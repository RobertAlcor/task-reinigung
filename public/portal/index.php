<?php
/**
 * Kundenportal — Sicht des Kunden eines Reinigungsbetriebs.
 *
 * Rolle "kunde". Der Kunde sieht ausschliesslich Daten zu seinen eigenen
 * Objekten: Einsaetze mit Zeiten und Fotos (Nachweis), Rechnungen,
 * und kann Beschwerden und Feedback melden. Mitarbeiter erscheinen mit
 * Vorname und Initial.
 */

declare(strict_types=1);

use App\Auth;
use App\AuthException;
use App\CustomerInvoice;
use App\Database;

$config = require __DIR__ . '/../system/bootstrap.php';
require __DIR__ . '/../admin/_helfer.php';
\App\Response::securityHeaders();

$db      = Database::get();
$meldung = null;
$aktion  = $_POST['aktion'] ?? null;
$seite   = preg_replace('/[^a-z]/', '', (string) ($_GET['s'] ?? 'start')) ?: 'start';
$id      = (int) ($_GET['id'] ?? 0);

if ($aktion === 'logout') {
    Auth::logout();
    weiter('/portal/');
}

// Direktzugang des Anbieter-Superusers
if (isset($_GET['direkt']) && preg_match('/^[a-f0-9]{40}$/', (string) $_GET['direkt']) === 1) {
    $dz = $db->rawOne("SELECT * FROM anbieter_direktzugang WHERE token_hash = ? AND ziel = 'portal' AND verwendet_am IS NULL AND laeuft_ab > NOW()", [hash('sha256', (string) $_GET['direkt'])]);
    if ($dz !== null) {
        $db->raw('UPDATE anbieter_direktzugang SET verwendet_am = NOW() WHERE id = ?', [(int) $dz['id']]);
        $ku = $db->rawOne("SELECT * FROM benutzer WHERE id = ? AND rolle = 'kunde' AND aktiv = 1", [(int) $dz['benutzer_id']]);
        if ($ku !== null) { Auth::loginOhnePasswort($ku); }
    }
    header('Location: /portal/'); exit;
}

$user = Auth::authenticate();
if ($user !== null && $user['rolle'] !== 'kunde') {
    Auth::logout(); $user = null;
    $meldung = ['typ' => 'fehler', 'text' => 'Dieser Zugang ist nicht für das Kundenportal.'];
}

