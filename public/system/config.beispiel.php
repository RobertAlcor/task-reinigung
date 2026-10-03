<?php
/**
 * Vorlage für die Konfiguration.
 *
 * Diese Datei nach config.php kopieren und ausfüllen. Die echte config.php
 * gehört NICHT ins Repository — sie enthält Zugangsdaten und den
 * Verschlüsselungsschlüssel.
 *
 * Bei einer Neuinstallation erzeugt setup.php diese Datei automatisch.
 */

declare(strict_types=1);

return [

    'db' => [
        'host'     => 'localhost',
        'name'     => 'DATENBANKNAME',
        'user'     => 'DATENBANKBENUTZER',
        'password' => 'DATENBANKPASSWORT',
        'charset'  => 'utf8mb4',
    ],

    // Wird dieser Schlüssel geändert, sind SVNR, IBAN und Alarmcodes unlesbar.
    // Erzeugen mit: php -r "echo base64_encode(random_bytes(32));"
    'crypto' => [
        'key' => 'HIER_32_BYTE_SCHLUESSEL_BASE64',
    ],

    'app' => [
        'name'     => 'Takt',
        'url'      => 'https://ihre-domain.at',
        'timezone' => 'Europe/Vienna',
        'locale'   => 'de-AT',
        'debug'    => false,
    ],

    'security' => [
        'allowed_origins'  => ['https://ihre-domain.at'],
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

    // Mailversand wird im Anbieter-Dashboard gepflegt
    // (Anbieter → Einstellungen → Mailversand). Diese Werte sind nur Fallback.
    'mail' => [
        'host'       => '',
        'port'       => 587,
        'secure'     => 'tls',
        'user'       => '',
        'password'   => '',
        'from_email' => '',
        'from_name'  => 'Takt',
    ],
];
