#!/usr/bin/env python3
"""Manueller FTPS-Upload von Testupdate 1; standardmaessig nur lesen."""
from __future__ import annotations

import ftplib
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import ssl
import sys
import uuid

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    'api/index.php', 'system/Auth.php', 'system/Database.php',
    'system/Http.php', 'system/Push.php', 'system/bootstrap.php',
)
LIMIT = 1024 * 1024


class SafeError(RuntimeError):
    """Nur eigens formulierte, geheimnisfreie Fehlermeldungen."""


class TLSClient(ftplib.FTP_TLS):
    def ntransfercmd(self, cmd, rest=None):
        # Einige FTPS-Server verlangen TLS-Session-Reuse am Datenkanal.
        conn, size = ftplib.FTP.ntransfercmd(self, cmd, rest)
        try:
            conn = self.context.wrap_socket(
                conn, server_hostname=self.host, session=self.sock.session)
        except BaseException:
            conn.close()
            raise
        return conn, size


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def clean_dir(value: str) -> str:
    if not re.fullmatch(r'/[A-Za-z0-9_./-]*', value) or '..' in value.split('/'):
        raise SafeError('Ungueltiger Zielordner.')
    if '.' in value.split('/'):
        raise SafeError('Ungueltiger Zielordner.')
    return '/' + value.strip('/') if value.strip('/') else '/'


def path_at(root: str, name: str) -> str:
    return root.rstrip('/') + '/' + name


def load_update(root: Path = ROOT) -> dict:
    data = json.loads((root / 'deployment/testupdate-1.json').read_text())
    rows = data.get('files', [])
    expected = {'public/' + f for f in FILES}
    if data.get('release') != '2026-10-03-testversion-1' or len(rows) != len(FILES):
        raise SafeError('Unbekanntes Update-Manifest.')
    if {e.get('path') for e in rows} != expected:
        raise SafeError('Dateiliste stimmt nicht mit Testupdate 1 ueberein.')
    result = {}
    for row in rows:
        for field in ('before_sha256', 'after_sha256'):
            if not re.fullmatch(r'[0-9a-f]{64}', row.get(field, '')):
                raise SafeError('Ungueltige Pruefsumme im Manifest.')
        p = root / row['path']
        if p.is_symlink() or not p.is_file():
            raise SafeError('Update-Datei fehlt oder ist ein symbolischer Link.')
        content = p.read_bytes()
        if len(content) > LIMIT or digest(content) != row['after_sha256']:
            raise SafeError('Repository weicht von Testupdate 1 ab; neues Manifest erforderlich.')
        result[row['path'][7:]] = {**row, 'content': content}
    return result


def connect(env: dict | os._Environ = os.environ) -> TLSClient:
    host = env.get('EASYNAME_FTP_HOST', '')
    user = env.get('EASYNAME_FTP_USER', '')
    password = env.get('EASYNAME_FTP_PASSWORD', '')
    if not all((host, user, password)):
        raise SafeError('Die drei EASYNAME_FTP_* Repository-Secrets fehlen oder sind leer.')
    if not re.fullmatch(r'[A-Za-z0-9.-]+', host) or any(c in user + password for c in '\r\n\x00'):
        raise SafeError('Ungueltige Verbindungsdaten.')
    context = ssl.create_default_context()
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    ftp = TLSClient(context=context, timeout=25)
    try:
        ftp.connect(host, 21)
        ftp.auth()  # Vor der Passwortuebertragung Zertifikat und Hostnamen pruefen.
        ftp.login(user, password)
        ftp.prot_p()
        ftp.set_pasv(True)
        return ftp
    except BaseException:
        ftp.close()
        raise


def read_file(ftp, path: str) -> bytes:
    result = bytearray()
    def receive(block):
        if len(result) + len(block) > LIMIT:
            raise SafeError('Serverdatei unerwartet gross; Abbruch.')
        result.extend(block)
    ftp.retrbinary('RETR ' + path, receive)
    return bytes(result)


def find_root(ftp, requested: str, repo: Path = ROOT) -> str:
    # Keine Verzeichnislisten oder Konfigurationen abrufen.
    candidates = ['/', '/public', '/Public'] if requested == 'auto' else [clean_dir(requested)]
    matches = []
    for candidate in candidates:
        try:
            for marker in ('index.php', 'system/Crypto.php'):
                if digest(read_file(ftp, path_at(candidate, marker))) != digest((repo / 'public' / marker).read_bytes()):
                    break
            else:
                matches.append(candidate)
        except ftplib.error_perm as exc:
            if not str(exc).startswith('550'):
                raise
    if not matches:
        raise SafeError('Kein passender Takt-Webordner gefunden. Web-FTP-Ordneransicht pruefen.')
    if len(matches) != 1:
        raise SafeError('Mehrere Takt-Webordner gefunden. Zielordner ausdruecklich auswaehlen.')
    return matches[0]


def inspect(ftp, root: str, update: dict) -> dict:
    originals = {}
    for name, entry in update.items():
        blob = read_file(ftp, path_at(root, name))
        if digest(blob) not in {entry['before_sha256'], entry['after_sha256']}:
            raise SafeError('Abweichender Serverstand bei ' + name + '; keine Dateien ueberschrieben.')
        originals[name] = blob
    return originals