// ---------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------
if ($user === null) {
    if ($aktion === 'login') {
        try {
            $u = Auth::loginWithPassword((string) ($_POST['benutzername'] ?? ''), (string) ($_POST['passwort'] ?? ''));
            if ($u['rolle'] !== 'kunde') { Auth::logout(); throw new AuthException('Dieser Zugang ist nicht für das Kundenportal.'); }
            weiter('/portal/');
        } catch (AuthException $e) {
            $meldung = ['typ' => 'fehler', 'text' => $e->getMessage()];
        }
    }
    if ($aktion === 'vergessen') {
        App\PasswordReset::request((string) ($_POST['email'] ?? ''), ['kunde'], rtrim($config['app']['url'], '/') . '/portal/?reset=', Auth::clientIp());
        $meldung = ['typ' => 'ok', 'text' => 'Wenn zu dieser Adresse ein Zugang gehört, ist eine E-Mail mit dem Link unterwegs.'];
    }
    if (isset($_GET['vergessen']) || $aktion === 'vergessen') {
        kopf('Passwort vergessen');
        ?><div class="anmelden"><div class="marke-login"><b>Takt<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></b><span>Kundenportal</span></div><h1>Passwort vergessen</h1><?php if ($meldung): ?><div class="meldung <?= e($meldung['typ']) ?>"><?= e($meldung['text']) ?></div><?php endif; ?><?php if (!App\Mailer::configured()): ?><div class="meldung fehler">Bitte wenden Sie sich an Ihren Reinigungsbetrieb.</div><?php endif; ?>
          <form method="post"><input type="hidden" name="aktion" value="vergessen"><label for="em">E-Mail-Adresse</label><input id="em" name="email" type="email" required autofocus><button type="submit">Link anfordern</button></form>
          <p style="margin-top:16px;text-align:center"><a href="/portal/" style="font-size:14px">Zurück</a></p></div></body></html><?php
        exit;
    }
    if (isset($_GET['reset']) || $aktion === 'reset') {
        $token = (string) ($_POST['token'] ?? $_GET['reset'] ?? '');
        if ($aktion === 'reset') {
            try { if (($_POST['neu'] ?? '') !== ($_POST['neu2'] ?? '')) { throw new RuntimeException('Die Passwörter stimmen nicht überein.'); } App\PasswordReset::complete($token, ['kunde'], (string) ($_POST['neu'] ?? '')); $meldung = ['typ' => 'ok', 'text' => 'Passwort gesetzt. Bitte anmelden.']; }
            catch (\Throwable $e) { $meldung = ['typ' => 'fehler', 'text' => $e->getMessage()]; }
        }
        if ($aktion !== 'reset' || ($meldung['typ'] ?? '') === 'fehler') {
            if (App\PasswordReset::check($token, ['kunde']) === null && $aktion !== 'reset') { $meldung = ['typ' => 'fehler', 'text' => 'Der Link ist ungültig oder abgelaufen.']; }
            else {
                kopf('Neues Passwort');
                ?><div class="anmelden"><div class="marke-login"><b>Takt<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></b><span>Kundenportal</span></div><h1>Neues Passwort</h1><?php if ($meldung): ?><div class="meldung <?= e($meldung['typ']) ?>"><?= e($meldung['text']) ?></div><?php endif; ?>
                  <form method="post"><input type="hidden" name="aktion" value="reset"><input type="hidden" name="token" value="<?= e($token) ?>"><label for="neu">Neues Passwort</label><input id="neu" name="neu" type="password" required minlength="10" autofocus><label for="neu2">Wiederholen</label><input id="neu2" name="neu2" type="password" required><button type="submit">Passwort setzen</button></form></div></body></html><?php
                exit;
            }
        }
    }
    kopf('Anmelden');
    ?><div class="anmelden"><div class="marke-login"><b>Takt<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></b><span>Kundenportal</span></div><h1>Kundenportal</h1><p class="unter">Zugangsdaten bekommen Sie von Ihrem Reinigungsbetrieb.</p>
      <?php if ($meldung): ?><div class="meldung <?= e($meldung['typ']) ?>"><?= e($meldung['text']) ?></div><?php endif; ?>
      <form method="post"><input type="hidden" name="aktion" value="login">
        <label for="bn">Benutzername</label><input id="bn" name="benutzername" autocomplete="username" required autofocus>
        <label for="pw">Passwort</label><input id="pw" name="passwort" type="password" autocomplete="current-password" required>
        <button type="submit">Anmelden</button></form>
      <div class="links"><a href="/portal/?vergessen">Passwort vergessen?</a></div></div></body></html><?php
    exit;
}

$kundeId = (int) $user['kunde_id'];
$kunde   = $db->find('kunden', $kundeId);
$betrieb = $db->rawOne('SELECT * FROM betriebe WHERE id = ?', [$db->tenant()]);
if ($kunde === null || $betrieb['status'] === 'gekuendigt') {
    Auth::logout();
    kopf('Hinweis'); echo '<div class="anmelden"><h1>Zugang nicht verfügbar</h1><p>Bitte wenden Sie sich an Ihren Reinigungsbetrieb.</p></div></body></html>'; exit;
}
$csrf = Auth::csrfToken();
$meineObjekte = $db->select('objekte', 'kunde_id = ?', [$kundeId], 'ORDER BY name');
$objektIds = array_map(static fn($o) => (int) $o['id'], $meineObjekte);
$inObj = $objektIds === [] ? '0' : implode(',', $objektIds);

/** Prueft, ob ein Einsatz zu einem Objekt des Kunden gehoert. */
$meinEinsatz = static function (int $einsatzId) use ($db, $inObj): ?array {
    return $db->rawOne("SELECT e.*, o.name AS objekt FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id WHERE e.id = ? AND e.betrieb_id = ? AND e.objekt_id IN ({$inObj})", [$einsatzId, $db->tenant()]);
};

