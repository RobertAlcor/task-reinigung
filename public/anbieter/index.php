<?php
/**
 * Anbieter-Dashboard — Uebersicht und Verwaltung aller Betriebe.
 *
 * Nur fuer den Softwarebetreiber. Server-gerendert, ein Formular je
 * Aktion, CSRF-Schutz. Kein Mandant wird gesetzt: Betriebsdaten
 * werden hier ausschliesslich ueber die Sicht v_anbieter_betriebe und
 * gezielte Anbieter-Funktionen gelesen, nie ueber die Mandanten-API.
 */

declare(strict_types=1);

use App\Auth;
use App\AuthException;
use App\Database;
use App\Tenant;
use App\Billing;
use App\Invoice;

$config = require __DIR__ . '/../system/bootstrap.php';
\App\Response::securityHeaders();

$db      = Database::get();
$meldung = null;   // ['typ' => 'ok'|'fehler', 'text' => ..., 'zugang' => [...]]
$aktion  = $_POST['aktion'] ?? null;
$ansicht = $_GET['s'] ?? 'uebersicht';
$zfaSetup = null; $zfaBackup = null; $klartextQr = null; $klartextZugang = null;

// ---------------------------------------------------------------------
// Abmelden
// ---------------------------------------------------------------------
if ($aktion === 'logout') {
    Auth::logout();
    header('Location: /anbieter/');
    exit;
}

// ---------------------------------------------------------------------
// Anmelden
// ---------------------------------------------------------------------
$anbieter = Auth::provider();

// Persoenlicher QR-Login (Konto → QR erzeugen): ersetzt Benutzername/Passwort, Zwei-Faktor bleibt.
if ($anbieter === null && isset($_GET['zugang']) && preg_match('/^[a-f0-9]{40}$/', (string) $_GET['zugang']) === 1) {
    $q = $db->rawOne('SELECT q.*, b.* , q.id AS qr_id FROM anbieter_login_qr q JOIN anbieter_benutzer b ON b.id = q.anbieter_benutzer_id WHERE q.token_hash = ? AND q.laeuft_ab > NOW() AND b.aktiv = 1', [hash('sha256', (string) $_GET['zugang'])]);
    if ($q !== null) {
        $db->raw('UPDATE anbieter_login_qr SET nutzungen = nutzungen + 1, letzte_nutzung = NOW() WHERE id = ?', [(int) $q['qr_id']]);
        Auth::loginProviderOhnePasswort($q);
    }
    header('Location: /anbieter/'); exit;
}

if ($anbieter === null) {
    // Passwort vergessen (Anbieter)
    if ($aktion === 'vergessen') {
        App\PasswordReset::requestAnbieter((string) ($_POST['email'] ?? ''), rtrim($config['app']['url'], '/') . '/anbieter/?reset=', Auth::clientIp(), (string) (($db->rawOne("SELECT wert FROM anbieter_einstellungen WHERE schluessel = 'produktname'")['wert'] ?? '') ?: 'Takt'));
        seiteKopf('Passwort vergessen');
        ?><div class="anmelden"><div class="marke-login"><b>Takt<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></b><span>Anbieter</span></div><h1>Passwort vergessen</h1><div class="meldung ok">Wenn zu dieser Adresse ein Anbieter-Zugang gehört, ist eine E-Mail mit dem Link unterwegs. Bitte auch den Spam-Ordner prüfen.</div><p style="margin-top:16px;text-align:center"><a href="/anbieter/">Zurück zur Anmeldung</a></p></div></body></html><?php
        exit;
    }
    if (isset($_GET['vergessen'])) {
        seiteKopf('Passwort vergessen');
        ?><div class="anmelden"><div class="marke-login"><b>Takt<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></b><span>Anbieter</span></div><h1>Passwort vergessen</h1><p style="color:var(--text2);margin-bottom:18px">Wir schicken einen Link zum Zurücksetzen an die E-Mail-Adresse Ihres Anbieter-Zugangs.</p>
          <?php if (!App\Mailer::configured()): ?><div class="meldung fehler">Der Mailversand ist noch nicht eingerichtet (system/config.php, Abschnitt mail). Notfall: Passwort direkt in phpMyAdmin setzen — Anleitung in der LIESMICH.</div><?php endif; ?>
          <form method="post"><input type="hidden" name="aktion" value="vergessen"><label for="em">E-Mail-Adresse</label><input id="em" name="email" type="email" required autofocus><button type="submit">Link anfordern</button></form>
          <p style="margin-top:16px;text-align:center"><a href="/anbieter/">Zurück zur Anmeldung</a></p></div></body></html><?php
        exit;
    }
    if (isset($_GET['reset']) || $aktion === 'reset') {
        $token = (string) ($_POST['token'] ?? $_GET['reset'] ?? ''); $fehler = null;
        if ($aktion === 'reset') {
            try {
                if (($_POST['neu'] ?? '') !== ($_POST['neu2'] ?? '')) { throw new RuntimeException('Die Passwörter stimmen nicht überein.'); }
                App\PasswordReset::completeAnbieter($token, (string) ($_POST['neu'] ?? ''));
                $meldung = ['typ' => 'ok', 'text' => 'Passwort gesetzt. Bitte anmelden.'];
            } catch (\Throwable $e) { $fehler = $e->getMessage(); }
        }
        if ($aktion !== 'reset' || $fehler !== null) {
            if (App\PasswordReset::checkAnbieter($token) === null) { $meldung = ['typ' => 'fehler', 'text' => 'Der Link ist ungültig oder abgelaufen. Bitte erneut anfordern.']; }
            else {
                seiteKopf('Neues Passwort');
                ?><div class="anmelden"><div class="marke-login"><b>Takt<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></b><span>Anbieter</span></div><h1>Neues Passwort</h1><?php if ($fehler): ?><div class="meldung fehler"><?= e($fehler) ?></div><?php endif; ?>
                  <form method="post"><input type="hidden" name="aktion" value="reset"><input type="hidden" name="token" value="<?= e($token) ?>"><label for="neu">Neues Passwort</label><input id="neu" name="neu" type="password" required minlength="12" autocomplete="new-password" autofocus><label for="neu2">Wiederholen</label><input id="neu2" name="neu2" type="password" required autocomplete="new-password"><button type="submit">Passwort setzen</button></form></div></body></html><?php
                exit;
            }
        }
    }
    if ($aktion === 'login') {
        try {
            $u = Auth::loginProvider((string) ($_POST['benutzername'] ?? ''), (string) ($_POST['passwort'] ?? ''));
            if (empty($u['zweiter_faktor'])) { header('Location: /anbieter/'); exit; }
        } catch (AuthException $e) {
            $meldung = ['typ' => 'fehler', 'text' => $e->getMessage()];
        }
    }
    if ($aktion === 'zweiter_faktor') {
        try { Auth::completeProviderSecondFactor((string) ($_POST['code'] ?? '')); header('Location: /anbieter/'); exit; }
        catch (AuthException $e) { $meldung = ['typ' => 'fehler', 'text' => $e->getMessage()]; }
    }
    if (Auth::pendingProviderSecondFactor()) {
        seiteKopf('Bestätigung');
        ?><div class="anmelden"><div class="marke-login"><b>Takt<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></b><span>Anbieter</span></div><h1>Bestätigung</h1><p style="color:var(--text2);margin-bottom:18px">Code aus der Authenticator-App oder ein Backup-Code.</p><?php meldungAnzeigen($meldung); ?>
          <form method="post"><input type="hidden" name="aktion" value="zweiter_faktor"><label for="code">Code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus style="font-size:24px;letter-spacing:.2em;text-align:center"><button type="submit">Bestätigen</button></form>
          <form method="post" style="margin-top:14px"><input type="hidden" name="aktion" value="logout"><button type="submit" class="zweit">Abbrechen</button></form></div></body></html><?php
        exit;
    }
    seiteKopf('Anmelden');
    ?>
    <div class="anmelden">
      <div class="marke-login"><b>Takt<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></b><span>Anbieter</span></div>
      <h1>Anbieter-Dashboard</h1><p class="unter">Verwaltung aller Betriebe, Verträge und Rechnungen.</p>
      <?php meldungAnzeigen($meldung); ?>
      <form method="post">
        <input type="hidden" name="aktion" value="login">
        <label for="bn">Benutzername</label>
        <input id="bn" name="benutzername" autocomplete="username" required autofocus>
        <label for="pw">Passwort</label>
        <input id="pw" name="passwort" type="password" autocomplete="current-password" required>
        <button type="submit">Anmelden</button>
      </form>
      <div class="links"><a href="/anbieter/?vergessen">Passwort vergessen?</a><span class="trenn"></span>Reinigungsbetrieb? <a href="/admin/">Zum Büro</a></div>
    </div>
    <?php
    seiteFuss();
    exit;
}

// ---------------------------------------------------------------------
// PDF ausliefern (nur angemeldet, Datei liegt im gesperrten system/)
// ---------------------------------------------------------------------
if ($ansicht === 'pdf') {
    $rid = (int) ($_GET['id'] ?? 0);
    try {
        $pfad = Invoice::render($rid, isset($_GET['neu']));
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($pfad) . '"');
        header('Content-Length: ' . (string) filesize($pfad));
        header('X-Content-Type-Options: nosniff');
        readfile($pfad);
        exit;
    } catch (\Throwable $e) {
        $meldung = ['typ' => 'fehler', 'text' => $e->getMessage()];
        $ansicht = 'uebersicht';
    }
}