def stage(ftp, destination: str, content: bytes, temporary: set) -> str:
    name = str(PurePosixPath(destination).parent / ('.takt-' + uuid.uuid4().hex + '.php'))
    temporary.add(name)
    ftp.storbinary('STOR ' + name, io.BytesIO(content))
    if digest(read_file(ftp, name)) != digest(content):
        raise SafeError('Pruefsumme nach FTPS-Uebertragung stimmt nicht.')
    return name


def upload(ftp, root: str, update: dict, originals: dict) -> int:
    changed = [n for n in update if digest(originals[n]) != update[n]['after_sha256']]
    temporary, attempted, staged = set(), [], {}
    cleanup_ok = True
    try:
        for name in changed:
            staged[name] = stage(ftp, path_at(root, name), update[name]['content'], temporary)
        # Seit der Pruefung geaenderte Serverdateien niemals still ueberschreiben.
        for name in update:
            if digest(read_file(ftp, path_at(root, name))) != digest(originals[name]):
                raise SafeError('Serverdateien wurden zwischenzeitlich geaendert; Abbruch.')
        for name in changed:
            attempted.append(name)
            ftp.rename(staged[name], path_at(root, name))
            temporary.discard(staged[name])
        for name in update:
            if digest(read_file(ftp, path_at(root, name))) != update[name]['after_sha256']:
                raise SafeError('Endkontrolle der Serverdateien fehlgeschlagen.')
        return len(changed)
    except BaseException:
        restored = True
        for name in reversed(attempted):
            try:
                tmp = stage(ftp, path_at(root, name), originals[name], temporary)
                ftp.rename(tmp, path_at(root, name))
                temporary.discard(tmp)
                if digest(read_file(ftp, path_at(root, name))) != digest(originals[name]):
                    restored = False
            except BaseException:
                restored = False
        if not restored:
            raise SafeError('Upload fehlgeschlagen; Ruecksicherung nicht vollstaendig. Eigenes Backup sofort wiederherstellen.') from None
        if attempted:
            raise SafeError('Upload fehlgeschlagen; ausgetauschte Dateien wurden zurueckgesichert.') from None
        raise
    finally:
        # Ausschliesslich die in diesem Lauf selbst erstellten Temporaerdateien entfernen.
        for tmp in temporary:
            try:
                ftp.delete(tmp)
            except Exception:
                cleanup_ok = False
        if not cleanup_ok:
            print('WARNUNG: Eigene temporaere .takt-*.php-Dateien konnten nicht vollstaendig entfernt werden.')


def run(env: dict | os._Environ = os.environ) -> None:
    if env.get('GITHUB_ACTIONS') != 'true' or env.get('GITHUB_REF') != 'refs/heads/main':
        raise SafeError('Nur manuell ueber GitHub Actions im Branch main ausfuehren.')
    mode = env.get('EASYNAME_MODE', 'pruefen')
    requested = env.get('EASYNAME_DIR', 'auto')
    if mode not in ('pruefen', 'hochladen'):
        raise SafeError('Unbekannter Modus.')
    if mode == 'hochladen':
        if requested == 'auto' or env.get('EASYNAME_CONFIRM') != 'TESTUPDATE-1' or env.get('EASYNAME_BACKUP') != 'true':
            raise SafeError('Upload verlangt geprueften Zielordner, TESTUPDATE-1 und Bestaetigung: nur Testdaten, vollstaendiges Backup vorhanden.')
    update = load_update()
    ftp = connect(env)
    try:
        root = find_root(ftp, requested)
        originals = inspect(ftp, root, update)
        changes = sum(digest(originals[n]) != update[n]['after_sha256'] for n in update)
        lines = ['FTPS mit geprueftem TLS-Zertifikat: erfolgreich.',
                 'Erkannter FTP-Zielordner: ' + root,
                 f'Testupdate 1: {changes} von 6 Dateien zu aktualisieren.']
        if mode == 'hochladen':
            count = upload(ftp, root, update, originals)
            lines.append(f'{count} Dateien uebertragen; alle 6 SHA-256-Pruefsummen kontrolliert.')
            lines.append('Keine Datenbankmigration, keine Verkaufsfreigabe. Anwendung jetzt manuell abnehmen.')
        else:
            lines.append('Nur gelesen. Es wurden keine Serverdateien geaendert.')
        text = '\n'.join(lines)
        print(text)
        if env.get('GITHUB_STEP_SUMMARY'):
            with open(env['GITHUB_STEP_SUMMARY'], 'a', encoding='utf-8') as f:
                f.write('## Easyname-Pruefung\n\n' + text.replace('\n', '\n\n') + '\n')
    finally:
        ftp.close()


if __name__ == '__main__':
    try:
        run()
    except SafeError as exc:
        print('ABBRUCH: ' + str(exc), file=sys.stderr)
        sys.exit(1)
    except Exception as exc:
        # Keine ungefilterten Servermeldungen, Tracebacks oder Zugangsdaten protokollieren.
        print('ABBRUCH: FTPS/Dateipruefung fehlgeschlagen (' + type(exc).__name__ + '). Host, Port 21, TLS und Secrets pruefen.', file=sys.stderr)
        sys.exit(1)