// ---------------------------------------------------------------------
// Foto ausliefern — nur wenn der Einsatz zu einem Objekt des Kunden gehoert
// ---------------------------------------------------------------------
if ($seite === 'foto' && $id > 0) {
    $f = $db->find('einsatz_fotos', $id);
    if ($f === null || (int) $f['geloescht'] === 1 || $meinEinsatz((int) $f['einsatz_id']) === null) { http_response_code(404); exit('Nicht gefunden.'); }
    \App\Photo::serve($id);
}
// Leistungsnachweis als PDF
if ($seite === 'nachweispdf') {
    $monat = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
    $html = App\Report::monatsnachweisHtml($kundeId, $monat, false);
    if ($html === null) { http_response_code(404); exit; }
    pdfAusgeben($html, 'Leistungsnachweis-' . $monat);
}
// Rechnungs-PDF
if ($seite === 'pdf' && $id > 0) {
    $r = $db->selectOne('rechnungen', "id = ? AND kunde_id = ? AND status <> 'entwurf'", [$id, $kundeId]);
    if ($r === null) { http_response_code(404); exit('Nicht gefunden.'); }
    $pfad = CustomerInvoice::pdf($id);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . basename($pfad) . '"'); readfile($pfad); exit;
}

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if ($aktion !== null && $aktion !== 'login') {
    try {
        Auth::checkCsrf($_POST['csrf'] ?? null);
        $pr = new Pruefung();
        if ($aktion === 'beschwerde') {
            $oid = $pr->int('objekt_id', true, 1);
            if (!in_array($oid, $objektIds, true)) { throw new RuntimeException('Objekt wählen.'); }
            $eid = $pr->int('einsatz_id'); if ($eid !== null && $meinEinsatz($eid) === null) { $eid = null; }
            $text = $pr->text('beschreibung', 4000, true, 'Beschreibung');
            if (!$pr->ok()) { throw new RuntimeException(implode(' ', $pr->fehler)); }
            $neu = $db->insert('beschwerden', ['kunde_id' => $kundeId, 'objekt_id' => $oid, 'einsatz_id' => $eid, 'datum' => $pr->datum('datum') ?? date('Y-m-d'), 'kategorie' => $pr->auswahl('kategorie', ['qualitaet', 'vergessen', 'puenktlichkeit', 'verhalten', 'schaden', 'sonstiges']) ?? 'sonstiges', 'beschreibung' => $text, 'status' => 'offen']);
            $obj = $db->find('objekte', $oid);
            if (!empty($betrieb['email'])) {
                $db->insert('benachrichtigungen', ['kanal' => 'mail', 'empfaenger_typ' => 'office', 'empfaenger_email' => $betrieb['email'], 'betreff' => 'Beschwerde vom Kunden: ' . $obj['name'],
                    'inhalt' => $kunde['firmenname'] . " meldet über das Kundenportal:\n\n" . $text . "\n\nObjekt: " . $obj['name'], 'referenz_typ' => 'beschwerde', 'referenz_id' => $neu]);
            }
            flash('ok', 'Danke, Ihre Meldung ist beim Büro eingegangen.');
            weiter('/portal/?s=melden');
        }
        if ($aktion === 'feedback') {
            $oid = $pr->int('objekt_id', true, 1); if (!in_array($oid, $objektIds, true)) { throw new RuntimeException('Objekt wählen.'); }
            $bew = $pr->int('bewertung', true, 1, 5);
            if (!$pr->ok()) { throw new RuntimeException(implode(' ', $pr->fehler)); }
            $db->insert('kundenfeedback', ['kunde_id' => $kundeId, 'objekt_id' => $oid, 'bewertung' => $bew, 'kommentar' => $pr->text('kommentar', 2000), 'kontaktperson' => $pr->text('kontaktperson', 120), 'datum' => date('Y-m-d')]);
            flash('ok', 'Danke für Ihre Rückmeldung.');
            weiter('/portal/?s=melden');
        }
        if ($aktion === 'passwort') {
            $alt = (string) ($_POST['alt'] ?? ''); $neu = (string) ($_POST['neu'] ?? '');
            if (!password_verify($alt, (string) $user['passwort_hash'])) { throw new RuntimeException('Das bisherige Passwort stimmt nicht.'); }
            if (mb_strlen($neu) < 10) { throw new RuntimeException('Mindestens 10 Zeichen.'); }
            if ($neu !== (string) ($_POST['neu2'] ?? '')) { throw new RuntimeException('Die neuen Passwörter stimmen nicht überein.'); }
            $db->raw('UPDATE benutzer SET passwort_hash = ? WHERE id = ?', [password_hash($neu, PASSWORD_DEFAULT), (int) $user['id']]);
            flash('ok', 'Passwort geändert.');
            weiter('/portal/?s=konto');
        }
    } catch (\Throwable $e) {
        flash('fehler', $e->getMessage());
        weiter('/portal/?s=' . $seite);
    }
}
$meldung = flashLesen();