// ---------------------------------------------------------------------
// Aktionen (nur angemeldet)
// ---------------------------------------------------------------------
if ($aktion !== null && $aktion !== 'login') {
    try {
        Auth::checkCsrf($_POST['csrf'] ?? null);
        $bid = (int) ($_POST['betrieb_id'] ?? 0);

        $nurSuperuser = ['demo', 'anlegen', 'status', 'tarif', 'testphase', 'passwort', 'vertrag', 'rechnung_erstellen', 'rechnung_senden', 'bezahlt', 'wieder_offen', 'storno', 'einstellungen', 'purge', 'anfrage_status', 'notiz'];
        if (in_array($aktion, $nurSuperuser, true) && ($anbieter['rolle'] ?? 'tester') !== 'superuser') { throw new RuntimeException('Diese Aktion ist dem Superuser vorbehalten. Sie sind als Tester angemeldet.'); }

        switch ($aktion) {
            case 'demo':
                $d = App\Demo::anlegen();
                $meldung = ['typ' => 'ok', 'text' => 'Demo-Betrieb „' . $d['name'] . '“ mit ' . $d['einsaetze'] . ' Einsätzen, Zeiten, Rechnungen, Angebot und Beispieldaten angelegt. Alle Zugänge:', 'demo' => $d];
                $ansicht = 'uebersicht';
                break;

            case 'anlegen':
                $r = Tenant::create([
                    'name'          => $_POST['name'] ?? '',
                    'inhaber_login' => $_POST['inhaber_login'] ?? '',
                    'email'         => $_POST['email'] ?? '',
                    'tarif'         => $_POST['tarif'] ?? 'basis',
                    'test_tage'     => (int) ($_POST['test_tage'] ?? 30),
                ]);
                $meldung = [
                    'typ'  => 'ok',
                    'text' => 'Betrieb angelegt. Zugangsdaten einmalig anzeigen und dem Inhaber übergeben:',
                    'zugang' => ['login' => $r['inhaber_login'], 'passwort' => $r['passwort']],
                ];
                $ansicht = 'uebersicht';
                break;

            case 'status':
                Tenant::setStatus($bid, (string) ($_POST['status'] ?? ''));
                $meldung = ['typ' => 'ok', 'text' => 'Status geändert.'];
                break;

            case 'tarif':
                Tenant::setTarif($bid, (string) ($_POST['tarif'] ?? ''));
                $meldung = ['typ' => 'ok', 'text' => 'Tarif geändert.'];
                break;

            case 'testphase':
                Tenant::extendTrial($bid, (int) ($_POST['tage'] ?? 30));
                $meldung = ['typ' => 'ok', 'text' => 'Testphase verlängert.'];
                break;

            case 'passwort':
                $pw = Tenant::resetOwnerPassword($bid);
                $meldung = $pw === null
                    ? ['typ' => 'fehler', 'text' => 'Kein Inhaber-Zugang gefunden.']
                    : ['typ' => 'ok', 'text' => 'Neues Inhaber-Passwort gesetzt. Einmalig sichtbar:', 'zugang' => ['passwort' => $pw]];
                break;

            case 'vertrag':
                Billing::saveVertrag($bid, $_POST);
                $meldung = ['typ' => 'ok', 'text' => 'Vertrag gespeichert.'];
                break;

            case 'rechnung_erstellen':
                $r = Billing::rechnungErstellen($bid);
                $meldung = ['typ' => 'ok', 'text' => 'Rechnung ' . $r['nummer'] . ' über ' . geld($r['brutto']) . ' erstellt.'];
                break;

            case 'rechnung_bezahlt':
                Billing::alsBezahlt((int) ($_POST['rechnung_id'] ?? 0), $_POST['bezahlt_am'] ?? null);
                $meldung = ['typ' => 'ok', 'text' => 'Als bezahlt gebucht.'];
                break;

            case 'rechnung_offen':
                Billing::wiederOffen((int) ($_POST['rechnung_id'] ?? 0));
                $meldung = ['typ' => 'ok', 'text' => 'Wieder auf offen gesetzt.'];
                break;

            case 'rechnung_storno':
                $nr = Billing::stornieren((int) ($_POST['rechnung_id'] ?? 0));
                $meldung = ['typ' => 'ok', 'text' => 'Storniert. Gegenrechnung ' . $nr . ' erzeugt.'];
                break;

            case 'eigenes_passwort':
                $alt = (string) ($_POST['alt'] ?? ''); $neu = (string) ($_POST['neu'] ?? '');
                if (!password_verify($alt, (string) $anbieter['passwort_hash'])) { throw new RuntimeException('Das bisherige Passwort stimmt nicht.'); }
                if (mb_strlen($neu) < 12) { throw new RuntimeException('Mindestens 12 Zeichen.'); }
                if ($neu !== (string) ($_POST['neu2'] ?? '')) { throw new RuntimeException('Die neuen Passwörter stimmen nicht überein.'); }
                $db->raw('UPDATE anbieter_benutzer SET passwort_hash = ? WHERE id = ?', [password_hash($neu, PASSWORD_DEFAULT), (int) $anbieter['id']]);
                $meldung = ['typ' => 'ok', 'text' => 'Passwort geändert.']; $ansicht = 'konto';
                break;

            case 'zfa_start':
                $zfaSetup = Auth::totpSetupStart($anbieter['benutzername'], 'Anbieter ' . ($config['app']['name'] ?? 'Reinigung')); $ansicht = 'konto';
                break;

            case 'zfa_bestaetigen':
                try { $zfaBackup = Auth::totpSetupConfirm('anbieter_benutzer', (int) $anbieter['id'], (string) ($_POST['code'] ?? '')); $anbieter['totp_aktiv'] = 1; $meldung = ['typ' => 'ok', 'text' => 'Zwei-Faktor-Anmeldung aktiv. Backup-Codes jetzt sichern.']; }
                catch (AuthException $e) { $meldung = ['typ' => 'fehler', 'text' => $e->getMessage()]; Auth::startSession(); $sec = (string) ($_SESSION['totp_setup_secret'] ?? ''); if ($sec !== '') { $zfaSetup = ['secret' => $sec, 'qr' => App\Totp::qrSvg(App\Totp::uri($sec, $anbieter['benutzername'], 'Anbieter ' . ($config['app']['name'] ?? 'Reinigung')))]; } }
                $ansicht = 'konto';
                break;

            case 'zfa_aus':
                if (!password_verify((string) ($_POST['passwort'] ?? ''), (string) $anbieter['passwort_hash'])) { throw new RuntimeException('Passwort stimmt nicht.'); }
                Auth::totpDisable('anbieter_benutzer', (int) $anbieter['id']); $anbieter['totp_aktiv'] = 0;
                $meldung = ['typ' => 'ok', 'text' => 'Zwei-Faktor-Anmeldung abgeschaltet.']; $ansicht = 'konto';
                break;

            case 'qr_login_erzeugen':
                $klartextQr = bin2hex(random_bytes(20));
                $db->raw('INSERT INTO anbieter_login_qr (anbieter_benutzer_id, token_hash, laeuft_ab) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY)) ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), laeuft_ab = VALUES(laeuft_ab), nutzungen = 0, letzte_nutzung = NULL', [(int) $anbieter['id'], hash('sha256', $klartextQr)]);
                $ansicht = 'konto';
                break;

            case 'qr_login_widerrufen':
                $db->raw('DELETE FROM anbieter_login_qr WHERE anbieter_benutzer_id = ?', [(int) $anbieter['id']]);
                $meldung = ['typ' => 'ok', 'text' => 'QR-Login widerrufen.']; $ansicht = 'konto';
                break;

            case 'benutzer_anlegen':
                if (($anbieter['rolle'] ?? 'tester') !== 'superuser') { throw new RuntimeException('Nur der Superuser darf Benutzer verwalten.'); }
                $bnn = trim((string) ($_POST['benutzername'] ?? '')); $bem = mb_strtolower(trim((string) ($_POST['email'] ?? ''))); $bpw = (string) ($_POST['passwort'] ?? ''); $brolle = ($_POST['rolle'] ?? 'tester') === 'superuser' ? 'superuser' : 'tester';
                if (preg_match('/^[A-Za-z0-9._@+-]{3,80}$/', $bnn) !== 1) { throw new RuntimeException('Benutzername: 3 bis 80 Zeichen, Buchstaben, Ziffern, Punkt, Bindestrich, @.'); }
                if ($bem !== '' && filter_var($bem, FILTER_VALIDATE_EMAIL) === false) { throw new RuntimeException('Ungültige E-Mail-Adresse.'); }
                if (mb_strlen($bpw) < 12) { throw new RuntimeException('Passwort: mindestens 12 Zeichen.'); }
                if ($db->rawOne('SELECT id FROM anbieter_benutzer WHERE benutzername = ?', [$bnn]) !== null) { throw new RuntimeException('Diesen Benutzernamen gibt es schon.'); }
                $db->raw('INSERT INTO anbieter_benutzer (benutzername, email, passwort_hash, name, rolle, aktiv) VALUES (?, ?, ?, ?, ?, 1)', [$bnn, $bem ?: null, password_hash($bpw, PASSWORD_DEFAULT), mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120) ?: null, $brolle]);
                $meldung = ['typ' => 'ok', 'text' => 'Benutzer „' . $bnn . '“ angelegt (' . ($brolle === 'superuser' ? 'Superuser' : 'Tester') . '). Zugangsdaten bitte persönlich übergeben.']; $ansicht = 'benutzer';
                break;

            case 'benutzer_status':
                if (($anbieter['rolle'] ?? 'tester') !== 'superuser') { throw new RuntimeException('Nur der Superuser darf Benutzer verwalten.'); }
                $bidu = (int) ($_POST['benutzer_id'] ?? 0);
                if ($bidu === (int) $anbieter['id']) { throw new RuntimeException('Das eigene Konto kann nicht geändert werden.'); }
                $ziel = $db->rawOne('SELECT * FROM anbieter_benutzer WHERE id = ?', [$bidu]); if ($ziel === null) { throw new RuntimeException('Benutzer nicht gefunden.'); }
                $was = (string) ($_POST['was'] ?? '');
                if ($was === 'deaktivieren') { $db->raw('UPDATE anbieter_benutzer SET aktiv = 0 WHERE id = ?', [$bidu]); $db->raw('DELETE FROM anbieter_login_qr WHERE anbieter_benutzer_id = ?', [$bidu]); $meldung = ['typ' => 'ok', 'text' => 'Benutzer deaktiviert.']; }
                elseif ($was === 'aktivieren') { $db->raw('UPDATE anbieter_benutzer SET aktiv = 1, fehlversuche = 0, gesperrt_bis = NULL WHERE id = ?', [$bidu]); $meldung = ['typ' => 'ok', 'text' => 'Benutzer aktiviert.']; }
                elseif ($was === 'rolle') { $nr = ($_POST['rolle'] ?? '') === 'superuser' ? 'superuser' : 'tester'; $db->raw('UPDATE anbieter_benutzer SET rolle = ? WHERE id = ?', [$nr, $bidu]); $meldung = ['typ' => 'ok', 'text' => 'Rolle geändert.']; }
                elseif ($was === 'passwort') { $npw = bin2hex(random_bytes(6)); $db->raw('UPDATE anbieter_benutzer SET passwort_hash = ?, fehlversuche = 0, gesperrt_bis = NULL, totp_aktiv = 0, totp_secret_enc = NULL, totp_backup = NULL WHERE id = ?', [password_hash($npw, PASSWORD_DEFAULT), $bidu]); $meldung = ['typ' => 'ok', 'text' => 'Neues Passwort für ' . $ziel['benutzername'] . ' gesetzt (Zwei-Faktor zurückgesetzt). Einmalig sichtbar:', 'zugang' => ['login' => $ziel['benutzername'], 'passwort' => $npw]]; }
                $ansicht = 'benutzer';
                break;

            case 'vertrag_pdf':
                $pf = App\Contract::pdf($bid, true);
                while (ob_get_level() > 0) { ob_end_clean(); }
                header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . basename($pf) . '"'); header('Content-Length: ' . filesize($pf)); readfile($pf); exit;

            case 'direkt':
                if (($anbieter['rolle'] ?? 'tester') !== 'superuser') { throw new RuntimeException('Nur für den Superuser.'); }
                $ziel = (string) ($_POST['ziel'] ?? ''); $benId = (int) ($_POST['benutzer_id'] ?? 0);
                if ($ziel === 'buero') { $zb = $db->rawOne("SELECT * FROM benutzer WHERE betrieb_id = ? AND rolle = 'inhaber' AND aktiv = 1 ORDER BY id LIMIT 1", [$bid]); }
                elseif ($ziel === 'app') { $zb = $db->rawOne("SELECT * FROM benutzer WHERE id = ? AND betrieb_id = ? AND rolle = 'mitarbeiter' AND aktiv = 1", [$benId, $bid]); }
                elseif ($ziel === 'portal') { $zb = $db->rawOne("SELECT * FROM benutzer WHERE id = ? AND betrieb_id = ? AND rolle = 'kunde' AND aktiv = 1", [$benId, $bid]); }
                else { $zb = null; }
                if ($zb === null) { throw new RuntimeException('Kein passender Zugang gefunden.'); }
                $dt = bin2hex(random_bytes(20));
                $db->raw('INSERT INTO anbieter_direktzugang (token_hash, betrieb_id, benutzer_id, ziel, erstellt_von, laeuft_ab) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 2 MINUTE))', [hash('sha256', $dt), $bid, (int) $zb['id'], $ziel, $anbieter['benutzername'] ?? null]);
                $db->raw("INSERT INTO audit_log (betrieb_id, benutzer_id, aktion, beschreibung, ip_adresse) VALUES (?, ?, 'login', ?, ?)", [$bid, (int) $zb['id'], 'Direktzugang Anbieter (' . ($anbieter['benutzername'] ?? '') . ') → ' . $ziel, Auth::clientIp()]);
                header('Location: /' . ($ziel === 'buero' ? 'admin' : $ziel) . '/?direkt=' . $dt); exit;

            case 'schnellzugang_erzeugen':
                $bs = $db->rawOne('SELECT * FROM betriebe WHERE id = ?', [$bid]);
                if ($bs === null || $bs['status'] !== 'test') { throw new RuntimeException('Nur für Betriebe in der Testphase.'); }
                $klartextZugang = bin2hex(random_bytes(20));
                $db->raw('INSERT INTO anbieter_schnellzugang (betrieb_id, token_hash, erstellt_von, laeuft_ab) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY)) ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), erstellt_von = VALUES(erstellt_von), laeuft_ab = VALUES(laeuft_ab), nutzungen = 0, letzte_nutzung = NULL',
                    [$bid, hash('sha256', $klartextZugang), $anbieter['benutzername'] ?? null]);
                $ansicht = 'betrieb';
                break;

            case 'schnellzugang_widerrufen':
                $db->raw('DELETE FROM anbieter_schnellzugang WHERE betrieb_id = ?', [$bid]);
                $meldung = ['typ' => 'ok', 'text' => 'Schnellzugang widerrufen.'];
                $ansicht = 'betrieb';
                break;

            case 'purge':
                if (($_POST['bestaetigung'] ?? '') !== 'LÖSCHEN') { throw new RuntimeException('Zur Bestätigung LÖSCHEN eingeben.'); }
                $pr = Tenant::purge($bid);
                $meldung = ['typ' => 'ok', 'text' => "Betriebsdaten endgültig gelöscht: {$pr['zeilen']} Datensätze, {$pr['dateien']} Dateien. Die Rechnungen an den Betrieb bleiben erhalten."];
                $ansicht = 'betrieb';
                break;

            case 'anfrage_status':
                $st = in_array($_POST['status'] ?? '', ['neu', 'kontaktiert', 'testphase', 'kunde', 'abgelehnt'], true) ? $_POST['status'] : 'neu';
                $db->raw('UPDATE anbieter_anfragen SET status = ? WHERE id = ?', [$st, (int) ($_POST['anfrage_id'] ?? 0)]);
                $ansicht = 'anfragen';
                break;

            case 'einstellungen':
                // Mail-Einstellungen separat speichern (bleiben in anbieter_einstellungen, nicht in Invoice)
                foreach (['mail_host','mail_port','mail_secure','mail_user','mail_password','mail_from_email','mail_from_name'] as $mk) {
                    $mv = trim((string) ($_POST[$mk] ?? ''));
                    if ($mk === 'mail_password' && $mv === '••••••••') { continue; } // Platzhalter, nicht ueberschreiben
                    $db->raw("INSERT INTO anbieter_einstellungen (schluessel, wert) VALUES (?, ?) ON DUPLICATE KEY UPDATE wert = VALUES(wert)", [$mk, $mv]);
                }
                App\Mailer::init($config['mail'] ?? []);   // Neu laden mit DB-Werten
                Invoice::saveSettings($_POST + ['abrechnung_automatisch' => !empty($_POST['abrechnung_automatisch']) ? '1' : '0', 'abrechnung_senden' => !empty($_POST['abrechnung_senden']) ? '1' : '0']);
                if (!empty($_POST['mit_testmail'])) {
                    $an = trim((string) ($_POST['test_email'] ?? '')); if (filter_var($an, FILTER_VALIDATE_EMAIL) === false) { throw new RuntimeException('Einstellungen gespeichert. Für die Testmail bitte eine gültige Empfänger-Adresse eingeben.'); }
                    if (!App\Mailer::configured()) { throw new RuntimeException('Einstellungen gespeichert, aber Mailversand unvollständig: SMTP-Benutzer und Absender-Adresse fehlen.'); }
                    try { App\Mailer::send($an, '', 'Testmail von ' . (Invoice::settings()['produktname'] ?? 'Takt'), "Diese Testmail wurde aus den Einstellungen im Anbieter-Dashboard gesendet.\n\nAbsender: " . App\Mailer::fromEmail() . "\nZeitpunkt: " . date('d.m.Y H:i:s'), (string) (Invoice::settings()['produktname'] ?? 'Takt')); }
                    catch (\Throwable $me) { throw new RuntimeException('Einstellungen gespeichert, aber die Testmail scheiterte: ' . $me->getMessage()); }
                    $meldung = ['typ' => 'ok', 'text' => 'Einstellungen gespeichert, Testmail an ' . $an . ' gesendet. Nicht angekommen? Spam-Ordner prüfen.']; $ansicht = 'einstellungen'; break;
                }
                $meldung = ['typ' => 'ok', 'text' => 'Einstellungen gespeichert.'];
                $ansicht = 'einstellungen';
                break;

            case 'rechnung_senden':
                Invoice::send((int) ($_POST['rechnung_id'] ?? 0), $config['mail']);
                $meldung = ['typ' => 'ok', 'text' => 'Rechnung per E-Mail versendet.'];
                break;

            case 'notiz':
                $text = trim((string) ($_POST['notiz'] ?? ''));
                if ($text !== '' && $bid > 0) {
                    $db->raw('INSERT INTO anbieter_notizen (betrieb_id, anbieter_id, notiz) VALUES (?, ?, ?)',
                        [$bid, (int) $anbieter['id'], mb_substr($text, 0, 4000)]);
                    $meldung = ['typ' => 'ok', 'text' => 'Notiz gespeichert.'];
                }
                break;
        }
    } catch (\Throwable $e) {
        $meldung = ['typ' => 'fehler', 'text' => $e->getMessage()];
    }
}

