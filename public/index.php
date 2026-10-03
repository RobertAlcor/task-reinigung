<?php
/**
 * Front-Controller der Hauptdomain und aller Kundendomains.
 *
 * 1. Kundendomain (website_config.domain passt zum Host) → White-Label-Website
 * 2. ?betrieb=<slug> → Vorschau der Website (nur angemeldet: Büro des Betriebs oder Anbieter)
 * 3. sonst → Vertriebsseite des Anbieters (vertrieb/index.php, wenn vorhanden)
 *
 * Unterseiten kommen ueber .htaccess als ?p=leistungen|ueber-uns|kontakt|impressum|datenschutz
 */

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\Website;

// Nur ohne Konfiguration: das mitgelieferte Reparaturformular öffnen.
if (!file_exists(__DIR__ . '/system/config.php')
    && !is_link(__DIR__ . '/system/config.php')
    && is_file(__DIR__ . '/config-reparieren.php')
    && !is_link(__DIR__ . '/config-reparieren.php')
    && strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) === 'reinigung.webdesign-alcor.at'
    && in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)
) {
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Location: https://reinigung.webdesign-alcor.at/config-reparieren.php', true, 302);
    exit;
}

$config = require __DIR__ . '/system/bootstrap.php';
\App\Response::securityHeaders();

$seite = preg_replace('/[^a-z-]/', '', (string) ($_GET['p'] ?? 'start')) ?: 'start';
if (!in_array($seite, ['start', 'leistungen', 'ueber-uns', 'kontakt', 'impressum', 'datenschutz', 'registrieren'], true)) { $seite = 'start'; }

$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$web = Website::fuerHost($host);
$vorschau = false;

if ($web === null && ($slug = preg_replace('/[^a-z0-9-]/', '', (string) ($_GET['betrieb'] ?? ''))) !== '') {
    // Vorschau nur fuer Berechtigte
    $erlaubt = false;
    $u = Auth::authenticate();
    if ($u !== null && in_array($u['rolle'], ['inhaber', 'office'], true)) {
        $b = Database::get()->rawOne('SELECT slug FROM betriebe WHERE id = ?', [(int) $u['betrieb_id']]);
        $erlaubt = $b !== null && $b['slug'] === $slug;
    }
    if (!$erlaubt && Auth::provider() !== null) { $erlaubt = true; }
    if ($erlaubt) { $web = Website::fuerSlug($slug); $vorschau = true; }
}

if ($web !== null) {
    $meldung = null;
    $basis = $vorschau ? '/index.php?betrieb=' . rawurlencode($_GET['betrieb']) . '&p=' : '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['name'])) {
        try { $meldung = ['typ' => 'ok', 'text' => $web->anfrage($_POST, Auth::clientIp())]; }
        catch (\Throwable $e) { $meldung = ['typ' => 'fehler', 'text' => $e->getMessage()]; }
    }
    header('Cache-Control: ' . ($vorschau ? 'no-store' : 'public, max-age=600'));
    // In der Vorschau werden Links ueber ?betrieb=…&p=… gefuehrt
    $html = $web->render($seite, $meldung, $vorschau ? '' : '', $vorschau);
    if ($vorschau) {
        $html = preg_replace_callback('#href="/(leistungen|ueber-uns|kontakt|impressum|datenschutz)(\#[a-z]*)?"#', static fn($m) => 'href="/index.php?betrieb=' . rawurlencode($_GET['betrieb']) . '&amp;p=' . $m[1] . ($m[2] ?? '') . '"', $html);
        $html = str_replace('href="/"', 'href="/index.php?betrieb=' . rawurlencode($_GET['betrieb']) . '"', $html);
        $html = str_replace('action="/kontakt#formular"', 'action="/index.php?betrieb=' . rawurlencode($_GET['betrieb']) . '&amp;p=kontakt#formular"', $html);
    }
    echo $html;
    exit;
}

// Vertriebsseite des Anbieters
if (is_file(__DIR__ . '/vertrieb/index.php')) {
    require __DIR__ . '/vertrieb/index.php';
    exit;
}
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Reinigungsverwaltung</title></head><body style="font:16px/1.6 system-ui;padding:40px;max-width:600px"><h1>Reinigungsverwaltung</h1><p><a href="/admin/">Büro</a> · <a href="/app/">Mitarbeiter-App</a> · <a href="/portal/">Kundenportal</a> · <a href="/anbieter/">Anbieter</a></p></body></html>';