$seiten = ['start' => 'Übersicht', 'nachweise' => 'Nachweise', 'rechnungen' => 'Rechnungen', 'objekte' => 'Objekte', 'melden' => 'Melden', 'konto' => 'Konto'];
if (!isset($seiten[$seite]) && $seite !== 'einsatz') { $seite = 'start'; }

kopf($seiten[$seite] ?? 'Nachweis');
?>
<div class="rahmen">
  <aside class="seitenleiste">
    <div class="betrieb"><?= e($betrieb['name']) ?><div class="klein" style="font-weight:400;margin-top:4px">Kundenportal</div></div>
    <nav><?php foreach ($seiten as $k => $v): if ($k === 'konto') continue; ?><a href="/portal/?s=<?= $k ?>" class="<?= ($seite === $k || ($seite === 'einsatz' && $k === 'nachweise')) ? 'aktiv' : '' ?>"><?= e($v) ?></a><?php endforeach; ?></nav>
    <div class="leiste-fuss"><a href="/portal/?s=konto" class="<?= $seite === 'konto' ? 'aktiv' : '' ?>"><?= e($kunde['firmenname']) ?></a><form method="post"><input type="hidden" name="aktion" value="logout"><button type="submit" class="leise">Abmelden</button></form></div>
  </aside>
  <main>
  <?php if ($meldung): ?><div class="meldung <?= e($meldung['typ']) ?>"><?= e($meldung['text']) ?></div><?php endif; ?>

<?php
// =====================================================================
if ($seite === 'start'):
    $naechste = $db->rawAll("SELECT e.id, e.datum, e.uhrzeit_von, e.uhrzeit_bis, e.status, o.name AS objekt, l.name AS leistung FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id JOIN leistungsarten l ON l.id = e.leistungsart_id WHERE e.betrieb_id = ? AND e.objekt_id IN ({$inObj}) AND e.datum >= CURDATE() AND e.status IN ('geplant','laeuft') ORDER BY e.datum, e.uhrzeit_von LIMIT 8", [$db->tenant()]);
    $letzte = $db->rawAll("SELECT e.id, e.datum, e.uhrzeit_von, o.name AS objekt, l.name AS leistung, (SELECT MIN(z.checkin_zeit) FROM zeiterfassung z WHERE z.einsatz_id = e.id) ein, (SELECT MAX(z.checkout_zeit) FROM zeiterfassung z WHERE z.einsatz_id = e.id) aus, (SELECT COUNT(*) FROM einsatz_fotos f WHERE f.einsatz_id = e.id AND f.geloescht = 0) fotos FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id JOIN leistungsarten l ON l.id = e.leistungsart_id WHERE e.betrieb_id = ? AND e.objekt_id IN ({$inObj}) AND e.status = 'erledigt' ORDER BY e.datum DESC, e.uhrzeit_von DESC LIMIT 5", [$db->tenant()]);
    $offenR = $db->rawOne("SELECT COUNT(*) n, IFNULL(SUM(brutto - IFNULL(bezahlt_betrag,0)),0) s FROM rechnungen WHERE betrieb_id = ? AND kunde_id = ? AND status IN ('gestellt','teilbezahlt','mahnung_1','mahnung_2')", [$db->tenant(), $kundeId]);
