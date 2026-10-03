"""Paketlayout und Startseiten-Einstieg; ausschließlich lokale Testdateien."""
import hashlib
import importlib.util
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request
import zipfile
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('ftp_package', ROOT / 'tools/config-recovery/build_ftp_upload.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class UploadChecks(unittest.TestCase):
    def setUp(self):
        self.work = tempfile.TemporaryDirectory(prefix='takt-upload-check-')
        self.root = Path(self.work.name)
        self.result = builder.build(self.root / 'package')
        self.public = self.root / 'site/public'
        self.public.mkdir(parents=True)
        with zipfile.ZipFile(self.result['archive']) as z:
            z.extractall(self.public.parent)
        (self.public / 'system').mkdir()
        (self.public / 'system/bootstrap.php').write_text("<?php http_response_code(200); echo 'NORMAL-BOOTSTRAP'; exit;")
        (self.public / 'system/.htaccess').write_text('Require all denied\n')
        router = self.root / 'router.php'
        router.write_text("<?php $_SERVER['HTTPS']='on'; return false;")
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            self.port = sock.getsockname()[1]
        self.log = (self.root / 'php.log').open('w+')
        self.proc = subprocess.Popen(['php', '-S', f'127.0.0.1:{self.port}', '-t', str(self.public), str(router)], stdout=self.log, stderr=self.log)
        for _ in range(60):
            try:
                with socket.create_connection(('127.0.0.1', self.port), timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError('Local PHP test server failed to start')
        self.client = urllib.request.build_opener(NoRedirect())

    def tearDown(self):
        self.proc.terminate()
        try:
            self.proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            self.proc.kill(); self.proc.wait()
        self.log.close()
        self.work.cleanup()

    def request(self, path='/', method='GET', host='reinigung.webdesign-alcor.at', data=None):
        req = urllib.request.Request(f'http://127.0.0.1:{self.port}' + path, headers={'Host': host}, method=method, data=data)
        try:
            response = self.client.open(req, timeout=5)
        except urllib.error.HTTPError as e:
            response = e
        with response:
            return response.status, response.headers, response.read()

    def test_archive_only_two_correct_server_paths(self):
        with zipfile.ZipFile(self.result['archive']) as z:
            self.assertEqual(set(z.namelist()), {'public/index.php', 'public/config-reparieren.php'})

    def test_archive_has_no_plain_unlock_code(self):
        with zipfile.ZipFile(self.result['archive']) as z:
            for name in z.namelist():
                self.assertNotIn(self.result['code'].encode(), z.read(name))

    def test_index_matches_repository(self):
        self.assertEqual((self.public / 'index.php').read_bytes(), (ROOT / 'public/index.php').read_bytes())

    def test_generated_hash_matches_private_code(self):
        content = (self.public / 'config-reparieren.php').read_text()
        self.assertIn(hashlib.sha256(self.result['code'].encode()).hexdigest(), content)
        self.assertNotIn('__ACCESS_HASH__', content)
        self.assertNotIn('__EXPIRES__', content)

    def test_expiry_below_authorization_limit(self):
        remaining = self.result['expires'] - time.time()
        self.assertGreater(remaining, 82000)
        self.assertLess(remaining, 86400)

    def test_repository_cannot_be_output_directory(self):
        with self.assertRaises(ValueError):
            builder.build(ROOT / 'output')

    def test_existing_package_not_silently_replaced(self):
        before = self.result['archive'].read_bytes()
        with self.assertRaises(FileExistsError):
            builder.build(self.root / 'package')
        self.assertEqual(before, self.result['archive'].read_bytes())

    def test_private_code_file_restricted(self):
        self.assertEqual(self.result['private'].stat().st_mode & 0o777, 0o600)

    def test_home_redirects_before_database_bootstrap(self):
        status, headers, body = self.request()
        self.assertEqual(status, 302)
        self.assertEqual(headers['Location'], 'https://reinigung.webdesign-alcor.at/config-reparieren.php')
        self.assertEqual(body, b'')

    def test_redirect_not_cached(self):
        _, headers, _ = self.request()
        self.assertEqual(headers['Cache-Control'], 'no-store')
        self.assertEqual(headers['Referrer-Policy'], 'no-referrer')

    def test_head_redirect(self):
        self.assertEqual(self.request(method='HEAD')[0], 302)

    def test_post_not_forwarded_to_recovery(self):
        status, headers, body = self.request(method='POST', data=b'name=test')
        self.assertEqual(status, 200)
        self.assertNotIn('Location', headers)
        self.assertEqual(body, b'NORMAL-BOOTSTRAP')

    def test_other_customer_domain_not_redirected(self):
        self.assertEqual(self.request(host='kunde.example')[2], b'NORMAL-BOOTSTRAP')

    def test_existing_config_keeps_normal_start(self):
        config = self.public / 'system/config.php'
        config.write_text('<?php return [];')
        self.assertEqual(self.request()[2], b'NORMAL-BOOTSTRAP')
        self.assertEqual(config.read_text(), '<?php return [];')

    def test_missing_repair_keeps_normal_bootstrap(self):
        (self.public / 'config-reparieren.php').unlink()
        self.assertEqual(self.request()[2], b'NORMAL-BOOTSTRAP')

    def test_dangling_config_symlink_not_repaired(self):
        (self.public / 'system/config.php').symlink_to(self.public / 'absent')
        self.assertEqual(self.request()[2], b'NORMAL-BOOTSTRAP')

    def test_recovery_symlink_not_used(self):
        endpoint = self.public / 'config-reparieren.php'
        external = self.root / 'other.php'
        endpoint.rename(external)
        endpoint.symlink_to(external)
        self.assertEqual(self.request()[2], b'NORMAL-BOOTSTRAP')

    def test_generated_endpoint_actually_serves_form(self):
        status, headers, body = self.request('/config-reparieren.php')
        self.assertEqual(status, 200)
        self.assertIn(b'name="db_pass"', body)
        self.assertIn(b'name="code"', body)
        self.assertNotIn(self.result['code'].encode(), body)

    def test_wrong_unlock_code_stops_before_database(self):
        status, _, body = self.request('/config-reparieren.php', method='POST', data=b'code=wrong&confirm=ja')
        self.assertEqual(status, 400)
        self.assertIn('Reparaturcode stimmt nicht'.encode(), body)
        self.assertFalse((self.public / 'system/config.php').exists())

    def test_correct_code_still_requires_db_credentials(self):
        body = ('code=' + self.result['code'] + '&confirm=ja').encode()
        status, _, content = self.request('/config-reparieren.php', method='POST', data=body)
        self.assertEqual(status, 400)
        self.assertIn('Datenbankpasswort aus Easyname'.encode(), content)
        self.assertFalse((self.public / 'system/config.php').exists())

    def test_existing_config_locks_repair(self):
        target = self.public / 'system/config.php'
        target.write_text('<?php return [];')
        status, _, body = self.request('/config-reparieren.php')
        self.assertEqual(status, 400)
        self.assertIn('config.php ist vorhanden'.encode(), body)
        self.assertNotIn(b'<form', body)
        self.assertEqual(target.read_text(), '<?php return [];')


if __name__ == '__main__':
    unittest.main()
