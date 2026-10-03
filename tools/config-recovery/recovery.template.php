<?php
declare(strict_types=1);
namespace TaktConfigRecovery;

// Vorlage bleibt gesperrt. START-HIER.html erzeugt eine befristete Einmalkopie.
const ACCESS_HASH = '__ACCESS_HASH__';
const EXPIRES = '__EXPIRES__';
const SITE = 'https://reinigung.webdesign-alcor.at';
const FIELDS = [
    'mitarbeiter_sensibel' => ['svnr_enc', 'iban_enc'],
    'objekt_zugang' => ['schluessel_info_enc', 'alarm_code_enc'],
    'benutzer' => ['totp_secret_enc'],
    'anbieter_benutzer' => ['totp_secret_enc'],
];

final class SafeError extends \RuntimeException {}

function value(array $input, string $name, int $max): string
{
    $v = $input[$name] ?? '';
    if (!is_string($v) || strlen($v) > $max || str_contains($v, "\0")) {
        throw new SafeError('Ein Formularfeld hat ein ungültiges Format.');
    }
    return $v;
}

function credentials(array $input): array
{
    $name = trim(value($input, 'db_name', 64));
    $user = trim(value($input, 'db_user', 80));
    $pass = value($input, 'db_pass', 512);
    if (preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $name) !== 1 || $user === '' || $pass === '') {
        throw new SafeError('Datenbankname, Datenbankbenutzer und Datenbankpasswort aus Easyname eintragen. Nicht den FTP-Zugang verwenden.');
    }
    return ['host' => 'localhost', 'name' => $name, 'user' => $user, 'password' => $pass, 'charset' => 'utf8mb4'];
}

function assertAuthorized(array $server, array $input, string $hash, int $expires): void
{
    if (($server['HTTPS'] ?? '') === '' || ($server['HTTPS'] ?? '') === 'off') {
        throw new SafeError('Diese Reparatur ist ausschließlich über HTTPS erlaubt.');
    }
    if (strtolower((string) ($server['HTTP_HOST'] ?? '')) !== 'reinigung.webdesign-alcor.at') {
        throw new SafeError('Die Reparatur ist nur für die vorgesehene Takt-Subdomain freigegeben.');
    }
    if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || time() > $expires || $expires > time() + 86400) {
        throw new SafeError('Die Reparaturdatei ist nicht aktiviert oder abgelaufen. In START-HIER.html eine neue Kopie erzeugen.');
    }
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new SafeError('Bitte das Formular absenden.');
    }
    if (isset($server['HTTP_ORIGIN']) && $server['HTTP_ORIGIN'] !== SITE) {
        throw new SafeError('Die Anfrage kommt nicht von der vorgesehenen Seite.');
    }
    $code = trim(value($input, 'code', 128));
    if (!hash_equals($hash, hash('sha256', $code))) {
        throw new SafeError('Der Reparaturcode stimmt nicht. Den Code aus START-HIER.html übernehmen.');
    }
}

function chooseKey(iterable $blobs, string $provided, bool $allowNew): string
{
    $provided = trim($provided);
    $key = $provided === '' ? null : base64_decode($provided, true);
    if ($provided !== '' && ($key === false || strlen($key) !== 32)) {
        throw new SafeError('Der eingegebene Schlüssel hat nicht das erwartete Format (32 Byte in Base64).');
    }
    $count = 0;
    foreach ($blobs as $blob) {
        if (++$count > 10000) { throw new SafeError('Die Datenmenge überschreitet die Grenze dieser Reparatur. Keine Datei erstellt.'); }
        if ($key === null) {
            throw new SafeError('Es sind bereits verschlüsselte Daten vorhanden. Ohne den bisherigen Schlüssel wird keine neue config.php erstellt. Die Datenbank bleibt unverändert; ein neuer Schlüssel kann diese Inhalte nicht entschlüsseln.');
        }
        if (!is_string($blob) || strlen($blob) <= 28 || strlen($blob) > 16384) {
            throw new SafeError('Ein verschlüsseltes Feld ist unvollständig oder unbekannt. Keine Datei erstellt.');
        }
        $plain = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16));
        if ($plain === false) {
            throw new SafeError('Der Schlüssel passt nicht zu allen vorhandenen verschlüsselten Daten. Keine Datei erstellt.');
        }
        unset($plain);
    }
    if ($key !== null) { return base64_encode($key); }
    if (!$allowNew) {
        throw new SafeError('Keine belegten Verschlüsselungsfelder gefunden. Für diesen Fall die Checkbox zur Erzeugung eines neuen Schlüssels aktivieren.');
    }
    return base64_encode(random_bytes(32));
}