?>
  <h1>Guten Tag, <?= e($kunde['kontaktperson'] ?: $kunde['firmenname']) ?></h1>
  <div class="kennzahlen" style="grid-template-columns:repeat(3,1fr)">
    <a href="/portal/?s=nachweise"><b><?= count($letzte) ?></b><span>letzte Nachweise</span></a>
    <a href="/portal/?s=objekte"><b><?= count($meineObjekte) ?></b><span>Objekte</span></a>
    <a href="/portal/?s=rechnungen" class="<?= (int) $offenR['n'] > 0 ? 'gelb' : '' ?>"><b><?= geld($offenR['s']) ?></b><span>offene Rechnungen</span></a>
  </div>
  <div class="zweispalt">
    <section><h2 style="margin-top:0">Nächste Reinigungen</h2>
      <?php if ($naechste === []): ?><p class="leer">Keine Termine geplant.</p><?php else: ?><div class="liste"><?php foreach ($naechste as $n): ?>
        <div class="eintrag"><div class="zeit"><?= e(date('d.m.', strtotime($n['datum']))) ?></div><div><div class="titel"><?= e($n['objekt']) ?></div><div class="sub"><?= e($n['leistung']) ?> · <?= zeitKurz($n['uhrzeit_von']) ?>–<?= zeitKurz($n['uhrzeit_bis']) ?></div></div><span class="marke-aus"><?= $n['status'] === 'laeuft' ? 'läuft gerade' : 'geplant' ?></span></div>
      <?php endforeach; ?></div><?php endif; ?></section>
    <section><h2 style="margin-top:0">Zuletzt erledigt</h2>
      <?php if ($letzte === []): ?><p class="leer">Noch keine Nachweise.</p><?php else: ?><div class="liste"><?php foreach ($letzte as $n): ?>
        <div class="eintrag"><div class="zeit"><?= e(date('d.m.', strtotime($n['datum']))) ?></div><div><div class="titel"><a href="/portal/?s=einsatz&amp;id=<?= (int) $n['id'] ?>"><?= e($n['objekt']) ?></a></div><div class="sub"><?= $n['ein'] ? zeitKurz(substr($n['ein'], 11)) . '–' . ($n['aus'] ? zeitKurz(substr($n['aus'], 11)) : '?') : e(zeitKurz($n['uhrzeit_von'])) ?><?= (int) $n['fotos'] > 0 ? ' · ' . (int) $n['fotos'] . ' Foto' . ((int) $n['fotos'] === 1 ? '' : 's') : '' ?></div></div><span class="marke-ok">erledigt</span></div>
      <?php endforeach; ?></div><?php endif; ?></section>
  </div>

<?php elseif ($seite === 'nachweise'):
    $monat = $_GET['m'] ?? date('Y-m'); if (preg_match('/^\d{4}-\d{2}$/', $monat) !== 1) { $monat = date('Y-m'); }
    $liste = $db->rawAll("SELECT e.id, e.datum, e.uhrzeit_von, e.uhrzeit_bis, o.name AS objekt, l.name AS leistung, (SELECT MIN(z.checkin_zeit) FROM zeiterfassung z WHERE z.einsatz_id = e.id) ein, (SELECT MAX(z.checkout_zeit) FROM zeiterfassung z WHERE z.einsatz_id = e.id) aus, (SELECT COUNT(*) FROM einsatz_fotos f WHERE f.einsatz_id = e.id AND f.geloescht = 0) fotos FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id JOIN leistungsarten l ON l.id = e.leistungsart_id WHERE e.betrieb_id = ? AND e.objekt_id IN ({$inObj}) AND e.status = 'erledigt' AND DATE_FORMAT(e.datum,'%Y-%m') = ? ORDER BY e.datum DESC, e.uhrzeit_von DESC", [$db->tenant(), $monat]);
    $vor = date('Y-m', strtotime($monat . '-01 -1 month')); $nach = date('Y-m', strtotime($monat . '-01 +1 month'));
?>
  <h1>Nachweise</h1>
  <div class="filter"><a class="knopf mini" href="/portal/?s=nachweispdf&amp;m=<?= e($monat) ?>" target="_blank">Monat als PDF</a><a class="knopf zweit mini" href="/portal/?s=nachweise&amp;m=<?= $vor ?>">← <?= e(CustomerInvoice::monatName((int) substr($vor, 5))) ?></a><b style="align-self:center"><?= e(CustomerInvoice::monatName((int) substr($monat, 5)) . ' ' . substr($monat, 0, 4)) ?></b><?php if ($nach <= date('Y-m')): ?><a class="knopf zweit mini" href="/portal/?s=nachweise&amp;m=<?= $nach ?>"><?= e(CustomerInvoice::monatName((int) substr($nach, 5))) ?> →</a><?php endif; ?></div>
  <?php if ($liste === []): ?><p class="leer">In diesem Monat keine erledigten Reinigungen.</p><?php else: ?>
  <table><thead><tr><th>Datum</th><th>Objekt</th><th>Leistung</th><th>Zeit</th><th>Fotos</th></tr></thead><tbody>
    <?php foreach ($liste as $n): ?><tr><td class="nowrap"><a href="/portal/?s=einsatz&amp;id=<?= (int) $n['id'] ?>"><?= datumDe($n['datum']) ?></a></td><td><?= e($n['objekt']) ?></td><td><?= e($n['leistung']) ?></td><td class="nowrap"><?= $n['ein'] ? zeitKurz(substr($n['ein'], 11)) . ' – ' . ($n['aus'] ? zeitKurz(substr($n['aus'], 11)) : '…') : '—' ?></td><td><?= (int) $n['fotos'] ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>

