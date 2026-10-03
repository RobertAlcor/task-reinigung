"""FTPS-Ablauf mit reinem In-Memory-Server; keinerlei Netzwerkzugriff."""
import ftplib
import importlib.util
from pathlib import Path
import ssl
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('takt_ftps', ROOT / 'tools/easyname_ftps.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class FakeFTP:
    def __init__(self, files):
        self.files = dict(files)
        self.writes = []
        self.rename_count = 0
        self.fail_rename = None
        self.corrupt_stage = False

    def retrbinary(self, cmd, callback):
        name = cmd.removeprefix('RETR ')
        if name not in self.files:
            raise ftplib.error_perm('550 Missing')
        callback(self.files[name])

    def storbinary(self, cmd, stream):
        name = cmd.removeprefix('STOR ')
        self.writes.append(('store', name))
        self.files[name] = b'bad transfer' if self.corrupt_stage else stream.read()

    def rename(self, src, dst):
        self.rename_count += 1
        if self.rename_count == self.fail_rename:
            raise ftplib.error_perm('550 Simulated rename failure')
        self.writes.append(('rename', dst))
        self.files[dst] = self.files.pop(src)

    def delete(self, name):
        self.writes.append(('delete', name))
        del self.files[name]


class FTPSChecks(unittest.TestCase):
    def setUp(self):
        self.root = '/public'
        self.update = {n: {'content': ('new ' + n).encode(),
                          'before_sha256': m.digest(('old ' + n).encode()),
                          'after_sha256': m.digest(('new ' + n).encode())}
                       for n in m.FILES}
        self.originals = {n: ('old ' + n).encode() for n in m.FILES}
        self.server = {m.path_at(self.root, n): b for n, b in self.originals.items()}
        self.server['/public/system/config.php'] = b'never read or change'
        self.ftp = FakeFTP(self.server)

    def test_manifest_matches_current_six_files(self):
        self.assertEqual(set(m.load_update()), set(m.FILES))

    def test_manifest_rejects_different_runtime_code(self):
        with tempfile.TemporaryDirectory() as directory:
            p = Path(directory)
            (p / 'deployment').mkdir()
            (p / 'deployment/testupdate-1.json').write_bytes((ROOT / 'deployment/testupdate-1.json').read_bytes())
            (p / 'public/api').mkdir(parents=True)
            (p / 'public/api/index.php').write_text('changed')
            with self.assertRaises(m.SafeError):
                m.load_update(p)

    def test_normalize_dirs(self):
        for src, dst in [('/', '/'), ('/public/', '/public'), ('/Public', '/Public')]:
            self.assertEqual(m.clean_dir(src), dst)

    def test_reject_unsafe_dirs(self):
        for value in ['public', '/a/../b', '/./public', '/a\nDELE x', '/a;rm', '/a\\b']:
            with self.subTest(value=value), self.assertRaises(m.SafeError):
                m.clean_dir(value)

    def test_check_reads_only(self):
        result = m.inspect(self.ftp, self.root, self.update)
        self.assertEqual(result, self.originals)
        self.assertEqual(self.ftp.writes, [])

    def test_check_rejects_unknown_server_code(self):
        self.ftp.files['/public/system/Auth.php'] = b'newer custom code'
        with self.assertRaises(m.SafeError):
            m.inspect(self.ftp, self.root, self.update)
        self.assertEqual(self.ftp.writes, [])

    def test_upload_only_six_preserves_config(self):
        self.assertEqual(m.upload(self.ftp, self.root, self.update, self.originals), 6)
        self.assertEqual(set(self.ftp.files), set(self.server))
        self.assertEqual(self.ftp.files['/public/system/config.php'], b'never read or change')
        for name in m.FILES:
            self.assertEqual(self.ftp.files[m.path_at(self.root, name)], self.update[name]['content'])

    def test_unchanged_upload_is_noop(self):
        originals = {n: e['content'] for n, e in self.update.items()}
        for n, blob in originals.items():
            self.ftp.files[m.path_at(self.root, n)] = blob
        self.assertEqual(m.upload(self.ftp, self.root, self.update, originals), 0)
        self.assertEqual(self.ftp.writes, [])

    def test_mid_upload_failure_restores_originals(self):
        self.ftp.fail_rename = 3
        with self.assertRaises(m.SafeError):
            m.upload(self.ftp, self.root, self.update, self.originals)
        self.assertEqual(self.ftp.files, self.server)

    def test_bad_stage_never_replaces_originals(self):
        self.ftp.corrupt_stage = True
        with self.assertRaises(m.SafeError):
            m.upload(self.ftp, self.root, self.update, self.originals)
        self.assertEqual(self.ftp.files, self.server)
        self.assertEqual(self.ftp.rename_count, 0)

    def test_concurrent_change_not_overwritten(self):
        self.ftp.files['/public/system/Auth.php'] = b'concurrent change'
        with self.assertRaises(m.SafeError):
            m.upload(self.ftp, self.root, self.update, self.originals)
        self.assertEqual(self.ftp.files['/public/system/Auth.php'], b'concurrent change')
        self.assertEqual(self.ftp.rename_count, 0)
        self.assertEqual(set(self.ftp.files), set(self.server))

    def test_read_size_limit(self):
        self.ftp.files['/huge'] = b'x' * (m.LIMIT + 1)
        with self.assertRaises(m.SafeError):
            m.read_file(self.ftp, '/huge')

    def test_root_autodetection(self):
        for n in ('index.php', 'system/Crypto.php'):
            self.ftp.files['/public/' + n] = (ROOT / 'public' / n).read_bytes()
        self.assertEqual(m.find_root(self.ftp, 'auto'), '/public')
        self.assertEqual(self.ftp.writes, [])

    def test_ambiguous_root_rejected(self):
        for root in ('/public', '/Public'):
            for n in ('index.php', 'system/Crypto.php'):
                self.ftp.files[root + '/' + n] = (ROOT / 'public' / n).read_bytes()
        with self.assertRaises(m.SafeError):
            m.find_root(self.ftp, 'auto')

    def test_unknown_root_rejected(self):
        with self.assertRaises(m.SafeError):
            m.find_root(self.ftp, 'auto')

    def test_upload_requires_all_confirmations(self):
        env = {'GITHUB_ACTIONS': 'true', 'GITHUB_REF': 'refs/heads/main', 'EASYNAME_MODE': 'hochladen'}
        for extra in [{}, {'EASYNAME_DIR': '/public'},
                      {'EASYNAME_DIR': '/public', 'EASYNAME_CONFIRM': 'TESTUPDATE-1'}]:
            with patch.object(m, 'connect') as connect, self.assertRaises(m.SafeError):
                m.run({**env, **extra})
            connect.assert_not_called()

    def test_non_main_rejected(self):
        with patch.object(m, 'connect') as connect, self.assertRaises(m.SafeError):
            m.run({'GITHUB_ACTIONS': 'true', 'GITHUB_REF': 'refs/heads/other'})
        connect.assert_not_called()

    def test_missing_credentials_rejected(self):
        with patch.object(m, 'TLSClient') as client, self.assertRaises(m.SafeError):
            m.connect({})
        client.assert_not_called()

    def test_protocol_injection_rejected(self):
        env = {'EASYNAME_FTP_HOST': 'ftp.example.test', 'EASYNAME_FTP_USER': 'test\r\nDELE x', 'EASYNAME_FTP_PASSWORD': 'only-a-fixture'}
        with patch.object(m, 'TLSClient') as client, self.assertRaises(m.SafeError):
            m.connect(env)
        client.assert_not_called()

    def test_connect_enforces_verified_tls_before_password(self):
        env = {'EASYNAME_FTP_HOST': 'ftp.example.test', 'EASYNAME_FTP_USER': 'test', 'EASYNAME_FTP_PASSWORD': 'only-a-fixture'}
        with patch.object(m, 'TLSClient') as client:
            m.connect(env)
            context = client.call_args.kwargs['context']
            self.assertTrue(context.check_hostname)
            self.assertEqual(context.verify_mode, ssl.CERT_REQUIRED)
            self.assertGreaterEqual(context.minimum_version, ssl.TLSVersion.TLSv1_2)
            methods = [call[0] for call in client.return_value.method_calls]
            self.assertEqual(methods, ['connect', 'auth', 'login', 'prot_p', 'set_pasv'])

    def test_tls_failure_never_sends_password(self):
        env = {'EASYNAME_FTP_HOST': 'ftp.example.test', 'EASYNAME_FTP_USER': 'test', 'EASYNAME_FTP_PASSWORD': 'only-a-fixture'}
        with patch.object(m, 'TLSClient') as client:
            client.return_value.auth.side_effect = ssl.SSLError('test')
            with self.assertRaises(ssl.SSLError):
                m.connect(env)
            client.return_value.login.assert_not_called()
            client.return_value.close.assert_called_once()


if __name__ == '__main__':
    unittest.main()