function inspectDatabase(\PDO $pdo, string $provided, bool $allowNew): string
{
    // Unbekannte verschlüsselte Spalten nicht übergehen.
    $columns = $pdo->query('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(\PDO::FETCH_ASSOC);
    $seen = [];
    foreach ($columns as $c) {
        $table = (string) $c['TABLE_NAME']; $col = (string) $c['COLUMN_NAME'];
        $seen[$table][$col] = true;
        $known = in_array($col, FIELDS[$table] ?? [], true);
        if (!$known && (str_ends_with($col, '_enc') || in_array($c['DATA_TYPE'], ['varbinary','tinyblob','blob','mediumblob','longblob'], true))) {
            throw new SafeError('Unbekannte Binär-/Verschlüsselungsfelder im Schema. Diese Datenbank benötigt eine separate Prüfung.');
        }
    }
    foreach (FIELDS as $table => $cols) {
        foreach ($cols as $col) {
            if (!isset($seen[$table][$col])) { throw new SafeError('Das Takt-Schema ist nicht vollständig oder passt nicht zu dieser Version. Keine SQL-Dateien auf Verdacht importieren.'); }
        }
    }
    foreach (['benutzer', 'anbieter_benutzer'] as $table) {
        if (!isset($seen[$table]['totp_aktiv'])) { throw new SafeError('Die Zwei-Faktor-Struktur fehlt. Keine Konfiguration erstellt.'); }
        $bad = $pdo->query("SELECT COUNT(*) FROM `$table` WHERE totp_aktiv = 1 AND (totp_secret_enc IS NULL OR OCTET_LENGTH(totp_secret_enc) = 0)")->fetchColumn();
        if ((int) $bad > 0) { throw new SafeError('Es gibt ein Zwei-Faktor-Konto ohne Schlüsselmaterial. Konten werden nicht verändert; gesonderte Wiederherstellung erforderlich.'); }
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM anbieter_benutzer WHERE aktiv = 1')->fetchColumn() < 1) {
        throw new SafeError('Kein aktiver Anbieter-Zugang in dieser Datenbank gefunden. Diese Reparatur stellt keine gelöschten Konten wieder her und richtet keine neue Installation ein.');
    }
    $blobs = (static function () use ($pdo): \Generator {
        foreach (FIELDS as $table => $cols) {
            foreach ($cols as $col) {
                $stmt = $pdo->query("SELECT `$col` FROM `$table` WHERE `$col` IS NOT NULL AND OCTET_LENGTH(`$col`) > 0 LIMIT 10001");
                while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) { yield $row[0]; }
                $stmt->closeCursor();
            }
        }
    })();
    return chooseKey($blobs, $provided, $allowNew);
}

function configSource(array $db, string $key): string
{
    $config = [
        'db' => $db, 'crypto' => ['key' => $key],
        'app' => ['name'=>'Takt','url'=>SITE,'timezone'=>'Europe/Vienna','locale'=>'de-AT','debug'=>false],
        'security' => ['allowed_origins'=>[SITE],'session_lifetime'=>7200,'token_lifetime'=>2592000,'max_login_tries'=>5,'lockout_minutes'=>15,'pin_length'=>4],
        'files' => ['max_upload_mb'=>8,'photo_retention'=>3,'allowed_types'=>['image/jpeg','image/png','image/webp']],
        'mail' => ['host'=>'','port'=>587,'secure'=>'tls','user'=>'','password'=>'','from_email'=>'','from_name'=>'Takt'],
    ];
    return "<?php\ndeclare(strict_types=1);\n// Privat: niemals teilen oder in GitHub speichern.\n\$config = " . var_export($config, true)
        . ";\n\$config['files']['upload_dir'] = __DIR__ . '/../uploads';\nreturn \$config;\n";
}

