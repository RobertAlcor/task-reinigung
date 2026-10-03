<?php
/**
 * Einmaliges Einrichten im Browser.
 *
 * Aufrufen unter https://DEINE-DOMAIN/setup.php
 *
 * Sperrt sich selbst, sobald system/config.php existiert. Nach dem
 * Einrichten diese Datei vom Server löschen.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Vienna');

const SYSTEM_DIR  = __DIR__ . '/system';
const CONFIG_FILE = SYSTEM_DIR . '/config.php';

$schritt  = 'formular';
$fehler   = [];
$hinweise = [];
$zugang   = null;

// Bereits eingerichtet? Dann nichts mehr tun.
$bereitsFertig = is_file(CONFIG_FILE);

function post(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

if (!$bereitsFertig && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $dbName  = post('db_name');
    $dbUser  = post('db_user');
    $dbPass  = (string) ($_POST['db_pass'] ?? '');
    $dbHost  = post('db_host') !== '' ? post('db_host') : 'localhost';

    $anzeigename = post('anzeigename');
    $login   = post('login');
    $passwort = (string) ($_POST['passwort'] ?? '');
    $passwort2 = (string) ($_POST['passwort2'] ?? '');
    $mail    = post('mail');

    // --- Eingaben prüfen ---
    if ($dbName === '')  { $fehler['db_name'] = 'Datenbankname fehlt.'; }
    if ($dbUser === '')  { $fehler['db_user'] = 'Benutzername fehlt.'; }
    if ($dbPass === '')  { $fehler['db_pass'] = 'Passwort fehlt.'; }
    if ($login === '')   { $fehler['login'] = 'Benutzername fehlt.'; }
    elseif (preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $login) !== 1) {
        $fehler['login'] = 'Nur Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich, 3 bis 40 Zeichen.';
    }
    if (mb_strlen($passwort) < 10) { $fehler['passwort'] = 'Mindestens 10 Zeichen.'; }
    if ($passwort !== $passwort2)  { $fehler['passwort2'] = 'Die beiden Passwörter stimmen nicht überein.'; }
    if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
        $fehler['mail'] = 'Keine gültige E-Mail-Adresse.';
    }

    // --- Umgebung prüfen ---
    if (!is_dir(SYSTEM_DIR)) {
        $fehler['system'] = 'Der Ordner system/ wurde nicht gefunden. Er muss neben public/ liegen.';
    } elseif (!is_writable(SYSTEM_DIR)) {
        $fehler['system'] = 'Der Ordner system/ ist nicht beschreibbar. Rechte auf 755 setzen.';
    }
    if (!extension_loaded('pdo_mysql')) { $fehler['php'] = 'Die PHP-Erweiterung pdo_mysql fehlt.'; }
    if (!extension_loaded('openssl'))   { $fehler['php'] = 'Die PHP-Erweiterung openssl fehlt.'; }
    if (PHP_VERSION_ID < 80100)         { $fehler['php'] = 'PHP 8.1 oder neuer erforderlich, gefunden: ' . PHP_VERSION; }

    // --- Datenbank prüfen ---
    $pdo = null;
    if ($fehler === []) {
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            $fehler['db'] = 'Verbindung fehlgeschlagen. Bitte Zugangsdaten prüfen.';
        }
    }

    // --- Schema prüfen ---
    if ($fehler === [] && $pdo !== null) {
        $n = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        )->fetchColumn();
        if ($n < 40) {
            $fehler['db'] = "In der Datenbank stehen nur {$n} Tabellen. Bitte zuerst schema_v2.sql importieren.";
        }
        $hatAnbieter = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'anbieter_benutzer'"
        )->fetchColumn();
        if ($hatAnbieter === 0) {
            $fehler['db'] = 'Die Tabelle anbieter_benutzer fehlt. Bitte zuerst schema_anbieter.sql importieren.';
        } else {
            $vorhanden = (int) $pdo->query('SELECT COUNT(*) FROM anbieter_benutzer')->fetchColumn();
            if ($vorhanden > 0) {
                $fehler['db'] = 'Es ist bereits ein Anbieter-Zugang angelegt. Setup abgebrochen.';
            }
        }
    }

    // --- Anlegen ---
    if ($fehler === [] && $pdo !== null) {
        try {
            $schluessel = base64_encode(random_bytes(32));
            $domain = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

            $pdo->beginTransaction();

            // Anbieter-Zugang: steht ueber allen Betrieben
            $stmt = $pdo->prepare(
                'INSERT INTO anbieter_benutzer (benutzername, email, passwort_hash, name, rolle, aktiv) VALUES (?, ?, ?, ?, \'superuser\', 1)'
            );
            $stmt->execute([$login, $mail !== '' ? $mail : null, password_hash($passwort, PASSWORD_DEFAULT), $anzeigename !== '' ? $anzeigename : null]);

            $pdo->commit();

            // --- Konfiguration schreiben ---
            $inhalt = konfigurationErzeugen($dbHost, $dbName, $dbUser, $dbPass, $schluessel, $domain);
            if (file_put_contents(CONFIG_FILE, $inhalt) === false) {
                throw new RuntimeException('Die Datei system/config.php konnte nicht geschrieben werden.');
            }
            @chmod(CONFIG_FILE, 0640);

            $schritt = 'fertig';
            $zugang = ['login' => $login, 'schluessel' => $schluessel];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $fehler['db'] = 'Beim Anlegen ist ein Fehler aufgetreten: ' . $e->getMessage();
        }
    }
}

/** Erzeugt den Inhalt von system/config.php. */
function konfigurationErzeugen(string $host, string $name, string $user, string $pass, string $key, string $url): string
{
    $q = static fn(string $v): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";

    return <<<PHP
    <?php
    /**
     * Zentrale Konfiguration. Erzeugt vom Setup.
     *
     * Diese Datei enthält Zugangsdaten und den Verschlüsselungsschlüssel.
     * Nicht in ein öffentliches Repository legen, nicht weitergeben.
     */

    declare(strict_types=1);

    return [

        'db' => [
            'host'     => {$q($host)},
            'name'     => {$q($name)},
            'user'     => {$q($user)},
            'password' => {$q($pass)},
            'charset'  => 'utf8mb4',
        ],

        // Wird dieser Schlüssel geändert, sind SVNR, IBAN und Alarmcodes unlesbar.
        'crypto' => [
            'key' => {$q($key)},
        ],

        'app' => [
            'name'     => 'Reinigungsverwaltung',
            'url'      => {$q($url)},
            'timezone' => 'Europe/Vienna',
            'locale'   => 'de-AT',
            'debug'    => false,
        ],

        'security' => [
            'allowed_origins'  => [{$q($url)}],
            'session_lifetime' => 7200,
            'token_lifetime'   => 2592000,
            'max_login_tries'  => 5,
            'lockout_minutes'  => 15,
            'pin_length'       => 4,
        ],

        'files' => [
            'upload_dir'      => __DIR__ . '/../uploads',
            'max_upload_mb'   => 8,
            'photo_retention' => 3,
            'allowed_types'   => ['image/jpeg', 'image/png', 'image/webp'],
        ],

        // Mailversand: später ausfüllen, sobald ein Postfach eingerichtet ist.
        'mail' => [
            'host'       => 'smtp.easyname.com',
            'port'       => 587,
            'secure'     => 'tls',
            'user'       => '',
            'password'   => '',
            'from_email' => '',
            'from_name'  => 'Reinigungsverwaltung',
        ],
    ];
    PHP;
}