$csrf = Auth::csrfToken();

// ---------------------------------------------------------------------
// Daten laden
// ---------------------------------------------------------------------
$betriebe = $db->rawAll('SELECT * FROM v_anbieter_betriebe ORDER BY status = \'gekuendigt\', name');

$summe = ['gesamt' => count($betriebe), 'aktiv' => 0, 'test' => 0, 'gekuendigt' => 0, 'mitarbeiter' => 0];
foreach ($betriebe as $b) {
    $summe[$b['status']] = ($summe[$b['status']] ?? 0) + 1;
    $summe['mitarbeiter'] += (int) $b['mitarbeiter_aktiv'];
}

$kz = Billing::kennzahlen();
$einst = Invoice::settings();
$istSuper = ($anbieter['rolle'] ?? 'tester') === 'superuser';
if (($einst['cron_token'] ?? '') === '') {
    $db->raw("INSERT INTO anbieter_einstellungen (schluessel, wert) VALUES ('cron_token', ?) ON DUPLICATE KEY UPDATE wert = VALUES(wert)", [bin2hex(random_bytes(24))]);
    $einst = Invoice::settings();
}
$einstFehlt = Invoice::settingsComplete($einst);
$tarife = Billing::tarife();

$detail = null;
$notizen = [];
$vertrag = null;
$rechnungen = [];
if ($ansicht === 'betrieb') {
    $did = (int) ($_GET['id'] ?? 0);
    $detail = $db->rawOne('SELECT * FROM v_anbieter_betriebe WHERE id = ?', [$did]);
    if ($detail !== null) {
        $vertrag = Billing::vertrag($did);
        $rechnungen = Billing::rechnungen($did);
        $mailStatus = Invoice::mailStatus(array_column($rechnungen, 'id'));
        $notizen = $db->rawAll(
            'SELECT n.*, a.name AS von FROM anbieter_notizen n JOIN anbieter_benutzer a ON a.id = n.anbieter_id
              WHERE n.betrieb_id = ? ORDER BY n.erstellt_am DESC LIMIT 50', [$did]);
    }
}

// ---------------------------------------------------------------------
// Ausgabe
// ---------------------------------------------------------------------
seiteKopf($detail ? $detail['name'] : 'Betriebe');
?>
<header class="kopf">
  <div class="marke"><a href="/anbieter/">Anbieter</a></div>
  <?php $anfragenNeu = 0; try { $anfragenNeu = (int) ($db->rawOne("SELECT COUNT(*) n FROM anbieter_anfragen WHERE status = 'neu'")['n'] ?? 0); } catch (\Throwable) {} ?>
  <nav>
    <a href="/anbieter/" class="<?= $ansicht === 'uebersicht' ? 'aktiv' : '' ?>">Betriebe</a>
    <?php if ($istSuper): ?><a href="/anbieter/?s=neu" class="<?= $ansicht === 'neu' ? 'aktiv' : '' ?>">Betrieb anlegen</a><?php endif; ?>
    <a href="/anbieter/?s=anfragen" class="<?= $ansicht === 'anfragen' ? 'aktiv' : '' ?>">Anfragen<?= ($anfragenNeu ?? 0) > 0 ? ' <span class="punkt"></span>' : '' ?></a>
    <?php if ($istSuper): ?><a href="/anbieter/?s=einstellungen" class="<?= $ansicht === 'einstellungen' ? 'aktiv' : '' ?>">Einstellungen<?= $einstFehlt !== [] ? ' <span class="punkt"></span>' : '' ?></a><?php endif; ?>
    <?php if (($anbieter['rolle'] ?? 'tester') === 'superuser'): ?><a href="/anbieter/?s=benutzer" class="<?= $ansicht === 'benutzer' ? 'aktiv' : '' ?>">Benutzer</a><?php endif; ?>
    <a href="/anbieter/?s=konto" class="<?= $ansicht === 'konto' ? 'aktiv' : '' ?>">Konto<?= (int) ($anbieter['totp_aktiv'] ?? 0) === 0 ? ' <span class="punkt"></span>' : '' ?></a>
  </nav>
  <form method="post" class="inline">
    <input type="hidden" name="aktion" value="logout">
    <span class="wer"><?= e($anbieter['name'] ?? $anbieter['benutzername']) ?></span>
    <button type="submit" class="leise">Abmelden</button>
  </form>
</header>

<main>
<?php meldungAnzeigen($meldung); ?>

<?php if (!$istSuper && in_array($ansicht, ['neu', 'einstellungen', 'benutzer'], true)): ?>
  <div class="meldung fehler">Dieser Bereich ist dem Superuser vorbehalten.</div>
<?php elseif ($ansicht === 'konto'): $qr = $db->rawOne('SELECT * FROM anbieter_login_qr WHERE anbieter_benutzer_id = ? AND laeuft_ab > NOW()', [(int) $anbieter['id']]); ?>
  <h1>Konto</h1>
  <p class="unter">Angemeldet als <b><?= e($anbieter['benutzername']) ?></b> · <?= ($anbieter['rolle'] ?? 'tester') === 'superuser' ? 'Superuser — voller Zugriff' : 'Tester — Ansehen und Testen, keine Verwaltung' ?>.</p>
  <div class="formular" style="margin-bottom:22px">
    <h2 class="erste">Anmelden per QR-Code</h2>
    <?php if (!empty($klartextQr)): $ql = rtrim($config['app']['url'], '/') . '/anbieter/?zugang=' . $klartextQr; ?>
      <div class="sz-neu"><div class="sz-qr"><?= \App\Totp::qrSvg($ql) ?></div><div><p>Mit dem Handy scannen — Sie sind sofort angemeldet, ohne Benutzername und Passwort. Auch als Lesezeichen am Handy speichern.</p><div class="sz-link"><code><?= e($ql) ?></code></div><p class="tipp">30 Tage gültig, nur für Ihr Konto. Ist Zwei-Faktor eingerichtet, wird der Code trotzdem verlangt. Wird nach dem Verlassen dieser Seite nicht mehr angezeigt.</p></div></div>
    <?php elseif ($qr): ?>
      <p>Aktiv, gültig bis <?= e(date('d.m.Y', strtotime($qr['laeuft_ab']))) ?><?= (int) $qr['nutzungen'] > 0 ? ' · ' . (int) $qr['nutzungen'] . '× verwendet' : ' · noch nicht verwendet' ?>.</p>
      <div class="zeile-knoepfe"><form method="post" class="inl" onsubmit="return confirm('Neu erzeugen? Der bisherige QR-Code gilt danach nicht mehr.')"><input type="hidden" name="aktion" value="qr_login_erzeugen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="zweit">Neu erzeugen und anzeigen</button></form><form method="post" class="inl"><input type="hidden" name="aktion" value="qr_login_widerrufen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="leise">Widerrufen</button></form></div>
    <?php else: ?>
      <p class="unter">Nie wieder Benutzername und Passwort tippen: QR-Code erzeugen, mit dem Handy scannen, angemeldet.</p>
      <form method="post"><input type="hidden" name="aktion" value="qr_login_erzeugen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit">QR-Code erzeugen</button></form>
    <?php endif; ?>
  </div>
  <div class="zweispalt">
    <form method="post" class="formular"><input type="hidden" name="aktion" value="eigenes_passwort"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <h2 class="erste">Passwort ändern</h2>
      <div class="g"><label for="alt">Bisheriges Passwort</label><input id="alt" name="alt" type="password" autocomplete="current-password" required></div>
      <div class="g"><label for="neu">Neues Passwort</label><input id="neu" name="neu" type="password" autocomplete="new-password" required minlength="12"><div class="tipp">Mindestens 12 Zeichen — dieser Zugang sieht alle Betriebe.</div></div>
      <div class="g"><label for="neu2">Wiederholen</label><input id="neu2" name="neu2" type="password" autocomplete="new-password" required></div>
      <button type="submit">Ändern</button></form>
    <div class="formular">
      <h2 class="erste">Zwei-Faktor-Anmeldung</h2>
      <?php if (!empty($zfaBackup)): ?><p style="margin-bottom:10px">Backup-Codes, jeder gilt einmal:</p><div class="backup-codes"><?php foreach ($zfaBackup as $c): ?><code><?= e($c) ?></code><?php endforeach; ?></div><p class="klein">Ausdrucken oder im Passwortmanager ablegen.</p>
      <?php elseif (!empty($zfaSetup)): ?><p style="margin-bottom:12px">1. Mit der Authenticator-App scannen:</p><div class="qr-setup"><?= $zfaSetup['qr'] ?></div><p class="klein" style="margin:8px 0 14px">Manuell: <code><?= e(chunk_split($zfaSetup['secret'], 4, ' ')) ?></code></p>
        <form method="post"><input type="hidden" name="aktion" value="zfa_bestaetigen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><div class="g"><label for="zc">2. Code aus der App</label><input id="zc" name="code" inputmode="numeric" autocomplete="one-time-code" required style="font-size:22px;letter-spacing:.2em;max-width:220px"></div><button type="submit">Aktivieren</button></form>
      <?php elseif ((int) ($anbieter['totp_aktiv'] ?? 0) === 1): ?><p style="margin-bottom:14px"><span class="marke-ok">aktiv</span></p>
        <form method="post"><input type="hidden" name="aktion" value="zfa_aus"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><div class="g" style="max-width:300px"><label for="zp">Passwort zur Bestätigung</label><input id="zp" name="passwort" type="password" required></div><button type="submit" class="zweit" onclick="return confirm('Abschalten?')">Abschalten</button></form>
      <?php else: ?><p style="margin-bottom:14px"><span class="marke-rot">nicht aktiv</span> Dieser Zugang verwaltet alle Betriebe. Bitte einrichten.</p>
        <form method="post"><input type="hidden" name="aktion" value="zfa_start"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit">Einrichten</button></form>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($ansicht === 'benutzer' && ($anbieter['rolle'] ?? 'tester') === 'superuser'): $alleB = $db->rawAll('SELECT b.*, (SELECT laeuft_ab FROM anbieter_login_qr q WHERE q.anbieter_benutzer_id = b.id AND q.laeuft_ab > NOW()) qr_bis FROM anbieter_benutzer b ORDER BY b.aktiv DESC, b.rolle = \'superuser\' DESC, b.benutzername'); ?>
  <h1>Benutzer</h1>
  <p class="unter">Wer sich hier im Anbieter-Dashboard anmelden darf. <b>Superuser</b> sehen und dürfen alles. <b>Tester</b> sehen alles, dürfen aber nichts verwalten — keine Verträge, Preise, Rechnungen, Einstellungen, keine Löschung; sie können Betriebe ansehen und Testbetriebe über den Schnellzugang öffnen.</p>
  <div class="zweispalt">
    <section><table><thead><tr><th>Benutzer</th><th>Rolle</th><th>Letzter Login</th><th></th></tr></thead><tbody>
      <?php foreach ($alleB as $b): $ich = (int) $b['id'] === (int) $anbieter['id']; ?><tr class="<?= (int) $b['aktiv'] === 0 ? 'aus' : '' ?>"><td><b><?= e($b['benutzername']) ?></b><?= $ich ? ' <span class="klein">(Sie)</span>' : '' ?><div class="klein"><?= e($b['name'] ?? '') ?><?= $b['email'] ? ' · ' . e($b['email']) : '' ?><?= $b['qr_bis'] ? ' · QR-Login aktiv' : '' ?></div></td>
        <td><?= $b['rolle'] === 'superuser' ? '<span class="marke-ok">Superuser</span>' : '<span class="marke-aus">Tester</span>' ?><?= (int) $b['aktiv'] === 0 ? ' <span class="marke-rot">inaktiv</span>' : '' ?></td>
        <td class="klein"><?= $b['letzter_login'] ? e(date('d.m.Y H:i', strtotime($b['letzter_login']))) : 'noch nie' ?></td>
        <td class="r"><?php if (!$ich): ?><div class="zeile-knoepfe" style="justify-content:flex-end;flex-wrap:nowrap">
          <form method="post" class="inl"><input type="hidden" name="aktion" value="benutzer_status"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="benutzer_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="was" value="rolle"><input type="hidden" name="rolle" value="<?= $b['rolle'] === 'superuser' ? 'tester' : 'superuser' ?>"><button type="submit" class="mini zweit"><?= $b['rolle'] === 'superuser' ? 'Zu Tester' : 'Zu Superuser' ?></button></form>
          <form method="post" class="inl" onsubmit="return confirm('Neues Passwort setzen? Das alte gilt dann nicht mehr, Zwei-Faktor wird zurückgesetzt.')"><input type="hidden" name="aktion" value="benutzer_status"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="benutzer_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="was" value="passwort"><button type="submit" class="mini zweit">Passwort neu</button></form>
          <form method="post" class="inl"><input type="hidden" name="aktion" value="benutzer_status"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="benutzer_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="was" value="<?= (int) $b['aktiv'] === 1 ? 'deaktivieren' : 'aktivieren' ?>"><button type="submit" class="mini <?= (int) $b['aktiv'] === 1 ? 'leise' : '' ?>"><?= (int) $b['aktiv'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?></button></form></div><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></section>
    <section><form method="post" class="formular"><input type="hidden" name="aktion" value="benutzer_anlegen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <h2 class="erste">Benutzer einladen</h2>
      <div class="g"><label for="nb">Benutzername</label><input id="nb" name="benutzername" required maxlength="80" placeholder="z. B. partner oder E-Mail"></div>
      <div class="g"><label for="nn">Name</label><input id="nn" name="name" maxlength="120"></div>
      <div class="g"><label for="ne">E-Mail</label><input id="ne" name="email" type="email"><div class="tipp">Für „Passwort vergessen"</div></div>
      <div class="g"><label for="np">Startpasswort</label><input id="np" name="passwort" type="password" required minlength="12" autocomplete="new-password"><div class="tipp">Mindestens 12 Zeichen — persönlich übergeben, die Person ändert es unter Konto.</div></div>
      <div class="g"><label for="nr">Rolle</label><select id="nr" name="rolle"><option value="tester">Tester — ansehen und testen</option><option value="superuser">Superuser — voller Zugriff</option></select></div>
      <button type="submit">Anlegen</button></form></section>
  </div>

<?php elseif ($ansicht === 'anfragen'):
    $anfr = []; try { $anfr = $db->rawAll("SELECT * FROM anbieter_anfragen ORDER BY status = 'neu' DESC, erstellt_am DESC LIMIT 200"); } catch (\Throwable) { echo '<div class="meldung fehler">Tabelle anbieter_anfragen fehlt — bitte schema_vertrieb.sql importieren.</div>'; }
    $stat = ['neu' => 'neu', 'kontaktiert' => 'kontaktiert', 'testphase' => 'Testphase', 'kunde' => 'Kunde', 'abgelehnt' => 'abgelehnt'];
?>
  <h1>Anfragen von der Vertriebsseite</h1>
  <?php if ($anfr === []): ?><p class="leer">Keine Anfragen.</p><?php else: ?>
  <div class="liste"><?php foreach ($anfr as $a): ?>
    <div class="eintrag" style="grid-template-columns:60px 1fr auto;align-items:start">
      <div class="zeit"><?= e(date('d.m.', strtotime($a['erstellt_am']))) ?></div>
      <div><div class="titel"><?= e($a['firma'] ?: $a['name']) ?> <?= $a['status'] === 'neu' ? '<span class="marke-test">neu</span>' : '<span class="marke-aus">' . e($stat[$a['status']] ?? $a['status']) . '</span>' ?></div>
        <div class="sub"><?= e($a['name']) ?> · <a href="mailto:<?= e($a['email']) ?>"><?= e($a['email']) ?></a><?= $a['telefon'] ? ' · <a href="tel:' . e(preg_replace('/\s+/', '', $a['telefon'])) . '">' . e($a['telefon']) . '</a>' : '' ?><?= $a['mitarbeiter'] ? ' · ' . e($a['mitarbeiter']) . ' Reinigungskräfte' : '' ?></div>
        <?php if ($a['nachricht']): ?><div class="nachricht" style="margin-top:8px;padding:10px 12px;background:var(--papier);border-radius:7px;font-size:14px;white-space:pre-wrap"><?= e($a['nachricht']) ?></div><?php endif; ?></div>
      <form method="post" class="inl"><input type="hidden" name="aktion" value="anfrage_status"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="anfrage_id" value="<?= (int) $a['id'] ?>">
        <select name="status" onchange="this.form.submit()" aria-label="Status"><?php foreach ($stat as $k => $v): ?><option value="<?= $k ?>" <?= $a['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></form>
    </div>
  <?php endforeach; ?></div><?php endif; ?>

<?php elseif ($ansicht === 'einstellungen'): ?>

  <h1>Einstellungen</h1>
  <p class="unter">Deine Daten als Rechnungsaussteller. Sie erscheinen auf jeder Rechnung an die Betriebe.</p>
  <?php if ($einstFehlt !== []): ?><div class="meldung fehler">Für gültige Rechnungen fehlt noch: <?= e(implode(', ', $einstFehlt)) ?></div><?php endif; ?>
  <form method="post" class="formular" style="max-width:720px">
    <input type="hidden" name="aktion" value="einstellungen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <h2 class="erste">Firma</h2>
    <div class="zwei">
      <div class="g"><label>Firmenname</label><input name="firma" value="<?= e($einst['firma'] ?? '') ?>" required></div>
      <div class="g"><label>Inhaber</label><input name="inhaber" value="<?= e($einst['inhaber'] ?? '') ?>"></div>
    </div>
    <div class="g"><label>Straße und Hausnummer</label><input name="adresse" value="<?= e($einst['adresse'] ?? '') ?>" required></div>
    <div class="zwei">
      <div class="g"><label>PLZ</label><input name="plz" value="<?= e($einst['plz'] ?? '') ?>" required></div>
      <div class="g"><label>Ort</label><input name="ort" value="<?= e($einst['ort'] ?? '') ?>" required></div>
    </div>
    <div class="drei">
      <div class="g"><label>Telefon</label><input name="telefon" value="<?= e($einst['telefon'] ?? '') ?>"></div>
      <div class="g"><label>E-Mail</label><input name="email" type="email" value="<?= e($einst['email'] ?? '') ?>"></div>
      <div class="g"><label>Website</label><input name="web" value="<?= e($einst['web'] ?? '') ?>"></div>
    </div>
    <h2>Steuer</h2>
    <div class="zwei">
      <div class="g"><label>UID-Nummer</label><input name="uid" value="<?= e($einst['uid'] ?? '') ?>" placeholder="ATU12345678"></div>
      <div class="g"><label><input type="checkbox" name="kleinunternehmer" value="1" <?= ($einst['kleinunternehmer'] ?? '0') === '1' ? 'checked' : '' ?>> Kleinunternehmer</label><div class="tipp">§ 6 Abs. 1 Z 27 UStG — keine USt auf Rechnungen, Hinweis wird gedruckt. Im Vertrag je Betrieb dann USt 0 % setzen.</div></div>
    </div>
    <h2>Bankverbindung</h2>
    <div class="drei">
      <div class="g"><label>Bank</label><input name="bank" value="<?= e($einst['bank'] ?? '') ?>"></div>
      <div class="g"><label>IBAN</label><input name="iban" value="<?= e($einst['iban'] ?? '') ?>" required></div>
      <div class="g"><label>BIC</label><input name="bic" value="<?= e($einst['bic'] ?? '') ?>"></div>
    </div>
    <?php $mc = []; foreach ($db->rawAll("SELECT schluessel, wert FROM anbieter_einstellungen WHERE schluessel LIKE 'mail_%'") as $r) { $mc[$r['schluessel']] = $r['wert']; } $mailOk = ($mc['mail_host'] ?? '') !== '' || App\Mailer::configured(); ?>
    <h2>Mailversand <?= $mailOk ? '<span style="color:var(--haus)">✓</span>' : '<span class="punkt"></span>' ?></h2>
    <p class="tipp" style="margin-bottom:10px">Für Rechnungen, Mahnungen, Passwort vergessen, Tagesberichte. Zugangsdaten bekommt man beim Hosting-Anbieter (z. B. easyname → Postfach).</p>
    <div class="drei"><div class="g"><label>SMTP-Server</label><input name="mail_host" value="<?= e($mc['mail_host'] ?? $config['mail']['host'] ?? '') ?>" placeholder="smtp.easyname.com"></div>
      <div class="g"><label>Port</label><input name="mail_port" type="number" value="<?= (int) ($mc['mail_port'] ?? $config['mail']['port'] ?? 587) ?>" style="max-width:100px"></div>
      <div class="g"><label>Verschlüsselung</label><select name="mail_secure"><option value="tls" <?= ($mc['mail_secure'] ?? $config['mail']['secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (Port 587)</option><option value="ssl" <?= ($mc['mail_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option></select></div></div>
    <div class="zwei"><div class="g"><label>Benutzer</label><input name="mail_user" value="<?= e($mc['mail_user'] ?? $config['mail']['user'] ?? '') ?>" autocomplete="off"></div>
      <div class="g"><label>Passwort</label><input name="mail_password" type="password" value="<?= ($mc['mail_password'] ?? $config['mail']['password'] ?? '') !== '' ? '••••••••' : '' ?>" autocomplete="new-password"></div></div>
    <div class="zwei"><div class="g"><label>Absender-Adresse</label><input name="mail_from_email" type="email" value="<?= e($mc['mail_from_email'] ?? $config['mail']['from_email'] ?? '') ?>" placeholder="office@firma.at"></div>
      <div class="g"><label>Absender-Name</label><input name="mail_from_name" value="<?= e($mc['mail_from_name'] ?? $config['mail']['from_name'] ?? 'Takt') ?>"></div></div>
    <div class="g" style="margin-top:8px"><div style="display:flex;gap:8px;align-items:center"><input name="test_email" type="email" placeholder="Ihre E-Mail zum Testen" value="<?= e($anbieter['email'] ?? '') ?>" style="max-width:280px;margin:0"><button type="submit" name="mit_testmail" value="1" class="zweit" style="white-space:nowrap">Speichern und Testmail senden</button></div><div class="tipp">Speichert alle Einstellungen und schickt sofort eine Testmail. Kommt sie nicht an: Spam-Ordner, dann SMTP-Daten prüfen.</div></div>

    <h2>Cronjob</h2>
    <p class="tipp" style="margin-bottom:10px">Bei easyname unter Hosting → Cronjobs diese Adresse alle 15 Minuten aufrufen lassen. Erzeugt Einsätze, versendet Benachrichtigungen, löscht abgelaufene Fotos.</p>
    <div class="g"><code style="display:block;padding:10px 12px;word-break:break-all"><?= e(rtrim($config['app']['url'], '/')) ?>/cron/?token=<?= e($einst['cron_token'] ?? '') ?></code></div>
    <h2>Abrechnung der Betriebe</h2>
    <label class="tag-haken" style="display:block;margin-bottom:8px"><input type="checkbox" name="abrechnung_automatisch" value="1" <?= ($einst['abrechnung_automatisch'] ?? '0') === '1' ? 'checked' : '' ?>> Fällige Abo-Rechnungen automatisch erstellen (täglich durch den Cronjob, nur aktive Betriebe)</label>
    <label class="tag-haken" style="display:block;margin-bottom:8px"><input type="checkbox" name="abrechnung_senden" value="1" <?= ($einst['abrechnung_senden'] ?? '0') === '1' ? 'checked' : '' ?>> … und sofort per E-Mail an die Rechnungsadresse des Betriebs senden</label>
    <div class="tipp">Ohne Haken erstellst du die Rechnungen wie bisher je Betrieb von Hand. Der Cron meldet erstellte Rechnungen in seiner Ausgabe.</div>
    <h2>Vertriebsseite und Impressum</h2>
    <div class="drei"><div class="g"><label>Produktname</label><input name="produktname" value="<?= e($einst['produktname'] ?? '') ?>" placeholder="Takt"><div class="tipp">Erscheint auf der Vertriebsseite und in Mails</div></div><div class="g"><label>Gewerbe</label><input name="gewerbe" value="<?= e($einst['gewerbe'] ?? '') ?>" placeholder="IT-Dienstleistung"></div><div class="g"><label>Gewerbebehörde</label><input name="behoerde" value="<?= e($einst['behoerde'] ?? '') ?>" placeholder="Magistratisches Bezirksamt für den 22. Bezirk"></div></div>
    <div class="g"><label>Kammermitgliedschaft</label><input name="kammer" value="<?= e($einst['kammer'] ?? '') ?>" placeholder="Wirtschaftskammer Wien, Fachgruppe UBIT"></div>
    <h2>Rechnungstexte</h2>
    <div class="g"><label>Einleitung</label><input name="rechnung_einleitung" value="<?= e($einst['rechnung_einleitung'] ?? '') ?>"></div>
    <div class="g"><label>Schlusstext</label><input name="rechnung_schluss" value="<?= e($einst['rechnung_schluss'] ?? '') ?>"></div>
    <button type="submit">Speichern</button>
  </form>

<?php elseif ($ansicht === 'neu'): ?>

  <h1>Betrieb anlegen</h1>
  <p class="unter">Inhaber-Zugang, Leistungskatalog, Vorlagen und Nummernkreise werden mit angelegt. Das Startpasswort wird einmalig angezeigt.</p>
  <form method="post" class="formular">
    <input type="hidden" name="aktion" value="anlegen">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="g"><label for="n">Firmenname</label><input id="n" name="name" required maxlength="150"></div>
    <div class="zwei">
      <div class="g"><label for="il">Inhaber-Benutzername</label><input id="il" name="inhaber_login" required pattern="[A-Za-z0-9._-]{3,40}" maxlength="40"><div class="tipp">Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich</div></div>
      <div class="g"><label for="em">E-Mail des Inhabers</label><input id="em" name="email" type="email" maxlength="150"></div>
    </div>
    <div class="zwei">
      <div class="g"><label for="ta">Tarif</label>
        <select id="ta" name="tarif">
          <option value="basis">Basis — bis 10 Mitarbeiter</option>
          <option value="plus">Plus — bis 25 Mitarbeiter</option>
          <option value="pro">Pro — unbegrenzt</option>
        </select></div>
      <div class="g"><label for="tt">Testphase in Tagen</label><input id="tt" name="test_tage" type="number" min="0" max="365" value="30"><div class="tipp">0 = sofort aktiv</div></div>
    </div>
    <button type="submit">Betrieb anlegen</button>
  </form>

<?php elseif ($ansicht === 'betrieb' && $detail !== null): ?>

  <p class="zurueck"><a href="/anbieter/">← Alle Betriebe</a></p>
  <h1><?= e($detail['name']) ?> <?= statusMarke($detail['status'], $detail['testphase_bis']) ?></h1>
  <?php $u = rtrim($config['app']['url'], '/'); $wc = $db->rawOne('SELECT domain, aktiv FROM website_config WHERE betrieb_id = ?', [(int) $detail['id']]); ?>
  <div class="bereiche klein-k">
    <a href="<?= e($u) ?>/admin/" target="_blank"><b>Büro</b><span>Login <?= e($detail['inhaber_login'] ?? 'Inhaber') ?></span></a>
    <a href="<?= e($u) ?>/app/" target="_blank"><b>Mitarbeiter-App</b><span><?= (int) $detail['mitarbeiter_aktiv'] ?> Mitarbeiter</span></a>
    <a href="<?= e($u) ?>/besichtigung/" target="_blank"><b>Besichtigung</b><span>mit Büro-Zugang</span></a>
    <a href="<?= e($u) ?>/portal/" target="_blank"><b>Kundenportal</b><span>Zugänge in der Kundenakte</span></a>
    <a href="<?= e($u) ?>/index.php?betrieb=<?= e($detail['slug']) ?>" target="_blank"><b>Website-Vorschau</b><span><?= $wc && $wc['domain'] ? e($wc['domain']) . ((int) $wc['aktiv'] === 1 ? ' · aktiv' : ' · nicht freigeschaltet') : 'keine Domain eingetragen' ?></span></a>
  </div>

  <?php if ($istSuper): $mas = $db->rawAll("SELECT b.id, m.vorname, m.nachname, m.personalnummer FROM benutzer b JOIN mitarbeiter m ON m.id = b.mitarbeiter_id WHERE b.betrieb_id = ? AND b.rolle = 'mitarbeiter' AND b.aktiv = 1 ORDER BY m.nachname", [(int) $detail['id']]); $kus = $db->rawAll("SELECT b.id, k.firmenname FROM benutzer b JOIN kunden k ON k.id = b.kunde_id WHERE b.betrieb_id = ? AND b.rolle = 'kunde' AND b.aktiv = 1 ORDER BY k.firmenname", [(int) $detail['id']]); ?>
  <div class="formular direkt"><h2 class="erste">Direkt öffnen — ohne Login</h2>
    <p class="unter">Sie sind Superuser: Büro, App und Kundenportal dieses Betriebs öffnen sich sofort in einem neuen Tab, ohne Zugangsdaten. Jeder Zugriff wird im Protokoll des Betriebs vermerkt.</p>
    <div class="direkt-zeile">
      <form method="post" target="_blank"><input type="hidden" name="aktion" value="direkt"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="ziel" value="buero"><button type="submit">Büro als Inhaber</button><div class="tipp">Auch Besichtigung (gleiche Anmeldung)</div></form>
      <form method="post" target="_blank"><input type="hidden" name="aktion" value="direkt"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="ziel" value="app"><div style="display:flex;gap:6px"><select name="benutzer_id" <?= $mas === [] ? 'disabled' : '' ?>><?php foreach ($mas as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['vorname'] . ' ' . $m['nachname'] . ' (' . $m['personalnummer'] . ')') ?></option><?php endforeach; ?><?php if ($mas === []): ?><option>keine Mitarbeiter</option><?php endif; ?></select><button type="submit" class="zweit" <?= $mas === [] ? 'disabled' : '' ?>>App</button></div><div class="tipp">Mitarbeiter-App als diese Person</div></form>
      <form method="post" target="_blank"><input type="hidden" name="aktion" value="direkt"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="ziel" value="portal"><div style="display:flex;gap:6px"><select name="benutzer_id" <?= $kus === [] ? 'disabled' : '' ?>><?php foreach ($kus as $k): ?><option value="<?= (int) $k['id'] ?>"><?= e($k['firmenname']) ?></option><?php endforeach; ?><?php if ($kus === []): ?><option>kein Portalzugang</option><?php endif; ?></select><button type="submit" class="zweit" <?= $kus === [] ? 'disabled' : '' ?>>Portal</button></div><div class="tipp">Kundenportal als dieser Kunde</div></form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($detail['status'] === 'test'): $sz = $db->rawOne('SELECT * FROM anbieter_schnellzugang WHERE betrieb_id = ? AND laeuft_ab > NOW()', [(int) $detail['id']]); ?>
  <div class="formular schnellzugang">
    <h2 class="erste">Schnellzugang zum Testen</h2>
    <?php if (!empty($klartextZugang) && $bid === (int) $detail['id']): $link = $u . '/admin/?zugang=' . $klartextZugang;
      $qrsvg = \App\Totp::qrSvg($link); ?>
      <div class="sz-neu"><div class="sz-qr"><?= $qrsvg ?></div>
        <div><p>QR-Code scannen oder Link öffnen — führt direkt ins Büro, ohne Benutzername oder Passwort. Zum eigenen Testen oder zum Weitergeben an eine Kollegin oder einen Kollegen.</p>
          <div class="sz-link"><code><?= e($link) ?></code></div>
          <p class="tipp">Gültig 7 Tage, mehrfach nutzbar von mehreren Personen gleichzeitig. Nur für diesen Testbetrieb — mit dem Wechsel auf „Aktiv" verfällt der Zugang automatisch.</p></div></div>
    <?php elseif ($sz): ?>
      <p>Aktiv seit <?= e(date('d.m.Y', strtotime($sz['erstellt_am']))) ?>, gültig bis <?= e(date('d.m.Y H:i', strtotime($sz['laeuft_ab']))) ?> Uhr<?= (int) $sz['nutzungen'] > 0 ? ' · ' . (int) $sz['nutzungen'] . '× verwendet' . ($sz['letzte_nutzung'] ? ', zuletzt ' . e(date('d.m. H:i', strtotime($sz['letzte_nutzung']))) : '') : ' · noch nicht verwendet' ?>.</p>
      <div class="zeile-knoepfe">
        <form method="post" class="inl" onsubmit="return confirm('Neu erzeugen? Ein zuvor geteilter Link oder QR-Code funktioniert danach nicht mehr.')"><input type="hidden" name="aktion" value="schnellzugang_erzeugen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>"><button type="submit" class="zweit">QR und Link erneut anzeigen</button></form>
        <form method="post" class="inl" onsubmit="return confirm('Schnellzugang widerrufen? Der bisherige Link funktioniert danach nicht mehr.')"><input type="hidden" name="aktion" value="schnellzugang_widerrufen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>"><button type="submit" class="leise">Widerrufen</button></form>
      </div>
      <p class="tipp">Der QR-Code selbst wird aus Sicherheitsgründen nicht erneut angezeigt — der Link oben funktioniert weiterhin, oder neu erzeugen.</p>
    <?php else: ?>
      <p class="unter">Öffnet das Büro dieses Testbetriebs direkt — ohne Benutzername oder Passwort. Praktisch für den eigenen Test am Handy oder um eine Kollegin oder einen Kollegen zum Mittesten einzuladen.</p>
      <form method="post"><input type="hidden" name="aktion" value="schnellzugang_erzeugen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>"><button type="submit">QR-Code und Link erzeugen</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="kennzahlen">
    <div><b><?= (int) $detail['mitarbeiter_aktiv'] ?><small> / <?= (int) $detail['max_mitarbeiter'] ?></small></b><span>Mitarbeiter</span></div>
    <div><b><?= (int) $detail['objekte_aktiv'] ?></b><span>Objekte</span></div>
    <div><b><?= (int) $detail['kunden_aktiv'] ?></b><span>Kunden</span></div>
    <div><b><?= (int) $detail['einsaetze_30_tage'] ?></b><span>Einsätze, 30 Tage</span></div>
  </div>

  <dl class="fakten">
    <dt>Tarif</dt><dd><?= e(ucfirst($detail['tarif'])) ?></dd>
    <dt>Inhaber-Login</dt><dd><?= e($detail['inhaber_login'] ?? '—') ?></dd>
    <dt>E-Mail</dt><dd><?= e($detail['email'] ?? '—') ?></dd>
    <dt>Letzter Login</dt><dd><?= $detail['letzter_login'] ? e(date('d.m.Y H:i', strtotime($detail['letzter_login']))) : 'noch nie' ?></dd>
    <dt>Angelegt</dt><dd><?= e(date('d.m.Y', strtotime($detail['erstellt_am']))) ?></dd>
    <dt>AV-Vertrag</dt><dd><?= $detail['av_vertrag_akzeptiert_am'] ? 'akzeptiert am ' . e(date('d.m.Y', strtotime($detail['av_vertrag_akzeptiert_am']))) : '<span class="warn-text">noch nicht akzeptiert</span>' ?></dd>
  </dl>

  <?php if ($istSuper): ?>
  <div class="aktionen">
    <form method="post"><input type="hidden" name="aktion" value="status"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>">
      <label>Status</label>
      <select name="status"><?php foreach (['test' => 'Testphase', 'aktiv' => 'Aktiv', 'gekuendigt' => 'Gekündigt'] as $k => $v): ?><option value="<?= $k ?>" <?= $detail['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      <button type="submit">Setzen</button>
    </form>
    <form method="post"><input type="hidden" name="aktion" value="tarif"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>">
      <label>Tarif</label>
      <select name="tarif"><?php foreach (['basis' => 'Basis', 'plus' => 'Plus', 'pro' => 'Pro'] as $k => $v): ?><option value="<?= $k ?>" <?= $detail['tarif'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      <button type="submit">Setzen</button>
    </form>
    <form method="post"><input type="hidden" name="aktion" value="testphase"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>">
      <label>Testphase verlängern</label>
      <input name="tage" type="number" min="1" max="365" value="14" class="kurz"> Tage
      <button type="submit">Verlängern</button>
    </form>
    <form method="post" onsubmit="return confirm('Neues Inhaber-Passwort setzen? Das alte gilt dann nicht mehr.')"><input type="hidden" name="aktion" value="passwort"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>">
      <label>Inhaber-Passwort</label>
      <button type="submit" class="zweit">Neu setzen</button>
    </form>
  </div>

  <h2>Vertrag und Preis</h2>
  <form method="post" class="formular vertrag">
    <input type="hidden" name="aktion" value="vertrag"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>">
    <div class="drei">
      <div class="g"><label>Preis / Monat netto</label><input name="preis_monat" value="<?= e(number_format((float) $vertrag['preis_monat'], 2, ',', '')) ?>" inputmode="decimal"></div>
      <div class="g"><label>Rabatt %</label><input name="rabatt_prozent" value="<?= e(number_format((float) $vertrag['rabatt_prozent'], 2, ',', '')) ?>" inputmode="decimal"></div>
      <div class="g"><label>USt %</label>
        <select name="ust_satz"><?php foreach ([20, 0] as $u): ?><option value="<?= $u ?>" <?= (int) $vertrag['ust_satz'] === $u ? 'selected' : '' ?>><?= $u ?> %<?= $u === 0 ? ' (Kleinunternehmer)' : '' ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="drei">
      <div class="g"><label><input type="checkbox" name="website_modul" value="1" <?= (int) $vertrag['website_modul'] === 1 ? 'checked' : '' ?>> Website-Modul</label><input name="website_preis_monat" value="<?= e(number_format((float) $vertrag['website_preis_monat'], 2, ',', '')) ?>" inputmode="decimal" placeholder="€ / Monat"></div>
      <div class="g"><label>Einrichtung einmalig</label><input name="einrichtung_einmalig" value="<?= e(number_format((float) ($vertrag['einrichtung_einmalig'] ?? 0), 2, ',', '')) ?>" inputmode="decimal" placeholder="0,00"><div class="tipp"><?= (int) ($vertrag['einrichtung_verrechnet'] ?? 0) === 1 ? 'bereits verrechnet' : 'kommt auf die nächste Rechnung; bei Pro im Preis enthalten (0 lassen)' ?></div></div>
      <div class="g"><label>Zahlungsweise</label>
        <select name="zahlungsweise"><option value="monatlich" <?= $vertrag['zahlungsweise'] === 'monatlich' ? 'selected' : '' ?>>monatlich</option><option value="jaehrlich" <?= $vertrag['zahlungsweise'] === 'jaehrlich' ? 'selected' : '' ?>>jährlich</option></select></div>
      <div class="g"><label>Zahlungsart</label>
        <select name="zahlungsart"><option value="ueberweisung" <?= $vertrag['zahlungsart'] === 'ueberweisung' ? 'selected' : '' ?>>Überweisung</option><option value="sepa" <?= $vertrag['zahlungsart'] === 'sepa' ? 'selected' : '' ?>>SEPA-Lastschrift</option></select></div>
    </div>
    <div class="drei">
      <div class="g"><label>Vertragsbeginn</label><input name="vertragsbeginn" type="date" value="<?= e($vertrag['vertragsbeginn'] ?? '') ?>"></div>
      <div class="g"><label>Nächste Abrechnung</label><input name="naechste_abrechnung" type="date" value="<?= e($vertrag['naechste_abrechnung'] ?? '') ?>"></div>
      <div class="g"><label>Kündigung zum</label><input name="kuendigung_zum" type="date" value="<?= e($vertrag['kuendigung_zum'] ?? '') ?>"></div>
    </div>
    <div class="zwei">
      <div class="g"><label>Rechnungs-E-Mail</label><input name="rechnungs_email" type="email" value="<?= e($vertrag['rechnungs_email'] ?? '') ?>"></div>
      <div class="g"><label>UID des Betriebs</label><input name="uid_nummer" value="<?= e($vertrag['uid_nummer'] ?? '') ?>"></div>
    </div>
    <div class="g"><label>Rechnungsadresse</label><input name="rechnungsadresse" value="<?= e($vertrag['rechnungsadresse'] ?? '') ?>" placeholder="Firma, Straße, PLZ Ort"></div>
    <div class="g"><label>Vereinbarungen</label><textarea name="notizen" rows="2"><?= e($vertrag['notizen'] ?? '') ?></textarea></div>
    <div class="summe">Monatsbetrag netto: <b><?= geld((float) $detail['monatsbetrag_netto']) ?></b> <span class="klein">· brutto <?= geld((float) $detail['monatsbetrag_netto'] * (1 + (float) $vertrag['ust_satz'] / 100)) ?></span></div>
    <button type="submit">Vertrag speichern</button></form>
      <form method="post" target="_blank" style="display:inline"><input type="hidden" name="aktion" value="vertrag_pdf"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>"><button type="submit" class="zweit">Vertrag als PDF zum Unterschreiben</button><div class="tipp" style="margin-top:6px">Enthält AGB und Auftragsverarbeitungsvertrag als Anlagen. Zweimal ausdrucken, beide unterschreiben, je ein Exemplar.</div>
  </form>

  <h2>Rechnungen</h2>
  <form method="post" class="inline-form">
    <input type="hidden" name="aktion" value="rechnung_erstellen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>">
    <button type="submit">Rechnung für nächsten Zeitraum erstellen</button>
    <span class="klein">Zeitraum ab <?= $vertrag['naechste_abrechnung'] ? e(date('d.m.Y', strtotime($vertrag['naechste_abrechnung']))) : '— (im Vertrag eintragen)' ?>, fällig 14 Tage nach Ausstellung</span>
  </form>
  <?php if ($rechnungen === []): ?><p class="leer">Noch keine Rechnung.</p><?php else: ?>
  <table class="rechnungen">
    <thead><tr><th>Nummer</th><th>Datum</th><th>Zeitraum</th><th class="r">Brutto</th><th>Status</th><th>Versand</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rechnungen as $r): $ueber = $r['status'] === 'offen' && $r['faellig_am'] < date('Y-m-d'); ?>
      <tr class="<?= $r['status'] === 'storniert' ? 'aus' : '' ?>">
        <td><?= e($r['rechnungsnummer']) ?><?= $r['storno_von_id'] ? '<div class="klein">Storno</div>' : '' ?></td>
        <td><?= e(date('d.m.Y', strtotime($r['rechnungsdatum']))) ?></td>
        <td class="klein"><?= e(date('d.m.', strtotime($r['zeitraum_von']))) ?> – <?= e(date('d.m.Y', strtotime($r['zeitraum_bis']))) ?></td>
        <td class="r"><?= geld((float) $r['brutto']) ?></td>
        <td><?= $r['status'] === 'bezahlt' ? '<span class="marke-ok">bezahlt' . ($r['bezahlt_am'] ? ' ' . e(date('d.m.', strtotime($r['bezahlt_am']))) : '') . '</span>' : ($r['status'] === 'storniert' ? '<span class="marke-aus">storniert</span>' : ($ueber ? '<span class="marke-rot">überfällig</span>' : '<span class="marke-test">offen</span>')) ?></td>
        <td class="klein"><?php $ms = $mailStatus[(int) $r['id']] ?? null; echo $ms === null ? '—' : ($ms['status'] === 'gesendet' ? 'gesendet ' . e(date('d.m.', strtotime($ms['gesendet_am']))) : '<span class="warn-text">Fehler</span>'); ?></td>
        <td class="r nowrap">
          <a href="/anbieter/?s=pdf&amp;id=<?= (int) $r['id'] ?>" target="_blank" class="knopf-link">PDF</a>
          <form method="post" class="inl"><input type="hidden" name="aktion" value="rechnung_senden"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="rechnung_id" value="<?= (int) $r['id'] ?>"><button type="submit" class="mini zweit">Senden</button></form>
          <?php if ($r['status'] === 'offen'): ?>
            <form method="post" class="inl"><input type="hidden" name="aktion" value="rechnung_bezahlt"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="rechnung_id" value="<?= (int) $r['id'] ?>"><input type="date" name="bezahlt_am" value="<?= date('Y-m-d') ?>" class="datum-kurz"> <button type="submit" class="mini">Bezahlt</button></form>
            <form method="post" class="inl" onsubmit="return confirm('Rechnung stornieren? Es wird eine Gegenrechnung erzeugt.')"><input type="hidden" name="aktion" value="rechnung_storno"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="rechnung_id" value="<?= (int) $r['id'] ?>"><button type="submit" class="mini zweit">Storno</button></form>
            <?php if ($detail['status'] === 'gekuendigt'): ?>
  <div class="formular" style="margin-top:22px;border-color:#E6B8B3">
    <h2 class="erste" style="color:#A32218">Betriebsdaten endgültig löschen</h2>
    <?php if (!empty($detail['geloescht_am'])): ?><p>Gelöscht am <?= e(date('d.m.Y H:i', strtotime($detail['geloescht_am']))) ?>. Nur der Betriebsstamm und deine Rechnungen sind erhalten.</p>
    <?php else: ?>
    <p>Gekündigt am <?= e(!empty($detail['gekuendigt_am']) ? date('d.m.Y', strtotime($detail['gekuendigt_am'])) : '—') ?>. Laut AV-Vertrag werden die Daten 30 Tage nach Vertragsende gelöscht. Vorher hat der Betrieb den Datenexport im Büro; danach ist nichts wiederherstellbar. Deine Rechnungen an den Betrieb bleiben 7 Jahre erhalten.</p>
    <form method="post" onsubmit="return confirm('Wirklich alle Daten dieses Betriebs löschen? Das ist endgültig.')"><input type="hidden" name="aktion" value="purge"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>">
      <div class="g" style="max-width:260px"><label>Zur Bestätigung LÖSCHEN eingeben</label><input name="bestaetigung" autocomplete="off"></div>
      <button type="submit" class="gefahr">Endgültig löschen</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php elseif ($r['status'] === 'bezahlt' && !$r['storno_von_id']): ?>
            <form method="post" class="inl"><input type="hidden" name="aktion" value="rechnung_offen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="rechnung_id" value="<?= (int) $r['id'] ?>"><button type="submit" class="mini zweit">Wieder offen</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php else: ?><p class="tipp" style="margin:14px 0 22px">Verträge, Rechnungen, Status und Löschung sind dem Superuser vorbehalten.</p><?php endif; ?>

  <h2>Notizen</h2>
  <?php if ($istSuper): ?><form method="post" class="notizform">
    <input type="hidden" name="aktion" value="notiz"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="betrieb_id" value="<?= (int) $detail['id'] ?>">
    <textarea name="notiz" rows="3" placeholder="Gespräch, Absprache, Supportfall …" required></textarea>
    <button type="submit">Speichern</button>
  </form><?php endif; ?>
  <?php if ($notizen === []): ?><p class="leer">Noch keine Notizen.</p><?php endif; ?>
  <?php foreach ($notizen as $n): ?>
    <div class="notiz"><div class="meta"><?= e(date('d.m.Y H:i', strtotime($n['erstellt_am']))) ?> · <?= e($n['von'] ?? '') ?></div><?= nl2br(e($n['notiz'])) ?></div>
  <?php endforeach; ?>

<?php else: ?>

  <?php
    $monateA = App\Report::monate(12); $labelsA = array_map(static fn($m) => mb_substr(App\CustomerInvoice::monatName((int) substr($m, 5)), 0, 3), $monateA);
    $mrrRows = $db->rawAll("SELECT DATE_FORMAT(rechnungsdatum,'%Y-%m') m, SUM(netto) s FROM anbieter_rechnungen WHERE status <> 'storniert' AND rechnungsdatum >= ? GROUP BY m", [$monateA[0] . '-01']); $mrrMap = []; foreach ($mrrRows as $r) { $mrrMap[$r['m']] = (float) $r['s']; }
    $neuRows = $db->rawAll("SELECT DATE_FORMAT(erstellt_am,'%Y-%m') m, COUNT(*) n FROM betriebe WHERE erstellt_am >= ? GROUP BY m", [$monateA[0] . '-01']); $neuMap = []; foreach ($neuRows as $r) { $neuMap[$r['m']] = (int) $r['n']; }
    $kuendRows = []; try { $kuendRows = $db->rawAll("SELECT DATE_FORMAT(v.kuendigung_zum,'%Y-%m') m, COUNT(*) n FROM anbieter_vertraege v WHERE v.kuendigung_zum IS NOT NULL AND v.kuendigung_zum >= ? GROUP BY m", [$monateA[0] . '-01']); } catch (\Throwable) {} $kuendMap = []; foreach ($kuendRows as $r) { $kuendMap[$r['m']] = (int) $r['n']; }
    $mrr = array_map(static fn($m) => round($mrrMap[$m] ?? 0, 2), $monateA); $neu = array_map(static fn($m) => $neuMap[$m] ?? 0, $monateA); $kuend = array_map(static fn($m) => $kuendMap[$m] ?? 0, $monateA);
  ?>
  <div class="kopfzeile" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><h1 style="margin:0">Betriebe</h1>
    <?php if ($istSuper): ?><form method="post" class="inl"><input type="hidden" name="aktion" value="demo"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="zweit" onclick="return confirm('Demo-Betrieb mit Beispieldaten anlegen? Dauert ein paar Sekunden.')">Demo-Betrieb anlegen</button></form><?php endif; ?></div>
  <?php $u = rtrim($config['app']['url'], '/'); ?>
  <?php $faellig = []; try { $faellig = $db->rawAll("SELECT id, name, gekuendigt_am, DATEDIFF(CURDATE(), gekuendigt_am) tage FROM betriebe WHERE status = 'gekuendigt' AND geloescht_am IS NULL AND gekuendigt_am IS NOT NULL ORDER BY gekuendigt_am"); } catch (\Throwable) {} ?>
  <?php if ($faellig !== []): ?><div class="meldung <?= max(array_column($faellig, 'tage')) >= 30 ? 'fehler' : 'hinweis' ?>"><b>Löschfrist nach Vertragsende (AV-Vertrag: 30 Tage):</b>
    <?php foreach ($faellig as $f): ?><div><a href="/anbieter/?s=betrieb&amp;id=<?= (int) $f['id'] ?>"><?= e($f['name']) ?></a> — gekündigt am <?= e(date('d.m.Y', strtotime($f['gekuendigt_am']))) ?>, <?= (int) $f['tage'] >= 30 ? '<b>Frist abgelaufen, jetzt löschen</b>' : 'Löschung in ' . (30 - (int) $f['tage']) . ' Tagen fällig' ?></div><?php endforeach; ?></div><?php endif; ?>
  <div class="bereiche">
    <a href="<?= e($u) ?>/admin/" target="_blank"><b>Büro</b><span>Dashboard der Betriebe — Login mit Inhaber- oder Bürobenutzer</span><code><?= e($u) ?>/admin/</code></a>
    <a href="<?= e($u) ?>/app/" target="_blank"><b>Mitarbeiter-App</b><span>Personalnummer und PIN — Link für die Reinigungskräfte</span><code><?= e($u) ?>/app/</code></a>
    <a href="<?= e($u) ?>/besichtigung/" target="_blank"><b>Besichtigung</b><span>Wizard am Tablet — Login mit Büro-Zugang</span><code><?= e($u) ?>/besichtigung/</code></a>
    <a href="<?= e($u) ?>/portal/" target="_blank"><b>Kundenportal</b><span>Für die Kunden der Betriebe — Zugang aus der Kundenakte</span><code><?= e($u) ?>/portal/</code></a>
    <a href="<?= e($u) ?>/" target="_blank"><b>Vertriebsseite</b><span>Deine Seite auf der Hauptdomain — Texte aus den Einstellungen</span><code><?= e($u) ?>/</code></a>
    <a href="<?= e($u) ?>/anbieter/?s=einstellungen"><b>Cronjob</b><span>URL für easyname, alle 15 Minuten — unter Einstellungen</span><code><?= e($u) ?>/cron/?token=…</code></a>
  </div>
  <div class="kennzahlen">
    <div><b><?= geld($kz['mrr_netto']) ?></b><span>Monatsumsatz netto, aktive Betriebe</span></div>
    <div><b><?= geld($kz['offen_brutto']) ?><small> · <?= $kz['offen_anzahl'] ?></small></b><span>offene Rechnungen</span></div>
    <div class="<?= $kz['ueberfaellig_anzahl'] > 0 ? 'rot' : '' ?>"><b><?= geld($kz['ueberfaellig_brutto']) ?><small> · <?= $kz['ueberfaellig_anzahl'] ?></small></b><span>überfällig</span></div>
    <div class="<?= $kz['abrechnung_faellig'] > 0 ? 'gelb' : '' ?>"><b><?= $kz['abrechnung_faellig'] ?></b><span>Abrechnungen fällig</span></div>
  </div>
  <div class="kennzahlen klein-kz">
    <div><b><?= $summe['gesamt'] ?></b><span>Betriebe gesamt</span></div>
    <div><b><?= $summe['aktiv'] ?></b><span>aktiv</span></div>
    <div><b><?= $summe['test'] ?></b><span>Testphase</span></div>
    <div><b><?= $summe['mitarbeiter'] ?></b><span>Mitarbeiter gesamt</span></div>
  </div>

  <?php if ($betriebe === []): ?>
    <p class="leer">Noch kein Betrieb angelegt. <a href="/anbieter/?s=neu">Ersten Betrieb anlegen</a></p>
  <?php else: ?>
  <div class="zweispalt" style="margin-bottom:22px">
    <div class="bericht-block"><h2>Abgerechneter Umsatz netto je Monat</h2><?= App\Report::balken([['name' => 'Umsatz', 'werte' => $mrr]], $labelsA, '€', 560, 170) ?></div>
    <div class="bericht-block"><h2>Betriebe: neu und gekündigt</h2><?= App\Report::balken([['name' => 'neu', 'werte' => $neu], ['name' => 'gekündigt', 'werte' => $kuend]], $labelsA, '', 560, 170) ?></div>
  </div>
  <table>
    <thead><tr><th>Betrieb</th><th>Status</th><th>Tarif</th><th class="r">€ / Monat</th><th>Zahlung</th><th>Nächste Abrechnung</th><th class="r">Mitarbeiter</th><th>Letzter Login</th></tr></thead>
    <tbody>
    <?php foreach ($betriebe as $b): ?>
      <tr class="<?= $b['status'] === 'gekuendigt' ? 'aus' : '' ?>">
        <td><a href="/anbieter/?s=betrieb&amp;id=<?= (int) $b['id'] ?>"><?= e($b['name']) ?></a><div class="klein"><?= e($b['inhaber_login'] ?? '') ?></div></td>
        <td><?= statusMarke($b['status'], $b['testphase_bis']) ?></td>
        <td><?= e(ucfirst($b['tarif'])) ?><?= (int) $b['website_modul'] === 1 ? '<div class="klein">+ Website</div>' : '' ?></td>
        <td class="r"><?= geld((float) $b['monatsbetrag_netto']) ?><div class="klein"><?= $b['zahlungsweise'] === 'jaehrlich' ? 'jährlich' : 'monatlich' ?></div></td>
        <td><?= zahlungMarke((int) $b['ueberfaellig_anzahl'], (float) $b['offen_brutto']) ?></td>
        <td><?= $b['naechste_abrechnung'] ? e(date('d.m.Y', strtotime($b['naechste_abrechnung']))) : '<span class="klein">—</span>' ?><?= ($b['naechste_abrechnung'] && $b['naechste_abrechnung'] <= date('Y-m-d') && $b['status'] === 'aktiv') ? '<div class="klein warn-text">jetzt abrechnen</div>' : '' ?></td>
        <td class="r"><?= (int) $b['mitarbeiter_aktiv'] ?> <span class="klein">/ <?= (int) $b['max_mitarbeiter'] ?></span></td>
        <td><?= $b['letzter_login'] ? e(date('d.m.Y', strtotime($b['letzter_login']))) : '<span class="klein">noch nie</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

<?php endif; ?>
</main>
<?php
seiteFuss();

// =====================================================================
// Hilfsfunktionen für die Ausgabe
// =====================================================================

function geld(float $v): string
{
    return number_format($v, 2, ',', '.') . ' €';
}

function zahlungMarke(int $ueberfaellig, float $offen): string
{
    if ($ueberfaellig > 0) {
        return '<span class="marke-rot">überfällig</span>';
    }
    if ($offen > 0) {
        return '<span class="marke-test">offen ' . e(geld($offen)) . '</span>';
    }
    return '<span class="marke-ok">bezahlt</span>';
}

function statusMarke(string $status, ?string $testBis): string
{
    return match ($status) {
        'aktiv'      => '<span class="marke-ok">aktiv</span>',
        'gekuendigt' => '<span class="marke-aus">gekündigt</span>',
        default      => '<span class="marke-test">Test bis ' . e($testBis ? date('d.m.', strtotime($testBis)) : '?') . '</span>',
    };
}

function meldungAnzeigen(?array $m): void
{
    if ($m === null) {
        return;
    }
    echo '<div class="meldung ' . e($m['typ']) . '">' . e($m['text']);
    if (!empty($m['zugang'])) {
        echo '<div class="zugang">';
        if (isset($m['zugang']['login'])) {
            echo '<div>Benutzername: <code>' . e($m['zugang']['login']) . '</code></div>';
        }
        echo '<div>Passwort: <code>' . e($m['zugang']['passwort']) . '</code></div>';
        echo '<div class="klein">Wird nicht erneut angezeigt. Der Inhaber sollte es nach dem ersten Login ändern.</div>';
        echo '</div>';
    }
    if (!empty($m['demo'])) {
        global $config; $d = $m['demo']; $u = rtrim($config['app']['url'], '/');
        echo '<div class="zugang demo-zugang">';
        echo '<div><b>Büro</b> <a href="' . e($u) . '/admin/" target="_blank">' . e($u) . '/admin/</a><br>Inhaber: <code>' . e($d['inhaber']) . '</code> · Bürobenutzer: <code>' . e($d['buero']) . '</code> · Passwort: <code>' . e($d['passwort']) . '</code></div>';
        echo '<div><b>Besichtigung</b> <a href="' . e($u) . '/besichtigung/" target="_blank">' . e($u) . '/besichtigung/</a> — mit dem Büro-Login</div>';
        echo '<div><b>Mitarbeiter-App</b> <a href="' . e($u) . '/app/" target="_blank">' . e($u) . '/app/</a><br>Personalnummer <code>1001</code> bis <code>1004</code> · PIN <code>' . e($d['pin']) . '</code></div>';
        echo '<div><b>Kundenportal</b> <a href="' . e($u) . '/portal/" target="_blank">' . e($u) . '/portal/</a><br>Benutzer: <code>' . e($d['kunde']) . '</code> · Passwort: <code>' . e($d['passwort']) . '</code></div>';
        echo '<div><b>Website-Vorschau</b> <a href="' . e($u) . '/index.php?betrieb=' . e($d['slug']) . '" target="_blank">' . e($u) . '/index.php?betrieb=' . e($d['slug']) . '</a></div>';
        echo '<div class="klein">Alle Passwörter sind fest und bekannt — nur für Demo und Ansicht, nie für echte Daten. Den Demo-Betrieb später über den Status „gekündigt“ stilllegen.</div>';
        echo '</div>';
    }
    echo '</div>';
}

function seiteKopf(string $titel): void
{
    ?><!DOCTYPE html>
<html lang="de-AT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titel) ?> — Anbieter</title>
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,700,800|dm-sans:400,500,600">
<style>
:root{--haus:#0B5D4A;--haus2:#14876B;--papier:#F6F7F4;--feld:#E6EFE9;--text:#13201B;--text2:#5C6B64;--linie:#DDE3DE;--rot:#B3261E;--rotf:#FDECEA;--gelb:#7A5A00;--gelbf:#FFF4D6}
*{box-sizing:border-box;margin:0;padding:0}
.gefahr{background:#A32218!important;color:#fff!important}.meldung.hinweis{background:#FFF7E6;border-color:#E9D5A8;color:#5A4300}
.demo-zugang div{margin-bottom:8px;line-height:1.6}.demo-zugang code{background:#fff;border:1px solid var(--linie);border-radius:4px;padding:1px 6px}
.bereiche{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:22px}.bereiche a{display:block;background:#fff;border:1px solid var(--linie);border-radius:10px;padding:14px 16px;text-decoration:none;color:var(--text)}.bereiche a:hover{border-color:var(--haus)}.bereiche b{display:block;font-size:15.5px;margin-bottom:3px}.bereiche span{display:block;font-size:13px;color:var(--text2);line-height:1.45}.bereiche code{display:block;font-size:12px;color:var(--haus);margin-top:8px;word-break:break-all}.bereiche.klein-k{grid-template-columns:repeat(5,1fr);margin:12px 0 18px}.bereiche.klein-k a{padding:10px 12px}@media(max-width:900px){.bereiche,.bereiche.klein-k{grid-template-columns:1fr 1fr}}
.direkt-zeile{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.direkt-zeile select{margin:0;max-width:220px}.direkt-zeile button{margin:0;white-space:nowrap}@media(max-width:900px){.direkt-zeile{grid-template-columns:1fr}}
.schnellzugang .sz-neu{display:flex;gap:20px;align-items:flex-start}.schnellzugang .sz-qr{flex:none;background:#fff;border:1px solid var(--linie);border-radius:10px;padding:10px}.schnellzugang .sz-qr svg{width:150px;height:150px;display:block}.schnellzugang .sz-link{background:var(--papier);border-radius:8px;padding:10px 12px;margin:10px 0;word-break:break-all;font-size:13px}@media(max-width:700px){.schnellzugang .sz-neu{flex-direction:column}}
.bericht-block{background:#fff;border:1px solid var(--linie);border-radius:10px;padding:16px 18px}.bericht-block h2{margin:0 0 10px;font-size:15px}.diagramm{width:100%;height:auto;display:block}.legende{display:flex;gap:16px;font-size:13px;color:var(--text2);margin-top:6px}.legende i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:6px;vertical-align:-1px}.zweispalt{display:grid;grid-template-columns:1fr 1fr;gap:18px}@media(max-width:900px){.zweispalt{grid-template-columns:1fr}}
.qr-setup svg{width:200px;height:200px;display:block;border:1px solid var(--linie);border-radius:8px;padding:6px;background:#fff}.backup-codes{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px}.backup-codes code{font-size:16px;padding:8px 12px;background:var(--papier);border:1px solid var(--linie);border-radius:6px;text-align:center}.marke-rot{background:#FDECEA;color:#A32218;font-size:12.5px;font-weight:700;padding:3px 9px;border-radius:100px}
body{font:15.5px/1.6 'DM Sans',system-ui,sans-serif;background:var(--papier);color:var(--text);-webkit-font-smoothing:antialiased}
h1,h2,.kennzahlen b,.marke{font-family:'Bricolage Grotesque',sans-serif;letter-spacing:-.025em}
a{color:var(--haus)}
code{font:13.5px ui-monospace,Menlo,Consolas,monospace;background:#fff;border:1px solid var(--linie);border-radius:5px;padding:2px 7px}
.kopf{background:#fff;border-bottom:1px solid var(--linie);display:flex;align-items:center;gap:32px;padding:0 32px;height:58px}
.kopf .marke{font-weight:800;font-size:18px}.kopf .marke a{color:var(--text);text-decoration:none}
.kopf nav{display:flex;gap:4px;flex:1}
.kopf nav a{text-decoration:none;color:var(--text2);padding:6px 12px;border-radius:6px;font-weight:500;font-size:14.5px}
.kopf nav a.aktiv,.kopf nav a:hover{background:var(--feld);color:var(--haus)}
.kopf .inline{display:flex;align-items:center;gap:14px}.wer{color:var(--text2);font-size:14px}
main{max-width:1100px;margin:0 auto;padding:36px 32px 60px}
h1{font-size:30px;font-weight:800;margin-bottom:8px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
h2{font-size:20px;font-weight:700;margin:36px 0 14px}
.unter{color:var(--text2);margin-bottom:24px;max-width:60ch}
.zurueck{margin-bottom:14px;font-size:14px}.zurueck a{text-decoration:none}
.kennzahlen{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:18px 0 28px}
.kennzahlen div{background:#fff;border:1px solid var(--linie);border-radius:10px;padding:16px 18px}
.kennzahlen b{display:block;font-size:30px;font-weight:800;line-height:1;color:var(--haus)}.kennzahlen b small{font-size:15px;color:var(--text2);font-weight:600}
.kennzahlen span{font-size:13.5px;color:var(--text2)}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--linie);border-radius:10px;overflow:hidden}
th{text-align:left;font-size:12.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--text2);padding:12px 14px;border-bottom:1px solid var(--linie);background:var(--papier)}
td{padding:13px 14px;border-bottom:1px solid var(--linie);vertical-align:top}
tr:last-child td{border-bottom:0}td a{font-weight:600;text-decoration:none}
tr.aus td{opacity:.55}.r{text-align:right}.klein{font-size:12.5px;color:var(--text2)}
.marke-ok,.marke-test,.marke-aus{display:inline-block;font-size:12.5px;font-weight:600;padding:3px 10px;border-radius:100px;white-space:nowrap}
.marke-ok{background:var(--feld);color:var(--haus)}.marke-rot{background:var(--rotf);color:var(--rot)}
.kennzahlen div.rot b{color:var(--rot)}.kennzahlen div.gelb b{color:var(--gelb)}
.klein-kz{margin-top:-14px}.klein-kz b{font-size:22px;color:var(--text)}
.drei{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.vertrag{max-width:820px}.vertrag textarea{width:100%}.summe{margin:6px 0 18px;font-size:15px}
.inline-form{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap}
.rechnungen td{vertical-align:middle}.inl{display:inline-flex;gap:6px;align-items:center;margin-left:6px}
.mini{padding:5px 10px;font-size:13px}
.knopf-link{display:inline-block;padding:5px 10px;font-size:13px;font-weight:600;border:1.5px solid var(--linie);border-radius:7px;text-decoration:none;color:var(--text);margin-left:6px}.knopf-link:hover{border-color:var(--haus)}
.punkt{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--rot);vertical-align:middle;margin-left:2px}
.formular h2{font-size:15px;text-transform:uppercase;letter-spacing:.05em;color:var(--text2);margin:22px 0 12px;padding-top:18px;border-top:1px solid var(--linie)}.formular h2.erste{border-top:0;padding-top:0;margin-top:0}.datum-kurz{padding:5px 8px;font-size:13px;width:135px}.nowrap{white-space:nowrap}.marke-test{background:var(--gelbf);color:var(--gelb)}.marke-aus{background:#EEE;color:#666}
.fakten{display:grid;grid-template-columns:160px 1fr;gap:8px 16px;background:#fff;border:1px solid var(--linie);border-radius:10px;padding:18px 20px;margin-bottom:24px;font-size:14.5px}
.fakten dt{color:var(--text2)}.fakten dd{font-weight:500}.warn-text{color:var(--rot);font-weight:600}
.aktionen{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
.aktionen form{background:#fff;border:1px solid var(--linie);border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.aktionen label{font-size:13.5px;color:var(--text2);width:100%}
label{display:block;font-weight:600;font-size:14px;margin-bottom:5px}
input,select,textarea{border:1.5px solid var(--linie);border-radius:7px;padding:10px 12px;font:inherit;font-size:15px;background:#fff;color:var(--text)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--haus)}
input.kurz{width:80px}
.formular{background:#fff;border:1px solid var(--linie);border-radius:12px;padding:26px 28px 30px;max-width:640px}
.formular input,.formular select{width:100%}.g{margin-bottom:16px}.zwei{display:grid;grid-template-columns:1fr 1fr;gap:14px}.tipp{font-size:13px;color:var(--text2);margin-top:5px}
button{background:var(--haus);color:#fff;border:0;border-radius:7px;padding:10px 18px;font:inherit;font-size:14.5px;font-weight:600;cursor:pointer}
button:hover{background:var(--haus2)}button.zweit{background:#fff;color:var(--text);border:1.5px solid var(--linie)}button.zweit:hover{border-color:var(--haus);background:#fff}
button.leise{background:transparent;color:var(--text2);padding:6px 10px}button.leise:hover{background:var(--feld);color:var(--haus)}
.meldung{border-radius:9px;padding:14px 18px;margin-bottom:22px;font-size:15px}
.meldung.ok{background:var(--feld);border:1px solid #BFD9CC}.meldung.fehler{background:var(--rotf);border:1px solid #F5C6C2;color:#7A1C16}
.zugang{margin-top:10px;display:grid;gap:6px}
.notizform{display:grid;gap:10px;max-width:640px;margin-bottom:20px}.notizform textarea{width:100%;resize:vertical}.notizform button{justify-self:start}
.notiz{background:#fff;border:1px solid var(--linie);border-radius:9px;padding:12px 16px;margin-bottom:10px;max-width:640px;font-size:14.5px}.notiz .meta{font-size:12.5px;color:var(--text2);margin-bottom:5px}
.leer{color:var(--text2);padding:30px 0}
.anmelden{max-width:380px;margin:80px auto;background:#fff;border:1px solid var(--linie);border-radius:14px;padding:32px 34px 36px}
.anmelden h1{font-size:26px;margin-bottom:22px}.anmelden input{width:100%;margin-bottom:16px}.anmelden button{width:100%}
@media(max-width:760px){main{padding:22px 16px}.kennzahlen,.aktionen,.zwei,.drei{grid-template-columns:1fr 1fr}.nowrap{white-space:normal}.kopf{padding:0 16px;gap:16px}.fakten{grid-template-columns:1fr}table{font-size:14px}th:nth-child(n+5),td:nth-child(n+5){display:none}}
</style>


<style>
/* ===== Anmeldeseiten: ein Design fuer alle Bereiche ===== */
.anmelden{max-width:420px!important;margin:64px auto!important;background:#fff!important;border:1px solid var(--linie,#DDE3DE)!important;border-radius:18px!important;padding:36px 38px 34px!important;box-shadow:0 30px 60px -30px rgba(11,32,27,.25)!important;font-family:'DM Sans',system-ui,sans-serif}
.anmelden .marke-login{display:flex;align-items:center;gap:10px;margin-bottom:22px;font-size:15px;color:#5C6B64}
.anmelden .marke-login b{position:relative;font-size:22px;letter-spacing:-.02em;color:#13201B;padding-right:16px}
.anmelden .marke-login b svg{position:absolute;right:-1px;top:-5px;width:15px;height:15px}
.anmelden .marke-login span{padding-left:10px;border-left:1px solid var(--linie,#DDE3DE)}
.anmelden h1{font-size:26px!important;letter-spacing:-.02em;margin:0 0 6px!important}
.anmelden>p:first-of-type,.anmelden .unter{color:#5C6B64;font-size:14.5px;margin:0 0 20px}
.anmelden label{display:block;font-weight:600;font-size:13.5px;margin:0 0 6px;color:#13201B}
.anmelden input:not([type=hidden]):not([type=checkbox]),.anmelden .pw-wrap{width:100%!important;box-sizing:border-box}
.anmelden input:not([type=hidden]):not([type=checkbox]){height:48px;padding:0 14px!important;border:1.5px solid var(--linie,#DDE3DE)!important;border-radius:10px!important;font-size:15.5px!important;margin:0 0 16px!important;background:#fff!important;transition:border-color .15s,box-shadow .15s}
.anmelden input:focus{border-color:#0B5D4A!important;box-shadow:0 0 0 3px rgba(11,93,74,.14)!important;outline:none}
.anmelden .pw-wrap{position:relative;display:block;margin:0 0 16px}
.anmelden .pw-wrap input{margin:0!important;padding-right:50px!important}
.anmelden .pw-wrap .pw-auge{position:absolute!important;right:4px!important;left:auto!important;top:4px!important;bottom:4px!important;width:40px!important;height:40px!important;min-height:0!important;line-height:1!important;font-size:0!important;margin:0!important;padding:0!important;display:flex;align-items:center;justify-content:center;background:transparent!important;border:0!important;border-radius:8px;color:#5C6B64;cursor:pointer;box-shadow:none!important}
.anmelden .pw-wrap .pw-auge:hover,.anmelden .pw-wrap .pw-auge.aktiv{color:#0B5D4A;background:rgba(11,93,74,.08)!important}
.anmelden .pw-wrap .pw-auge svg{width:20px;height:20px;pointer-events:none}
.anmelden button[type=submit]:not(.pw-auge){width:100%;height:50px;margin-top:4px;background:#0B5D4A;color:#fff;border:0;border-radius:10px;font-size:16px;font-weight:700;cursor:pointer;transition:background .15s,transform .1s}
.anmelden button[type=submit]:not(.pw-auge):hover{background:#094c3c}.anmelden button[type=submit]:not(.pw-auge):active{transform:translateY(1px)}
.anmelden button.zweit{background:#fff!important;color:#13201B!important;border:1.5px solid var(--linie,#DDE3DE)!important}
.anmelden .meldung{border-radius:10px;padding:12px 14px;font-size:14px;margin-bottom:18px;line-height:1.5}
.anmelden .meldung.fehler{background:#FBE9E7;border:1px solid #E6B8B3;color:#8A2418}.anmelden .meldung.ok{background:#E6EFE9;border:1px solid #C6DACF;color:#0B5D4A}
.anmelden .links{margin-top:18px;text-align:center;font-size:14px;color:#5C6B64;line-height:2}
.anmelden .links a{color:#0B5D4A;font-weight:600;text-decoration:none}.anmelden .links a:hover{text-decoration:underline}
.anmelden .links .trenn{display:block;height:1px;background:var(--linie,#DDE3DE);margin:12px 0}
@media(max-width:520px){.anmelden{margin:20px 14px!important;padding:28px 22px!important;border-radius:14px!important}}
</style>
<script>
document.addEventListener("DOMContentLoaded",function(){
  document.querySelectorAll("input[type=password]").forEach(function(inp){
    if(inp.closest(".pw-wrap"))return;
    var wrap=document.createElement("div");wrap.className="pw-wrap";
    inp.parentNode.insertBefore(wrap,inp);wrap.appendChild(inp);
    var btn=document.createElement("button");btn.type="button";btn.className="pw-auge";btn.setAttribute("aria-label","Passwort anzeigen");btn.tabIndex=-1;
    btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
    wrap.appendChild(btn);
    btn.addEventListener("click",function(){var t=inp.type==="password"?"text":"password";inp.type=t;btn.classList.toggle("aktiv",t==="text");btn.setAttribute("aria-label",t==="text"?"Passwort verbergen":"Passwort anzeigen")});
  });
  // Kein-Login-Kontext (z. B. Konto-Seite): Auge auch dort rechts, ueber Wrapper-Breite
  document.querySelectorAll(".pw-wrap").forEach(function(w){if(!w.closest(".anmelden")){w.style.position="relative";w.style.display="block";var i=w.querySelector("input");if(i){i.style.paddingRight="48px";i.style.boxSizing="border-box";i.style.width="100%"}var b=w.querySelector(".pw-auge");if(b){Object.assign(b.style,{position:"absolute",right:"3px",left:"auto",top:"3px",bottom:"3px",width:"40px",height:"auto",margin:"0",padding:"0",background:"transparent",border:"0",display:"flex",alignItems:"center",justifyContent:"center",cursor:"pointer",color:"#5C6B64",boxShadow:"none"})}}});
});
</script>
</head>
<body>
<?php
}

function seiteFuss(): void
{
    echo "</body></html>";
}