<?php elseif ($seite === 'einsatz'):
    $e_ = $meinEinsatz($id); if ($e_ === null) { echo '<p class="leer">Nicht gefunden.</p>'; }
    else {
        $zeiten = $db->rawAll("SELECT z.checkin_zeit, z.checkout_zeit, z.minuten_netto, CONCAT(m.vorname,' ',LEFT(m.nachname,1),'.') AS wer FROM zeiterfassung z JOIN mitarbeiter m ON m.id = z.mitarbeiter_id WHERE z.einsatz_id = ? AND z.betrieb_id = ? ORDER BY z.checkin_zeit", [$id, $db->tenant()]);
        $fotos = $db->select('einsatz_fotos', 'einsatz_id = ? AND geloescht = 0', [$id], 'ORDER BY typ, aufgenommen_am');
        $leist = $db->find('leistungsarten', (int) $e_['leistungsart_id']);
?>
  <p class="zurueck"><a href="/portal/?s=nachweise&amp;m=<?= e(substr($e_['datum'], 0, 7)) ?>">← Nachweise</a></p>
  <h1><?= e($e_['objekt']) ?> <span class="marke-ok"><?= $e_['status'] === 'erledigt' ? 'erledigt' : e($e_['status']) ?></span></h1>
  <div class="zweispalt">
    <section>
      <dl class="fakten"><dt>Datum</dt><dd><?= datumDe($e_['datum']) ?></dd><dt>Leistung</dt><dd><?= e($leist['name'] ?? '') ?></dd><dt>Geplant</dt><dd><?= zeitKurz($e_['uhrzeit_von']) ?> – <?= zeitKurz($e_['uhrzeit_bis']) ?></dd>
        <?php foreach ($zeiten as $z): ?><dt><?= e($z['wer']) ?></dt><dd><?= zeitKurz(substr($z['checkin_zeit'], 11)) ?> – <?= $z['checkout_zeit'] ? zeitKurz(substr($z['checkout_zeit'], 11)) : '…' ?><?= $z['minuten_netto'] !== null ? ' · ' . e(number_format((int) $z['minuten_netto'] / 60, 1, ',', '')) . ' Std' : '' ?></dd><?php endforeach; ?></dl>
      <?php if ($fotos === []): ?><p class="leer">Keine Fotos zu diesem Einsatz.</p><?php else: ?>
      <h2>Fotos</h2>
      <?php foreach (['vorher' => 'Vorher', 'nachher' => 'Nachher', 'schaden' => 'Schaden', 'sonstiges' => 'Sonstiges'] as $ft => $fl): $fg = array_values(array_filter($fotos, static fn($f) => $f['typ'] === $ft)); if ($fg === []) { continue; } ?>
      <h3 class="fototitel"><?= $fl ?> <span class="klein"><?= count($fg) ?></span></h3>
      <div class="fotogitter"><?php foreach ($fg as $f): ?><figure><a href="/portal/?s=foto&amp;id=<?= (int) $f['id'] ?>" target="_blank"><img src="/portal/?s=foto&amp;id=<?= (int) $f['id'] ?>" alt="<?= e($fl) ?>" loading="lazy"></a><figcaption><?= e(date('H:i', strtotime($f['aufgenommen_am']))) ?></figcaption></figure><?php endforeach; ?></div>
      <?php endforeach; ?>
      <p class="klein">Fotos werden drei Monate nach dem Einsatz automatisch gelöscht.</p><?php endif; ?>
    </section>
    <section><h2 style="margin-top:0">Etwas nicht in Ordnung?</h2><a class="knopf zweit" href="/portal/?s=melden&amp;einsatz=<?= $id ?>&amp;objekt=<?= (int) $e_['objekt_id'] ?>">Zu diesem Einsatz melden</a></section>
  </div>
<?php } ?>

