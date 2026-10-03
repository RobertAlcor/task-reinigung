<?php
/**
 * Wiederkehrende Aufgaben. Aufruf:
 *   - per Cronjob-URL: https://DOMAIN/cron/?token=XYZ  (Token aus Anbieter-Einstellungen)
 *   - per CLI:          php cron/index.php
 *
 * Empfohlener Rhythmus: alle 15 Minuten. Jeder Lauf ist idempotent.
 */

declare(strict_types=1);

use App\Database;
use App\Mailer;
use App\Backup;
use App\Photo;
use App\Schedule;

$config = require __DIR__ . '/../system/bootstrap.php';
$db = Database::get();

// ---------------------------------------------------------------------
// Zugang pruefen
// ---------------------------------------------------------------------
$istCli = PHP_SAPI === 'cli';
if (!$istCli) {
    $erwartet = (string) ($db->rawOne("SELECT wert FROM anbieter_einstellungen WHERE schluessel = 'cron_token'")['wert'] ?? '');
    $token = (string) ($_GET['token'] ?? '');
    if ($erwartet === '' || $token === '' || !hash_equals($erwartet, $token)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Kein Zugang.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// Gleichzeitige Laeufe verhindern
$lock = fopen(__DIR__ . '/../system/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Läuft bereits.\n");
}

$start = microtime(true);
$log = [];
$sag = static function (string $s) use (&$log): void { $log[] = $s; echo $s, "\n"; };

// ---------------------------------------------------------------------
// 1. Einsaetze rollierend erzeugen — je aktivem Betrieb
// ---------------------------------------------------------------------
$betriebe = $db->rawAll("SELECT id, name FROM betriebe WHERE status IN ('test','aktiv')");
$gesamt = 0;
$heute = date('Y-m-d');
foreach ($betriebe as $b) {
    try {
        $db->setTenant((int) $b['id']);
        $n = Schedule::generate();
        $gesamt += $n;
        if ($n > 0) {
            $sag("Einsätze {$b['name']}: {$n} neu");
        }
        // Tagesbericht von gestern ans Buero — einmal pro Tag
        $merker = $db->selectOne('einstellungen', "schluessel = 'cron_tagesbericht'");
        if (($merker['wert'] ?? '') !== $heute) {
            $mail = $db->rawOne('SELECT email FROM betriebe WHERE id = ?', [$db->tenant()])['email'] ?? null;
            $text = tagesbericht($db, date('Y-m-d', strtotime('-1 day')));
            if ($mail && $text !== null) {
                $db->insert('benachrichtigungen', ['kanal' => 'mail', 'empfaenger_typ' => 'office', 'empfaenger_email' => $mail,
                    'betreff' => 'Tagesbericht ' . date('d.m.Y', strtotime('-1 day')), 'inhalt' => $text, 'referenz_typ' => 'tagesbericht']);
                $sag("Tagesbericht {$b['name']} eingereiht");
            }
            $db->raw("INSERT INTO einstellungen (betrieb_id, schluessel, wert, typ) VALUES (?, 'cron_tagesbericht', ?, 'text') ON DUPLICATE KEY UPDATE wert = VALUES(wert)", [$db->tenant(), $heute]);
        }
    } catch (\Throwable $e) {
        $sag("FEHLER Einsätze {$b['name']}: " . $e->getMessage());
        error_log('Cron Schedule ' . $b['id'] . ': ' . $e->getMessage());
    }
}
$sag("Einsätze gesamt: {$gesamt}");

// ---------------------------------------------------------------------
// 2. Benachrichtigungen versenden
// ---------------------------------------------------------------------
try {
    $r = Mailer::processQueue(50);
    $sag("Mails: {$r['gesendet']} gesendet, {$r['fehler']} Fehler" . (Mailer::configured() ? '' : ' (Versand nicht konfiguriert)'));
} catch (\Throwable $e) {
    $sag('FEHLER Mails: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// 3. Fotos nach Aufbewahrungsfrist loeschen (liegen unter system/fotos/)
// ---------------------------------------------------------------------
try {
    $gel = Photo::purgeExpired();
    $sag("Fotos gelöscht: {$gel}");
} catch (\Throwable $e) {
    $sag('FEHLER Fotolöschung: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// 2b. Web-Push fuer neue Nachrichten und offene Einsatzanfragen
// ---------------------------------------------------------------------
try { $pu = \App\Push::ausstehend(); if ($pu['personen'] > 0) { $sag("Push: {$pu['personen']} Personen, {$pu['gesendet']} erreicht"); } } catch (\Throwable $e) { $sag('FEHLER Push: ' . $e->getMessage()); }

// ---------------------------------------------------------------------
// 3b. Loeschfristen laut Datenschutzerklaerung, Testphasen sperren, Sicherung — einmal taeglich
// ---------------------------------------------------------------------
$tagMerker = $db->rawOne("SELECT wert FROM anbieter_einstellungen WHERE schluessel = 'cron_tagesaufgaben'");
if (($tagMerker['wert'] ?? '') !== date('Y-m-d')) {
    try {
        $a1 = $db->raw('DELETE FROM anfragen WHERE kunde_id IS NULL AND erstellt_am < DATE_SUB(NOW(), INTERVAL 6 MONTH)')->rowCount();
        $a2 = 0; try { $a2 = $db->raw("DELETE FROM anbieter_anfragen WHERE status IN ('neu','kontaktiert','abgelehnt') AND erstellt_am < DATE_SUB(NOW(), INTERVAL 12 MONTH)")->rowCount(); } catch (\Throwable) {}
        $a3 = $db->raw('DELETE FROM passwort_reset WHERE laeuft_ab < DATE_SUB(NOW(), INTERVAL 7 DAY)')->rowCount();
        $a4 = $db->raw('DELETE FROM anbieter_schnellzugang WHERE laeuft_ab < DATE_SUB(NOW(), INTERVAL 7 DAY)')->rowCount();
        try { $db->raw('DELETE FROM anbieter_login_qr WHERE laeuft_ab < DATE_SUB(NOW(), INTERVAL 7 DAY)'); $db->raw('DELETE FROM passwort_reset_anbieter WHERE laeuft_ab < DATE_SUB(NOW(), INTERVAL 7 DAY)'); } catch (\Throwable) {}
        $sag("Löschfristen: {$a1} Website-Anfragen, {$a2} Vertriebsanfragen, {$a3} Reset-Tokens, {$a4} Schnellzugänge");
        $gesperrt = $db->raw("UPDATE betriebe SET status = 'gesperrt' WHERE status = 'test' AND testphase_bis IS NOT NULL AND testphase_bis < CURDATE()")->rowCount();
        if ($gesperrt > 0) { $sag("Testphase abgelaufen, gesperrt: {$gesperrt}"); }
        // Automatische Abo-Rechnungen (Anbieter-Einstellung)
        $auto = $db->rawOne("SELECT wert FROM anbieter_einstellungen WHERE schluessel = 'abrechnung_automatisch'")['wert'] ?? '0';
        if ($auto === '1') {
            $sendenAuto = ($db->rawOne("SELECT wert FROM anbieter_einstellungen WHERE schluessel = 'abrechnung_senden'")['wert'] ?? '0') === '1';
            $faellige = $db->rawAll("SELECT v.betrieb_id, b.name FROM anbieter_vertraege v JOIN betriebe b ON b.id = v.betrieb_id WHERE b.status = 'aktiv' AND v.naechste_abrechnung <= CURDATE() AND v.preis_monat > 0");
            foreach ($faellige as $fa) {
                try {
                    $re = \App\Billing::rechnungErstellen((int) $fa['betrieb_id']);
                    $info = 'Abo-Rechnung ' . $re['nummer'] . ' für ' . $fa['name'] . ' (' . number_format((float) $re['brutto'], 2, ',', '.') . ' €)';
                    if ($sendenAuto) { try { \App\Invoice::send((int) $re['id'], $config['mail'] ?? []); $info .= ', gesendet'; } catch (\Throwable $me) { $info .= ', Versand fehlgeschlagen: ' . $me->getMessage(); } }
                    $sag($info);
                } catch (\Throwable $e) { $sag('FEHLER Abo-Rechnung ' . $fa['name'] . ': ' . $e->getMessage()); }
            }
        }
        $pfad = Backup::run(14);
        $sag('Sicherung: ' . basename($pfad) . ' (' . round(filesize($pfad) / 1024) . ' KB)');
        $db->raw("INSERT INTO anbieter_einstellungen (schluessel, wert) VALUES ('cron_tagesaufgaben', ?) ON DUPLICATE KEY UPDATE wert = VALUES(wert)", [date('Y-m-d')]);
    } catch (\Throwable $e) {
        $sag('FEHLER Tagesaufgaben: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// 4. Aufraeumen: Anmeldeprotokoll (12 Monate), abgelaufene Tokens, alte Nachrichten
// ---------------------------------------------------------------------
$a1 = $db->raw('DELETE FROM loginversuche WHERE zeitpunkt < DATE_SUB(NOW(), INTERVAL 12 MONTH)')->rowCount();
$a2 = $db->raw('DELETE FROM api_tokens WHERE laeuft_ab < DATE_SUB(NOW(), INTERVAL 30 DAY) OR (widerrufen = 1 AND erstellt_am < DATE_SUB(NOW(), INTERVAL 30 DAY))')->rowCount();
$a3 = $db->raw("DELETE FROM benachrichtigungen WHERE status = 'gesendet' AND gesendet_am < DATE_SUB(NOW(), INTERVAL 6 MONTH)")->rowCount();
$sag("Bereinigt: {$a1} Loginversuche, {$a2} Tokens, {$a3} Nachrichten");

// ---------------------------------------------------------------------
// 5. Testphasen: Erinnerung 7 Tage vor Ablauf (einmalig je Betrieb)
// ---------------------------------------------------------------------
$faellig = $db->rawAll(
    "SELECT b.id, b.name, b.email, b.testphase_bis FROM betriebe b
      WHERE b.status = 'test' AND b.testphase_bis = DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND b.email IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM anbieter_mails m WHERE m.betrieb_id = b.id AND m.betreff LIKE 'Testphase endet%')"
);
$anbieter = [];
foreach ($db->rawAll('SELECT schluessel, wert FROM anbieter_einstellungen') as $row) { $anbieter[$row['schluessel']] = $row['wert']; }
foreach ($faellig as $b) {
    $betreff = 'Testphase endet am ' . date('d.m.Y', strtotime((string) $b['testphase_bis']));
    $text = "Guten Tag,\n\nIhre Testphase endet am " . date('d.m.Y', strtotime((string) $b['testphase_bis'])) . ".\n"
          . "Danach läuft die Nutzung zum vereinbarten Preis weiter. Wenn Sie nicht weitermachen möchten, antworten Sie einfach auf diese E-Mail.\n\n"
          . "Mit freundlichen Grüßen\n" . ($anbieter['firma'] ?? '');
    try {
        Mailer::send((string) $b['email'], (string) $b['name'], $betreff, $text, $anbieter['firma'] ?? null, $anbieter['email'] ?? null);
        $db->raw("INSERT INTO anbieter_mails (betrieb_id, empfaenger, betreff, status) VALUES (?, ?, ?, 'gesendet')", [(int) $b['id'], $b['email'], $betreff]);
        $sag("Testphasen-Erinnerung: {$b['name']}");
    } catch (\Throwable $e) {
        $db->raw("INSERT INTO anbieter_mails (betrieb_id, empfaenger, betreff, status, fehler) VALUES (?, ?, ?, 'fehler', ?)", [(int) $b['id'], $b['email'], $betreff, mb_substr($e->getMessage(), 0, 255)]);
    }
}

$sag(sprintf('Fertig in %.1f s', microtime(true) - $start));
flock($lock, LOCK_UN);

// =====================================================================

/** Tagesbericht: erledigte, offene und ausgefallene Einsaetze eines Tages. Null, wenn nichts geplant war. */
function tagesbericht(Database $db, string $datum): ?string
{
    $rows = $db->rawAll(
        "SELECT e.uhrzeit_von, e.status, o.name AS objekt, e.notizen_mitarbeiter,
                (SELECT GROUP_CONCAT(CONCAT(m.vorname,' ',m.nachname) SEPARATOR ', ') FROM einsatz_mitarbeiter em JOIN mitarbeiter m ON m.id = em.mitarbeiter_id WHERE em.einsatz_id = e.id) AS team,
                (SELECT MIN(z.checkin_zeit) FROM zeiterfassung z WHERE z.einsatz_id = e.id) AS ein,
                (SELECT MAX(z.checkout_zeit) FROM zeiterfassung z WHERE z.einsatz_id = e.id) AS aus,
                (SELECT SUM(z.minuten_netto) FROM zeiterfassung z WHERE z.einsatz_id = e.id) AS minuten,
                (SELECT COUNT(*) FROM einsatz_fotos f WHERE f.einsatz_id = e.id AND f.geloescht = 0) AS fotos
           FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id
          WHERE e.betrieb_id = ? AND e.datum = ? AND e.status <> 'storniert' ORDER BY e.uhrzeit_von",
        [$db->tenant(), $datum]
    );
    if ($rows === []) {
        return null;
    }
    $erledigt = []; $offen = []; $ausgefallen = [];
    foreach ($rows as $r) {
        $zeile = substr((string) $r['uhrzeit_von'], 0, 5) . ' ' . $r['objekt'] . ' — ' . ($r['team'] ?: 'niemand zugeteilt');
        if ($r['status'] === 'erledigt') {
            $zeile .= ' · ' . ($r['ein'] ? substr((string) $r['ein'], 11, 5) : '?') . '–' . ($r['aus'] ? substr((string) $r['aus'], 11, 5) : '?')
                    . ($r['minuten'] !== null ? ' · ' . round($r['minuten'] / 60, 1) . ' Std' : '')
                    . ((int) $r['fotos'] > 0 ? ' · ' . (int) $r['fotos'] . ' Foto' . ((int) $r['fotos'] === 1 ? '' : 's') : '');
            if ($r['notizen_mitarbeiter']) { $zeile .= "\n    Notiz: " . $r['notizen_mitarbeiter']; }
            $erledigt[] = $zeile;
        } elseif ($r['status'] === 'ausgefallen') {
            $ausgefallen[] = $zeile;
        } else {
            $offen[] = $zeile . ($r['ein'] ? ' · eingestempelt ' . substr((string) $r['ein'], 11, 5) . ', nicht ausgestempelt' : ' · kein Check-in');
        }
    }
    $t = 'Tagesbericht ' . date('d.m.Y', strtotime($datum)) . "\n" . str_repeat('=', 40) . "\n\n";
    $t .= 'Erledigt (' . count($erledigt) . "):\n" . ($erledigt ? '  ' . implode("\n  ", $erledigt) : '  —') . "\n\n";
    if ($offen) { $t .= 'Nicht abgeschlossen (' . count($offen) . ") — bitte im Büro prüfen:\n  " . implode("\n  ", $offen) . "\n\n"; }
    if ($ausgefallen) { $t .= 'Ausgefallen (' . count($ausgefallen) . "):\n  " . implode("\n  ", $ausgefallen) . "\n\n"; }
    return $t . 'Automatisch erstellt.';
}