function writeConfig(string $directory, string $source): void
{
    $target = $directory . '/config.php';
    if (file_exists($target) || is_link($target)) { throw new SafeError('Eine config.php existiert inzwischen. Sie wird nicht überschrieben.'); }
    if (!is_dir($directory) || !is_writable($directory) || !is_file($directory . '/bootstrap.php')) {
        throw new SafeError('Der system-Ordner fehlt oder ist nicht beschreibbar. Datei neben public/index.php hochladen und Rechte beim Hoster prüfen.');
    }
    $ht = @file_get_contents($directory . '/.htaccess');
    if (!is_string($ht) || preg_match('/^\s*Require\s+all\s+denied\s*$/mi', $ht) !== 1) {
        throw new SafeError('Die Zugriffssperre system/.htaccess fehlt. Keine Konfiguration erstellt.');
    }
    $temp = $directory . '/.config-recovery-' . bin2hex(random_bytes(16)) . '.php';
    $oldMask = umask(0077); $handle = null;
    try {
        $handle = @fopen($temp, 'x+b');
        if ($handle === false) { throw new SafeError('Temporäre Konfiguration konnte nicht erstellt werden.'); }
        $offset = 0;
        while ($offset < strlen($source)) {
            $n = fwrite($handle, substr($source, $offset));
            if ($n === false || $n === 0) { throw new SafeError('Konfiguration konnte nicht vollständig geschrieben werden.'); }
            $offset += $n;
        }
        if (!fflush($handle) || !chmod($temp, 0640)) { throw new SafeError('Konfiguration konnte nicht sicher gespeichert werden.'); }
        fclose($handle); $handle = null;
        // Hardlink: vollständig geschrieben bereitstellen, nie bestehende Datei ersetzen.
        if (!@link($temp, $target)) { throw new SafeError('Konfiguration nicht installiert: Datei existiert bereits oder der Hoster erlaubt diese sichere Dateierstellung nicht.'); }
    } finally {
        if (is_resource($handle)) { fclose($handle); }
        if (is_file($temp)) { @unlink($temp); }
        umask($oldMask);
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function web(): void
{
    ini_set('display_errors','0'); ini_set('log_errors','0');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    $error = ''; $done = false;
    $system = __DIR__ . '/system';
    try {
        if (preg_match('/^[a-f0-9]{64}$/D', ACCESS_HASH) !== 1 || time() > (int) EXPIRES) { throw new SafeError('Reparaturdatei nicht aktiviert oder abgelaufen. Zuerst START-HIER.html auf dem Computer öffnen.'); }
        if (file_exists($system . '/config.php') || is_link($system . '/config.php')) { throw new SafeError('config.php ist vorhanden. Die Reparatur bleibt gesperrt. Bitte diese Reparaturdatei vom Server löschen.'); }
        if (($_SERVER['HTTPS'] ?? '') === '' || ($_SERVER['HTTPS'] ?? '') === 'off' || strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) !== 'reinigung.webdesign-alcor.at') { throw new SafeError('Nur über die vorgesehene HTTPS-Subdomain öffnen.'); }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16000) { throw new SafeError('Anfrage zu groß.'); }
            assertAuthorized($_SERVER, $_POST, ACCESS_HASH, (int) EXPIRES);
            $db = credentials($_POST);
            if (value($_POST, 'confirm', 10) !== 'ja') { throw new SafeError('Bitte die Erstellung der fehlenden Datei ausdrücklich bestätigen.'); }
            if (!extension_loaded('pdo_mysql') || !extension_loaded('openssl')) { throw new SafeError('Die PHP-Erweiterung pdo_mysql oder OpenSSL fehlt.'); }
            $pdo = new \PDO('mysql:host=localhost;dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['password'], [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false,\PDO::ATTR_TIMEOUT=>10]);
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            try {
                $key = inspectDatabase($pdo, trim(value($_POST, 'old_key', 256)), value($_POST, 'allow_new', 10) === 'ja');
                $pdo->rollBack();
                writeConfig($system, configSource($db, $key));
                unset($key, $db); $done = true;
            } finally {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
            }
        }
    } catch (SafeError $e) { $error = $e->getMessage(); http_response_code(400); }
      catch (\PDOException $e) { $error = 'Datenbankverbindung oder lesende Prüfung fehlgeschlagen. Datenbankname, Datenbankbenutzer und Datenbankpasswort im Easyname-Bereich Datenbanken prüfen. Keine Datenbankänderung vorgenommen.'; http_response_code(400); }
      catch (\Throwable $e) { $error = 'Die Reparatur konnte nicht abgeschlossen werden. Keine Konten oder Datenbankeinträge wurden geändert. Bitte Fehlermeldung ohne Zugangsdaten weitergeben.'; http_response_code(500); }
    $active = preg_match('/^[a-f0-9]{64}$/D', ACCESS_HASH) === 1 && time() <= (int) EXPIRES && !file_exists($system . '/config.php') && !is_link($system . '/config.php') && ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' && strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) === 'reinigung.webdesign-alcor.at';
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Takt · Konfiguration reparieren</title><style>body{font:17px/1.55 system-ui;max-width:740px;margin:40px auto;padding:0 20px;color:#172238}h1{line-height:1.2}label{display:block;margin-top:20px;font-weight:600}input:not([type=checkbox]){box-sizing:border-box;display:block;width:100%;padding:12px;font:inherit;border:1px solid #8b94a4;border-radius:5px}button{padding:14px 20px;font:inherit;background:#164e75;color:white;border:0;border-radius:5px;margin:24px 0}aside{padding:18px;background:#eef3f8;border-left:4px solid #164e75}.error{background:#fff0eb;padding:18px}small{display:block;font-size:14px}code{overflow-wrap:anywhere}</style><h1>Fehlende config.php erstellen</h1>';
    if ($error !== '') { echo '<p class="error" role="alert">' . h($error) . '</p>'; }
    if ($done) {
        echo '<aside><strong>config.php wurde erstellt.</strong><p>Die Datenbank wurde ausschließlich gelesen. Bestehende Konten, Passwörter und Zwei-Faktor-Einstellungen wurden nicht geändert.</p></aside><p>Jetzt <strong>config-reparieren.php vom Server löschen</strong>, die neue <code>system/config.php</code> privat sichern und Takt neu aufrufen. Ein vorhandenes setup.php ebenfalls nicht weiter öffentlich bereitstellen.</p><p>Mail-Einstellungen aus der Datenbank bleiben erhalten. Frühere Einstellungen, die ausschließlich in der verlorenen Datei standen, müssen separat ergänzt werden.</p><p><a href="/">Takt öffnen</a></p>';
    } elseif ($active) {
        echo '<aside>Dies ist keine Neuinstallation. Es werden keine Tabellen, Konten oder Daten gelöscht. Ein neuer Schlüssel wird nur nach vollständiger Prüfung leerer bekannter Verschlüsselungsfelder erzeugt.</aside><form method="post" action="" autocomplete="off"><label>Reparaturcode<input type="password" name="code" required maxlength="128" autocomplete="off"></label><small>Der Einmalcode aus START-HIER.html, nicht dein FTP-Passwort.</small><label>Datenbankname<input name="db_name" required maxlength="64" value="u230952db1"></label><label>Datenbankbenutzer<input name="db_user" required maxlength="80" value="u230952db1"></label><label>Datenbankpasswort<input type="password" name="db_pass" required maxlength="512" autocomplete="new-password"></label><small>Angaben unter Easyname → Datenbanken prüfen. Host ist localhost. FTP-Zugangsdaten sind hier falsch.</small><label>Bisheriger Verschlüsselungsschlüssel – optional<input type="password" name="old_key" maxlength="256" autocomplete="new-password"></label><small>Unbekannt? Leer lassen. Die Prüfung entscheidet, ob ein neuer Schlüssel ohne Verlust möglich ist.</small><label><input type="checkbox" name="allow_new" value="ja"> Neuen Schlüssel nur erlauben, wenn sämtliche geprüften Verschlüsselungsfelder leer sind.</label><label><input type="checkbox" name="confirm" value="ja" required> Die fehlende config.php für diese bestehende Installation erstellen. Keine parallelen Datei-/Datenbankänderungen während der Prüfung.</label><button type="submit">Prüfen und config.php erstellen</button></form><p>Das Passwort und ein eingegebener Schlüssel werden nicht im Ergebnis angezeigt. Bei einem Abbruch bleiben die Felder aus Sicherheitsgründen leer.</p>';
    }
    echo '</html>';
}

if (PHP_SAPI !== 'cli') { web(); }