<?php elseif ($seite === 'rechnungen'):
    $liste = $db->select('rechnungen', "kunde_id = ? AND status <> 'entwurf'", [$kundeId], 'ORDER BY rechnungsdatum DESC, id DESC LIMIT 100');
?>
  <h1>Rechnungen</h1>
  <?php if ($liste === []): ?><p class="leer">Noch keine Rechnungen.</p><?php else: ?>
  <table><thead><tr><th>Nummer</th><th>Datum</th><th>Zeitraum</th><th class="r">Betrag</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($liste as $r): $offen = in_array($r['status'], ['gestellt', 'teilbezahlt', 'mahnung_1', 'mahnung_2'], true); ?>
      <tr class="<?= $r['status'] === 'storniert' ? 'aus' : '' ?>"><td><b><?= e($r['rechnungsnummer']) ?></b><?= $r['storno_von_rechnung_id'] ? '<div class="klein">Gutschrift</div>' : '' ?></td><td><?= datumDe($r['rechnungsdatum']) ?></td><td class="klein nowrap"><?= datumDe($r['leistungszeitraum_von']) ?> – <?= datumDe($r['leistungszeitraum_bis']) ?></td><td class="r"><?= geld($r['brutto']) ?></td>
      <td><?= $r['status'] === 'bezahlt' ? '<span class="marke-ok">bezahlt</span>' : ($r['status'] === 'storniert' ? '<span class="marke-aus">storniert</span>' : ($r['faellig_am'] < date('Y-m-d') ? '<span class="marke-rot">überfällig seit ' . datumDe($r['faellig_am']) . '</span>' : '<span class="marke-test">offen bis ' . datumDe($r['faellig_am']) . '</span>')) ?></td>
      <td class="r"><a class="knopf mini zweit" href="/portal/?s=pdf&amp;id=<?= (int) $r['id'] ?>" target="_blank">PDF</a></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>

<?php elseif ($seite === 'objekte'): ?>
  <h1>Ihre Objekte</h1>
  <?php foreach ($meineObjekte as $o): $ls = $db->rawAll('SELECT ol.*, l.name FROM objekt_leistungen ol JOIN leistungsarten l ON l.id = ol.leistungsart_id WHERE ol.objekt_id = ? AND ol.betrieb_id = ? AND ol.aktiv = 1 ORDER BY ol.uhrzeit_von', [(int) $o['id'], $db->tenant()]); ?>
    <div class="notiz" style="max-width:none;margin-bottom:14px"><b style="font-size:17px"><?= e($o['name']) ?></b><div class="klein"><?= e($o['adresse']) ?>, <?= e($o['plz']) ?> <?= e($o['ort']) ?></div>
      <?php if ($ls): ?><ul style="margin:10px 0 0 18px"><?php foreach ($ls as $l): ?><li><?= e($l['name']) ?> — <?= e(TURNUS[$l['turnus']] ?? $l['turnus']) ?>, <?= e(wochentageText((int) $l['wochentage'])) ?>, <?= zeitKurz($l['uhrzeit_von']) ?>–<?= zeitKurz($l['uhrzeit_bis']) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
  <?php endforeach; ?>

<?php elseif ($seite === 'melden'):
    $vorObj = (int) ($_GET['objekt'] ?? 0); $vorEin = (int) ($_GET['einsatz'] ?? 0);
    $einsaetze = $db->rawAll("SELECT e.id, e.datum, e.uhrzeit_von, o.name AS objekt FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id WHERE e.betrieb_id = ? AND e.objekt_id IN ({$inObj}) AND e.datum >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND e.status = 'erledigt' ORDER BY e.datum DESC LIMIT 30", [$db->tenant()]);
