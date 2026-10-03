<?php
/**
 * Startdatei. Wird von jedem Einstiegspunkt als Erstes eingebunden.
 *
 * Aufgaben: Konfiguration laden, Klassen bereitstellen, Datenbank,
 * Verschluesselung und Authentifizierung initialisieren, Fehler
 * einheitlich behandeln.
 */

declare(strict_types=1);

// --- Konfiguration ---------------------------------------------------

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Konfiguration fehlt. Bitte system/config.beispiel.php nach system/config.php kopieren und ausfüllen.');
}
$config = require $configFile;

// --- Zeit und Zeichensatz --------------------------------------------

date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Vienna');
mb_internal_encoding('UTF-8');
setlocale(LC_TIME, 'de_AT.UTF-8', 'de_AT', 'de');

// --- Fehleranzeige ---------------------------------------------------

$debug = (bool) ($config['app']['debug'] ?? false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// --- Klassen laden (einfacher Autoloader, kein Composer noetig) -------

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $short = substr($class, 4);
    // Auth und AuthException liegen gemeinsam in Auth.php,
    // Response, Router und Request gemeinsam in Http.php
    $map = [
        'AuthException' => 'Auth',
        'Response'      => 'Http',
        'Router'        => 'Http',
        'Request'       => 'Http',
    ];
    $file = __DIR__ . '/' . ($map[$short] ?? $short) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// --- Einheitliche Fehlerbehandlung -----------------------------------

set_exception_handler(static function (Throwable $e) use ($debug): void {
    error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    $isJson = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')
           || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

    if ($e instanceof App\AuthException) {
        $status  = $e->status();
        $message = $e->getMessage();
    } else {
        $status  = 500;
        // Interne Meldungen nie nach aussen geben
        $message = $debug ? $e->getMessage() : 'Es ist ein Fehler aufgetreten. Bitte später erneut versuchen.';
    }

    if ($isJson) {
        http_response_code($status);
        App\Response::json(['ok' => false, 'fehler' => $message], $status);
    }

    http_response_code($status);
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title>';
    echo '<p style="font:16px/1.6 system-ui;padding:40px;max-width:600px">' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
    exit;
});

// --- Dienste initialisieren ------------------------------------------

App\Response::securityHeaders();
App\Database::init($config['db']);
App\Crypto::init($config['crypto']['key']);
App\Auth::init($config['security']);
App\Mailer::init($config['mail'] ?? []);

// --- Hilfsfunktion: Ausgabe in HTML immer maskieren -------------------

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

return $config;