function alt(string $key): string
{
    return htmlspecialchars((string) ($_POST[$key] ?? ''), ENT_QUOTES);
}
?>
<!DOCTYPE html>
<html lang="de-AT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Einrichtung</title>
<style>
:root{--haus:#0B5D4A;--papier:#F6F7F4;--text:#13201B;--text2:#5C6B64;--linie:#DDE3DE;--rot:#B3261E;--feld:#E6EFE9}
*{box-sizing:border-box;margin:0;padding:0}
body{font:16px/1.6 system-ui,-apple-system,'Segoe UI',sans-serif;background:var(--papier);color:var(--text);padding:32px 20px}
.box{max-width:620px;margin:0 auto;background:#fff;border:1px solid var(--linie);border-radius:14px;padding:34px 34px 38px}
h1{font-size:27px;letter-spacing:-.02em;margin-bottom:6px}
.unter{color:var(--text2);margin-bottom:26px;font-size:15.5px}
h2{font-size:15px;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin:26px 0 14px;padding-top:22px;border-top:1px solid var(--linie)}
h2:first-of-type{border-top:0;padding-top:0;margin-top:0}
label{display:block;font-weight:600;font-size:14.5px;margin-bottom:5px}
input{width:100%;border:1.5px solid var(--linie);border-radius:7px;padding:11px 13px;font:inherit;font-size:15.5px}
input:focus{outline:none;border-color:var(--haus)}
.g{margin-bottom:15px}
.zwei{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.tipp{font-size:13.5px;color:var(--text2);margin-top:5px}
.fehler{color:var(--rot);font-size:13.5px;margin-top:5px;font-weight:500}
button{width:100%;background:var(--haus);color:#fff;border:0;border-radius:7px;padding:14px;font:inherit;font-size:16px;font-weight:600;cursor:pointer;margin-top:14px}
button:hover{background:#14876B}
.warn{background:#FDECEA;border:1px solid #F5C6C2;border-radius:9px;padding:14px 16px;margin-bottom:22px;font-size:14.5px;color:#7A1C16}
.gut{background:var(--feld);border:1px solid #BFD9CC;border-radius:9px;padding:16px 18px;margin-bottom:20px;font-size:15px}
.schluessel{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13.5px;background:var(--papier);border:1px solid var(--linie);border-radius:7px;padding:12px 14px;word-break:break-all;margin:10px 0 4px}
ol{margin:14px 0 0 20px}
li{margin-bottom:9px}
a{color:var(--haus)}
</style>
</head>
<body>
<div class="box">

<?php if ($bereitsFertig): ?>

  <h1>Bereits eingerichtet</h1>
  <p class="unter">Die Datei <code>system/config.php</code> ist vorhanden. Das Setup läuft aus Sicherheitsgründen kein zweites Mal.</p>
  <div class="warn"><strong>Bitte jetzt diese Datei löschen:</strong> <code>public/setup.php</code></div>
  <p>Soll neu eingerichtet werden, muss <code>system/config.php</code> zuvor gelöscht werden.</p>

<?php elseif ($schritt === 'fertig'): ?>

  <h1>Fertig</h1>
  <p class="unter">Anbieter-Zugang angelegt, Konfiguration geschrieben.</p>

  <div class="gut">
    <strong>Verschlüsselungsschlüssel — bitte sichern:</strong>
    <div class="schluessel"><?= htmlspecialchars($zugang['schluessel'], ENT_QUOTES) ?></div>
    Er steht in <code>system/config.php</code>. Geht er verloren, sind SVNR, IBAN und
    Alarmcodes dauerhaft unlesbar. Eine Kopie an einem sicheren Ort aufbewahren.
  </div>

  <div class="warn"><strong>Jetzt löschen:</strong> <code>public/setup.php</code> — solange die Datei liegt, ist sie über das Internet erreichbar.</div>

  <h2>Weiter</h2>
  <ol>
    <li>Anmelden unter <a href="/anbieter/">/anbieter/</a> mit Benutzername <strong><?= htmlspecialchars($zugang['login'], ENT_QUOTES) ?></strong></li>
    <li>Dort den ersten Reinigungsbetrieb anlegen — Leistungskatalog, Vorlagen und Nummernkreise werden dabei automatisch angelegt</li>
  </ol>

<?php else: ?>

  <h1>Einrichtung</h1>
  <p class="unter">Einmalig auszufüllen. Der Verschlüsselungsschlüssel wird dabei erzeugt.</p>

  <?php if (isset($fehler['db'])): ?><div class="warn"><?= htmlspecialchars($fehler['db'], ENT_QUOTES) ?></div><?php endif; ?>
  <?php if (isset($fehler['system'])): ?><div class="warn"><?= htmlspecialchars($fehler['system'], ENT_QUOTES) ?></div><?php endif; ?>
  <?php if (isset($fehler['php'])): ?><div class="warn"><?= htmlspecialchars($fehler['php'], ENT_QUOTES) ?></div><?php endif; ?>

  <form method="post" autocomplete="off">

    <h2>Datenbank</h2>
    <div class="g">
      <label for="db_name">Datenbankname</label>
      <input id="db_name" name="db_name" value="<?= alt('db_name') ?>" required>
      <?php if (isset($fehler['db_name'])): ?><div class="fehler"><?= $fehler['db_name'] ?></div><?php endif; ?>
    </div>
    <div class="zwei">
      <div class="g">
        <label for="db_user">Benutzername</label>
        <input id="db_user" name="db_user" value="<?= alt('db_user') ?>" required>
        <?php if (isset($fehler['db_user'])): ?><div class="fehler"><?= $fehler['db_user'] ?></div><?php endif; ?>
      </div>
      <div class="g">
        <label for="db_pass">Passwort</label>
        <input id="db_pass" name="db_pass" type="password" required>
        <?php if (isset($fehler['db_pass'])): ?><div class="fehler"><?= $fehler['db_pass'] ?></div><?php endif; ?>
      </div>
    </div>
    <div class="g">
      <label for="db_host">Server</label>
      <input id="db_host" name="db_host" value="<?= alt('db_host') !== '' ? alt('db_host') : 'localhost' ?>">
      <div class="tipp">Bei easyname bleibt hier <code>localhost</code> stehen.</div>
    </div>

    <h2>Anbieter-Zugang</h2>
    <p class="tipp" style="margin-bottom:14px">Das ist dein Zugang als Betreiber der Software. Reinigungsbetriebe legst du danach im Anbieter-Dashboard an.</p>
    <div class="g">
      <label for="anzeigename">Name</label>
      <input id="anzeigename" name="anzeigename" value="<?= alt('anzeigename') ?>">
    </div>
    <div class="g">
      <label for="login">Benutzername</label>
      <input id="login" name="login" value="<?= alt('login') ?>" required>
      <?php if (isset($fehler['login'])): ?><div class="fehler"><?= $fehler['login'] ?></div><?php endif; ?>
    </div>
    <div class="g">
      <label for="mail">E-Mail</label>
      <input id="mail" name="mail" type="email" value="<?= alt('mail') ?>">
      <?php if (isset($fehler['mail'])): ?><div class="fehler"><?= $fehler['mail'] ?></div><?php endif; ?>
    </div>
    <div class="zwei">
      <div class="g">
        <label for="passwort">Passwort</label>
        <input id="passwort" name="passwort" type="password" required>
        <div class="tipp">Mindestens 10 Zeichen.</div>
        <?php if (isset($fehler['passwort'])): ?><div class="fehler"><?= $fehler['passwort'] ?></div><?php endif; ?>
      </div>
      <div class="g">
        <label for="passwort2">Passwort wiederholen</label>
        <input id="passwort2" name="passwort2" type="password" required>
        <?php if (isset($fehler['passwort2'])): ?><div class="fehler"><?= $fehler['passwort2'] ?></div><?php endif; ?>
      </div>
    </div>

    <button type="submit">Einrichten</button>
  </form>

<?php endif; ?>

</div>
</body>
</html>