?>
  <h1>Melden</h1>
  <div class="zweispalt">
    <section><form method="post" class="formular"><input type="hidden" name="aktion" value="beschwerde"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <h2 class="erste">Beanstandung</h2>
      <div class="g"><label for="bo">Objekt</label><select id="bo" name="objekt_id" required><?php foreach ($meineObjekte as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $vorObj === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
      <div class="g"><label for="be">Betroffener Einsatz</label><select id="be" name="einsatz_id"><option value="">— nicht sicher —</option><?php foreach ($einsaetze as $es): ?><option value="<?= (int) $es['id'] ?>" <?= $vorEin === (int) $es['id'] ? 'selected' : '' ?>><?= datumDe($es['datum']) ?> <?= zeitKurz($es['uhrzeit_von']) ?> · <?= e($es['objekt']) ?></option><?php endforeach; ?></select></div>
      <div class="zwei"><div class="g"><label for="bk">Art</label><select id="bk" name="kategorie"><option value="qualitaet">Reinigungsqualität</option><option value="vergessen">Etwas vergessen</option><option value="puenktlichkeit">Pünktlichkeit</option><option value="verhalten">Verhalten</option><option value="schaden">Schaden</option><option value="sonstiges">Sonstiges</option></select></div><div class="g"><label for="bd">Datum</label><input id="bd" name="datum" type="date" value="<?= date('Y-m-d') ?>"></div></div>
      <div class="g"><label for="bt">Was ist passiert?</label><textarea id="bt" name="beschreibung" rows="4" required maxlength="4000"></textarea></div>
      <button type="submit">Absenden</button></form></section>
    <section><form method="post" class="formular"><input type="hidden" name="aktion" value="feedback"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <h2 class="erste">Wie zufrieden sind Sie?</h2>
      <div class="g"><label for="fo">Objekt</label><select id="fo" name="objekt_id" required><?php foreach ($meineObjekte as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
      <div class="g"><label for="fb">Bewertung</label><select id="fb" name="bewertung"><?php foreach ([5 => 'sehr zufrieden', 4 => 'zufrieden', 3 => 'in Ordnung', 2 => 'unzufrieden', 1 => 'sehr unzufrieden'] as $k => $v): ?><option value="<?= $k ?>"><?= str_repeat('★', $k) ?> <?= $v ?></option><?php endforeach; ?></select></div>
      <div class="g"><label for="fk">Ihr Name</label><input id="fk" name="kontaktperson" maxlength="120" value="<?= e($kunde['kontaktperson'] ?? '') ?>"></div>
      <div class="g"><label for="fc">Kommentar</label><textarea id="fc" name="kommentar" rows="3" maxlength="2000"></textarea></div>
      <button type="submit" class="zweit">Rückmeldung senden</button></form></section>
  </div>

<?php elseif ($seite === 'konto'): ?>
  <h1>Konto</h1>
  <dl class="fakten" style="max-width:520px"><dt>Firma</dt><dd><?= e($kunde['firmenname']) ?></dd><dt>Benutzername</dt><dd><?= e($user['benutzername']) ?></dd><dt>Ihr Betrieb</dt><dd><?= e($betrieb['name']) ?><?= $betrieb['telefon'] ? '<div class="klein">' . e($betrieb['telefon']) . '</div>' : '' ?><?= $betrieb['email'] ? '<div class="klein">' . e($betrieb['email']) . '</div>' : '' ?></dd></dl>
  <form method="post" class="formular" style="max-width:520px"><input type="hidden" name="aktion" value="passwort"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <h2 class="erste">Passwort ändern</h2>
    <div class="g"><label for="alt">Bisheriges Passwort</label><input id="alt" name="alt" type="password" autocomplete="current-password" required></div>
    <div class="g"><label for="neu">Neues Passwort</label><input id="neu" name="neu" type="password" autocomplete="new-password" required minlength="10"></div>
    <div class="g"><label for="neu2">Wiederholen</label><input id="neu2" name="neu2" type="password" autocomplete="new-password" required></div>
    <button type="submit">Ändern</button></form>
<?php endif; ?>
  </main>
</div>
</body></html>
<?php
function kopf(string $titel): void
{
    global $betrieb;
    $farbe = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($betrieb['farbe_primaer'] ?? '')) ? $betrieb['farbe_primaer'] : null;
    ?><!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title><?= e($titel) ?> — Kundenportal</title>
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,700,800|dm-sans:400,500,600"><link rel="stylesheet" href="/assets/css/buero.css">
<style>.fototitel{font-size:15px;margin:14px 0 6px;padding-left:10px;border-left:4px solid var(--haus)}.fototitel .klein{font-weight:400;color:var(--text2);margin-left:6px}
.fotogitter{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}.fotogitter figure{margin:0}.fotogitter img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:8px;border:1px solid var(--linie);display:block}.fotogitter figcaption{font-size:13px;color:var(--text2);margin-top:4px}<?= $farbe ? ':root{--haus:' . e($farbe) . '}' : '' ?></style>


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
</head><body><?php
}
