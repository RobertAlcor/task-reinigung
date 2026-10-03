<?php
/**
 * Rechnungen des Betriebs an seine Kunden.
 *  liste    — alle Rechnungen mit offenen Posten
 *  lauf     — Abrechnungslauf: Monat waehlen, je Kunde Vorschau und Entwurf erzeugen
 *  detail   — Entwurf bearbeiten / festgeschriebene Rechnung verwalten
 *  regie    — Regieleistungen erfassen (Sonderreinigung, Fahrtkosten)
 *  layout   — Rechnungslayout des Betriebs
 * Variablen: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

use App\Auth;
use App\CustomerInvoice as CI;
use App\Mailer;

$t  = $_GET['t'] ?? 'liste';
$id = (int) ($_GET['id'] ?? 0);
$pr = new Pruefung();

const R_STATUS = ['entwurf' => 'Entwurf', 'gestellt' => 'offen', 'teilbezahlt' => 'teilbezahlt', 'bezahlt' => 'bezahlt', 'mahnung_1' => '1. Mahnung', 'mahnung_2' => '2. Mahnung', 'storniert' => 'storniert'];

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    $aktion = $_POST['aktion'] ?? '';
    try {
        switch ($aktion) {
            case 'entwurf':
                $neu = CI::entwurfErzeugen((int) $_POST['kunde_id'], (int) $_POST['jahr'], (int) $_POST['monat']);
                flash('ok', 'Entwurf erzeugt. Bitte prüfen und dann festschreiben.');
                weiter('/admin/?s=rechnungen&t=detail&id=' . $neu);
            case 'entwurf_alle':
                $jahr = (int) $_POST['jahr']; $monat = (int) $_POST['monat']; $n = 0; $fehler = [];
                foreach ($db->select('kunden', "status = 'aktiv'", [], 'ORDER BY firmenname') as $k) {
                    try { CI::entwurfErzeugen((int) $k['id'], $jahr, $monat); $n++; } catch (\RuntimeException $e) { if (!str_contains($e->getMessage(), 'nichts abzurechnen')) { $fehler[] = $k['firmenname'] . ': ' . $e->getMessage(); } }
                }
                flash($fehler ? 'fehler' : 'ok', "{$n} Entwürfe erzeugt." . ($fehler ? ' ' . implode(' ', $fehler) : ''));
                weiter('/admin/?s=rechnungen&f=entwurf');
            case 'position':
                CI::positionSpeichern($id, (int) ($_POST['pos_id'] ?? 0) ?: null, ['beschreibung' => $_POST['beschreibung'] ?? '', 'menge' => str_replace(',', '.', (string) ($_POST['menge'] ?? '1')), 'einheit' => $_POST['einheit'] ?? '', 'einzelpreis' => str_replace(['.', ','], ['', '.'], (string) ($_POST['einzelpreis'] ?? '0'))]);
                flash('ok', 'Position gespeichert.'); weiter('/admin/?s=rechnungen&t=detail&id=' . $id);
            case 'position_loeschen':
                CI::positionLoeschen($id, (int) $_POST['pos_id']); flash('ok', 'Position entfernt.'); weiter('/admin/?s=rechnungen&t=detail&id=' . $id);
            case 'verwerfen':
                CI::entwurfLoeschen($id); flash('ok', 'Entwurf verworfen.'); weiter('/admin/?s=rechnungen');
            case 'festschreiben':
                $nr = CI::festschreiben($id, $pr->datum('rechnungsdatum')); CI::pdf($id, true); flash('ok', "Rechnung {$nr} festgeschrieben."); weiter('/admin/?s=rechnungen&t=detail&id=' . $id);
            case 'senden':
                CI::senden($id, $betrieb); flash('ok', 'Rechnung per E-Mail versendet.'); weiter('/admin/?s=rechnungen&t=detail&id=' . $id);
            case 'bezahlt':
                $betrag = trim((string) ($_POST['betrag'] ?? '')); CI::bezahlt($id, $pr->datum('bezahlt_am'), $betrag === '' ? null : (float) str_replace(['.', ','], ['', '.'], $betrag)); flash('ok', 'Zahlung gebucht.'); weiter('/admin/?s=rechnungen&t=detail&id=' . $id);
            case 'mahnung':
                $mid = CI::mahnungErstellen($id, (int) $_POST['stufe']); flash('ok', ((int) $_POST['stufe'] >= 2 ? '2. Mahnung' : 'Zahlungserinnerung') . ' erstellt. PDF liegt bereit.'); weiter('/admin/?s=rechnungen&t=detail&id=' . $id);
            case 'mahnung_senden':
                CI::mahnungSenden((int) $_POST['mahnung_id'], $betrieb); flash('ok', 'Mahnung per E-Mail gesendet.'); weiter('/admin/?s=rechnungen&t=detail&id=' . $id);
            case 'storno':
                $nr = CI::stornieren($id); flash('ok', "Storniert, Gutschrift {$nr} erzeugt."); weiter('/admin/?s=rechnungen&t=detail&id=' . $id);
            case 'regie':
                $oid = $pr->int('objekt_id', false, 1); $obj = $oid ? $db->find('objekte', $oid) : null;
                $kid = $obj ? (int) $obj['kunde_id'] : $pr->int('kunde_id', true, 1);
                if ($kid === null || $db->find('kunden', $kid) === null) { throw new RuntimeException('Kunde wählen.'); }
                $la = $pr->int('leistungsart_id'); if ($la !== null && $db->find('leistungsarten', $la) === null) { $la = null; }
                $menge = $pr->decimal('menge', true, 0.01); $preis = $pr->decimal('einzelpreis', true, 0);
                $beschr = $pr->text('beschreibung', 255, true, 'Beschreibung'); $datum = $pr->datum('datum', true);
                if (!$pr->ok()) { throw new RuntimeException(implode(' ', $pr->fehler)); }
                $db->insert('regieleistungen', ['kunde_id' => $kid, 'objekt_id' => $oid, 'leistungsart_id' => $la, 'datum' => $datum, 'beschreibung' => $beschr, 'menge' => $menge, 'einheit' => $pr->text('einheit', 20) ?? 'Std', 'einzelpreis' => $preis, 'fahrtkosten' => $pr->decimal('fahrtkosten', false, 0) ?? 0]);
                flash('ok', 'Regieleistung erfasst. Sie kommt auf die nächste Monatsrechnung.'); weiter('/admin/?s=rechnungen&t=regie');
            case 'regie_loeschen':
                $r = $db->find('regieleistungen', (int) $_POST['regie_id']);
                if ($r !== null && $r['rechnung_id'] === null) { $db->delete('regieleistungen', (int) $r['id']); flash('ok', 'Entfernt.'); }
                weiter('/admin/?s=rechnungen&t=regie');
            case 'layout':
                $db->raw('INSERT INTO rechnungs_layout (betrieb_id) VALUES (?) ON DUPLICATE KEY UPDATE betrieb_id = betrieb_id', [$db->tenant()]);
                $farbe = strtoupper(trim((string) ($_POST['farbe'] ?? ''))); if ($farbe !== '' && preg_match('/^#[0-9A-F]{6}$/', $farbe) !== 1) { $farbe = ''; }
                $iban = strtoupper(str_replace(' ', '', (string) ($_POST['iban'] ?? '')));
                if ($iban !== '' && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) { throw new RuntimeException('IBAN ungültig.'); }
                $db->raw('UPDATE rechnungs_layout SET kopfzeile = ?, fusszeile = ?, farbe = ?, einleitungstext = ?, schlusstext = ?, zahlungsbedingungen = ?, bankname = ?, iban = ?, bic = ?, zeige_positionen_detail = ? WHERE betrieb_id = ?',
                    [$pr->text('kopfzeile', 500), $pr->text('fusszeile', 500), $farbe ?: null, $pr->text('einleitungstext', 1000), $pr->text('schlusstext', 1000), $pr->text('zahlungsbedingungen', 500), $pr->text('bankname', 100), $iban ?: null, $pr->text('bic', 11), $pr->haken('zeige_positionen_detail'), $db->tenant()]);
                $zahl = static fn(string $k, float $std): float => round((float) str_replace(',', '.', (string) ($_POST[$k] ?? $std)), 2);
                $db->raw('UPDATE rechnungs_layout SET mahnspesen_1 = ?, mahnspesen_2 = ?, verzugszins_prozent = ?, mahnfrist_tage = ? WHERE betrieb_id = ?', [$zahl('mahnspesen_1', 0), $zahl('mahnspesen_2', 40), $zahl('verzugszins_prozent', 9.2), max(3, min(30, (int) ($_POST['mahnfrist_tage'] ?? 10))), $db->tenant()]);
                foreach (['ust_satz' => (string) ((float) str_replace(',', '.', (string) ($_POST['ust_satz'] ?? '20'))), 'kleinunternehmer' => !empty($_POST['kleinunternehmer']) ? '1' : '0'] as $k => $v) {
                    $db->raw("INSERT INTO einstellungen (betrieb_id, schluessel, wert, typ) VALUES (?, ?, ?, 'text') ON DUPLICATE KEY UPDATE wert = VALUES(wert)", [$db->tenant(), $k, $v]);
                }
                flash('ok', 'Layout gespeichert.'); weiter('/admin/?s=rechnungen&t=layout');
        }
    } catch (\Throwable $e) {
        flash('fehler', $e->getMessage());
        weiter('/admin/?s=rechnungen' . ($id > 0 ? '&t=detail&id=' . $id : ($t !== 'liste' ? '&t=' . $t : '')));
    }
}

// PDF ausliefern
if ($t === 'pdf' && $id > 0) {
    $pfad = CI::pdf($id, isset($_GET['neu']) || ($db->find('rechnungen', $id)['status'] ?? '') === 'entwurf');
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . basename($pfad) . '"'); header('Content-Length: ' . (string) filesize($pfad));
    readfile($pfad); exit;
}

if ($t === 'mahnpdf' && $id > 0) {
    $mn = $db->find('mahnungen', $id); if ($mn === null) { flash('fehler', 'Mahnung nicht gefunden.'); weiter('/admin/?s=rechnungen'); }
    $pf = CI::mahnungPdf($id); while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . basename($pf) . '"'); header('Content-Length: ' . filesize($pf)); readfile($pf); exit;
}
$tabs = ['liste' => 'Rechnungen', 'lauf' => 'Abrechnungslauf', 'regie' => 'Regieleistungen', 'layout' => 'Layout'];
?>
<div class="kopfzeile"><h1>Rechnungen</h1><a class="knopf" href="/admin/?s=rechnungen&amp;t=lauf">Monat abrechnen</a></div>
<nav class="tabs"><?php foreach ($tabs as $k => $v): ?><a href="/admin/?s=rechnungen&amp;t=<?= $k ?>" class="<?= ($t === $k || ($t === 'detail' && $k === 'liste')) ? 'aktiv' : '' ?>"><?= e($v) ?></a><?php endforeach; ?></nav>

<?php
// =====================================================================
// LISTE
// =====================================================================
if ($t === 'liste'):
    $f = $_GET['f'] ?? 'offen';
    $where = match ($f) { 'entwurf' => "r.status = 'entwurf'", 'bezahlt' => "r.status = 'bezahlt'", 'alle' => '1=1', default => "r.status IN ('gestellt','teilbezahlt','mahnung_1','mahnung_2')" };
    $liste = $db->rawAll("SELECT r.*, k.firmenname FROM rechnungen r JOIN kunden k ON k.id = r.kunde_id WHERE r.betrieb_id = ? AND {$where} ORDER BY r.status = 'entwurf' DESC, r.rechnungsdatum DESC, r.id DESC LIMIT 300", [$db->tenant()]);
    $kz = $db->rawOne("SELECT IFNULL(SUM(CASE WHEN status IN ('gestellt','teilbezahlt','mahnung_1','mahnung_2') THEN brutto - IFNULL(bezahlt_betrag,0) END),0) offen,
                              IFNULL(SUM(CASE WHEN status IN ('gestellt','teilbezahlt','mahnung_1','mahnung_2') AND faellig_am < CURDATE() THEN brutto - IFNULL(bezahlt_betrag,0) END),0) ueberfaellig,
                              SUM(status = 'entwurf') entwuerfe,
                              IFNULL(SUM(CASE WHEN status <> 'storniert' AND status <> 'entwurf' AND YEAR(rechnungsdatum) = YEAR(CURDATE()) THEN netto END),0) umsatz_jahr
                         FROM rechnungen WHERE betrieb_id = ?", [$db->tenant()]);
?>
  <div class="kennzahlen">
    <div><b><?= geld($kz['offen']) ?></b><span>offen</span></div>
    <div class="<?= (float) $kz['ueberfaellig'] > 0 ? 'rot' : '' ?>"><b><?= geld($kz['ueberfaellig']) ?></b><span>überfällig</span></div>
    <a href="/admin/?s=rechnungen&amp;f=entwurf" class="<?= (int) $kz['entwuerfe'] > 0 ? 'gelb' : '' ?>"><b><?= (int) $kz['entwuerfe'] ?></b><span>Entwürfe</span></a>
    <div><b><?= geld($kz['umsatz_jahr']) ?></b><span>Umsatz netto <?= date('Y') ?></span></div>
  </div>
  <form method="get" class="filter"><input type="hidden" name="s" value="rechnungen"><select name="f" onchange="this.form.submit()" aria-label="Filter">
    <?php foreach (['offen' => 'Offene', 'entwurf' => 'Entwürfe', 'bezahlt' => 'Bezahlte', 'alle' => 'Alle'] as $k => $v): ?><option value="<?= $k ?>" <?= $f === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></form>
  <?php if ($liste === []): ?><p class="leer">Keine Rechnungen. <a href="/admin/?s=rechnungen&amp;t=lauf">Monat abrechnen</a></p><?php else: ?>
  <table><thead><tr><th>Nummer</th><th>Kunde</th><th>Zeitraum</th><th class="r">Brutto</th><th>Status</th><th>Fällig</th></tr></thead><tbody>
    <?php foreach ($liste as $r): $ueber = in_array($r['status'], ['gestellt', 'teilbezahlt', 'mahnung_1', 'mahnung_2'], true) && $r['faellig_am'] < date('Y-m-d'); ?>
      <tr class="<?= $r['status'] === 'storniert' ? 'aus' : '' ?>"><td><a href="/admin/?s=rechnungen&amp;t=detail&amp;id=<?= (int) $r['id'] ?>"><?= e($r['rechnungsnummer'] ?: 'Entwurf #' . $r['id']) ?></a><?= $r['storno_von_rechnung_id'] ? '<div class="klein">Gutschrift</div>' : '' ?></td>
      <td><?= e($r['firmenname']) ?></td><td class="klein nowrap"><?= datumDe($r['leistungszeitraum_von']) ?> – <?= datumDe($r['leistungszeitraum_bis']) ?></td>
      <td class="r"><?= geld($r['brutto']) ?><?= $r['status'] === 'teilbezahlt' ? '<div class="klein">' . geld($r['bezahlt_betrag']) . ' bezahlt</div>' : '' ?></td>
      <td><?= rMarke($r['status'], $ueber) ?></td><td><?= datumDe($r['faellig_am']) ?></td></tr>
    <?php endforeach; ?></tbody></table>
  <?php endif; ?>

<?php
// =====================================================================
// ABRECHNUNGSLAUF
// =====================================================================
elseif ($t === 'lauf'):
    $jahr = (int) ($_GET['jahr'] ?? date('Y', strtotime('first day of last month'))); $monat = (int) ($_GET['monat'] ?? date('n', strtotime('first day of last month')));
    $kunden = $db->select('kunden', "status = 'aktiv'", [], 'ORDER BY firmenname');
    $von = sprintf('%04d-%02d-01', $jahr, $monat);
    $vorhanden = []; foreach ($db->select('rechnungen', "leistungszeitraum_von = ? AND status <> 'storniert'", [$von]) as $r) { $vorhanden[(int) $r['kunde_id']] = $r; }
?>
  <div class="filter" style="justify-content:space-between">
    <form method="get" class="filter" style="margin:0"><input type="hidden" name="s" value="rechnungen"><input type="hidden" name="t" value="lauf">
      <select name="monat" aria-label="Monat"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $monat ? 'selected' : '' ?>><?= CI::monatName($m) ?></option><?php endfor; ?></select>
      <select name="jahr" aria-label="Jahr"><?php for ($j = (int) date('Y') - 1; $j <= (int) date('Y'); $j++): ?><option value="<?= $j ?>" <?= $j === $jahr ? 'selected' : '' ?>><?= $j ?></option><?php endfor; ?></select>
      <button type="submit" class="zweit">Anzeigen</button>
    </form>
    <form method="post" id="alle-entwuerfe"><input type="hidden" name="aktion" value="entwurf_alle"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="jahr" value="<?= $jahr ?>"><input type="hidden" name="monat" value="<?= $monat ?>"><button type="submit" onclick="return confirm('Für alle aktiven Kunden ohne Rechnung einen Entwurf erzeugen?')">Alle Entwürfe erzeugen</button></form>
  </div>
  <p class="unter">Aus Pauschalen, erledigten Einsätzen, Stunden nach Aufwand, offenen Regieleistungen und Material. Entwürfe lassen sich vor dem Festschreiben ändern.</p>
  <?php if ($kunden === []): ?><p class="leer">Keine aktiven Kunden.</p><?php else: ?>
  <table><thead><tr><th>Kunde</th><th>Positionen</th><th class="r">Netto</th><th></th></tr></thead><tbody>
    <?php foreach ($kunden as $k): $v = CI::vorschau((int) $k['id'], $jahr, $monat); $netto = array_sum(array_column($v['positionen'], 'gesamtpreis')); $vr = $vorhanden[(int) $k['id']] ?? null; ?>
      <tr><td><b><?= e($k['firmenname']) ?></b></td>
      <td class="klein"><?php if ($v['positionen'] === []): ?>—<?php else: foreach (array_slice($v['positionen'], 0, 4) as $p): ?><div><?= e(mb_strimwidth($p['beschreibung'], 0, 70, '…')) ?> · <?= geld($p['gesamtpreis']) ?></div><?php endforeach; if (count($v['positionen']) > 4): ?><div>+ <?= count($v['positionen']) - 4 ?> weitere</div><?php endif; endif; ?></td>
      <td class="r"><b><?= geld($netto) ?></b></td>
      <td class="r nowrap"><?php if ($vr): ?><a class="knopf mini zweit" href="/admin/?s=rechnungen&amp;t=detail&amp;id=<?= (int) $vr['id'] ?>"><?= e($vr['rechnungsnummer'] ?: 'Entwurf') ?> öffnen</a>
        <?php elseif ($v['positionen'] !== []): ?><form method="post" class="inl"><input type="hidden" name="aktion" value="entwurf"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="kunde_id" value="<?= (int) $k['id'] ?>"><input type="hidden" name="jahr" value="<?= $jahr ?>"><input type="hidden" name="monat" value="<?= $monat ?>"><button type="submit" class="mini">Entwurf erzeugen</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table>
  <?php endif; ?>

<?php
// =====================================================================
// DETAIL
// =====================================================================
elseif ($t === 'detail'):
    $r = $db->rawOne('SELECT r.*, k.firmenname, k.email AS k_email, k.rechnungs_email FROM rechnungen r JOIN kunden k ON k.id = r.kunde_id WHERE r.id = ? AND r.betrieb_id = ?', [$id, $db->tenant()]);
    if ($r === null) { flash('fehler', 'Rechnung nicht gefunden.'); weiter('/admin/?s=rechnungen'); }
    $pos = $db->select('rechnung_positionen', 'rechnung_id = ?', [$id], 'ORDER BY sortierung');
    $entwurf = $r['status'] === 'entwurf'; $offenS = in_array($r['status'], ['gestellt', 'teilbezahlt', 'mahnung_1', 'mahnung_2'], true);
    $bearb = null; if (($pid = (int) ($_GET['p'] ?? 0)) > 0) { foreach ($pos as $p) { if ((int) $p['id'] === $pid) { $bearb = $p; } } }
    $klein = CI::kleinunternehmer();
?>
  <p class="zurueck"><a href="/admin/?s=rechnungen">← Rechnungen</a></p>
  <div class="kopfzeile"><h1 style="font-size:24px"><?= e($r['rechnungsnummer'] ?: 'Entwurf') ?> · <?= e($r['firmenname']) ?> <?= rMarke($r['status'], $offenS && $r['faellig_am'] < date('Y-m-d')) ?></h1>
    <div class="zeile-knoepfe"><a class="knopf zweit" href="/admin/?s=rechnungen&amp;t=pdf&amp;id=<?= $id ?>" target="_blank"><?= $entwurf ? 'Vorschau-PDF' : 'PDF' ?></a></div></div>
  <div class="zweispalt">
    <section>
      <table><thead><tr><th>Position</th><th class="r">Menge</th><th class="r">Einzelpreis</th><th class="r">Betrag</th><?php if ($entwurf): ?><th></th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($pos as $p): ?><tr><td><?= e($p['beschreibung']) ?></td><td class="r nowrap"><?= e(rtrim(rtrim(number_format((float) $p['menge'], 2, ',', '.'), '0'), ',')) ?> <?= e($p['einheit']) ?></td><td class="r"><?= geld($p['einzelpreis']) ?></td><td class="r"><b><?= geld($p['gesamtpreis']) ?></b></td>
          <?php if ($entwurf): ?><td class="r nowrap"><a class="knopf mini zweit" href="/admin/?s=rechnungen&amp;t=detail&amp;id=<?= $id ?>&amp;p=<?= (int) $p['id'] ?>#pos">Ändern</a> <form method="post" class="inl"><input type="hidden" name="aktion" value="position_loeschen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="pos_id" value="<?= (int) $p['id'] ?>"><button type="submit" class="mini leise">✕</button></form></td><?php endif; ?></tr><?php endforeach; ?>
        <tr><td colspan="<?= $entwurf ? 4 : 3 ?>" class="r">Netto</td><td class="r"><?= geld($r['netto']) ?></td></tr>
        <?php if (!$klein): ?><tr><td colspan="<?= $entwurf ? 4 : 3 ?>" class="r">USt <?= (int) $r['ust_satz'] ?> %</td><td class="r"><?= geld($r['ust']) ?></td></tr><?php endif; ?>
        <tr><td colspan="<?= $entwurf ? 4 : 3 ?>" class="r"><b>Gesamt</b></td><td class="r"><b><?= geld($r['brutto']) ?></b></td></tr>
      </tbody></table>
      <?php if ($entwurf): ?>
      <h2 id="pos"><?= $bearb ? 'Position ändern' : 'Position hinzufügen' ?></h2>
      <form method="post" class="formular"><input type="hidden" name="aktion" value="position"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="pos_id" value="<?= (int) ($bearb['id'] ?? 0) ?>">
        <div class="g"><label>Beschreibung</label><input name="beschreibung" required maxlength="255" value="<?= e($bearb['beschreibung'] ?? '') ?>"></div>
        <div class="drei"><div class="g"><label>Menge</label><input name="menge" inputmode="decimal" value="<?= e($bearb ? rtrim(rtrim(number_format((float) $bearb['menge'], 2, ',', ''), '0'), ',') : '1') ?>" required></div><div class="g"><label>Einheit</label><input name="einheit" maxlength="20" value="<?= e($bearb['einheit'] ?? 'Pauschale') ?>"></div><div class="g"><label>Einzelpreis netto</label><input name="einzelpreis" inputmode="decimal" value="<?= e($bearb ? number_format((float) $bearb['einzelpreis'], 2, ',', '') : '') ?>" required></div></div>
        <div class="zeile-knoepfe"><button type="submit" class="mini">Speichern</button><?php if ($bearb): ?><a class="knopf mini zweit" href="/admin/?s=rechnungen&amp;t=detail&amp;id=<?= $id ?>">Abbrechen</a><?php endif; ?></div></form>
      <?php endif; ?>
    </section>
    <section>
      <dl class="fakten"><dt>Kunde</dt><dd><a href="/admin/?s=kunden&amp;t=detail&amp;id=<?= (int) $r['kunde_id'] ?>"><?= e($r['firmenname']) ?></a></dd><dt>Zeitraum</dt><dd><?= datumDe($r['leistungszeitraum_von']) ?> – <?= datumDe($r['leistungszeitraum_bis']) ?></dd>
        <?php if (!$entwurf): ?><dt>Datum</dt><dd><?= datumDe($r['rechnungsdatum']) ?></dd><dt>Fällig</dt><dd><?= datumDe($r['faellig_am']) ?></dd><?php endif; ?>
        <?php if ($r['bezahlt_am']): ?><dt>Bezahlt</dt><dd><?= datumDe($r['bezahlt_am']) ?> · <?= geld($r['bezahlt_betrag']) ?></dd><?php endif; ?>
        <?php if ($r['storno_von_rechnung_id']): ?><dt>Storno zu</dt><dd><a href="/admin/?s=rechnungen&amp;t=detail&amp;id=<?= (int) $r['storno_von_rechnung_id'] ?>">Rechnung #<?= (int) $r['storno_von_rechnung_id'] ?></a></dd><?php endif; ?>
        <dt>E-Mail</dt><dd><?= e($r['rechnungs_email'] ?: $r['k_email'] ?: '— keine hinterlegt') ?></dd></dl>
      <?php if ($entwurf): ?>
        <h2>Festschreiben</h2>
        <form method="post" class="formular"><input type="hidden" name="aktion" value="festschreiben"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="g"><label>Rechnungsdatum</label><input name="rechnungsdatum" type="date" value="<?= date('Y-m-d') ?>"></div>
          <p class="klein" style="margin-bottom:12px">Vergibt die fortlaufende Nummer. Danach ist die Rechnung unveränderbar; Korrektur nur per Storno.</p>
          <div class="zeile-knoepfe"><button type="submit" onclick="return confirm('Rechnung festschreiben?')">Festschreiben</button>
          <button type="submit" form="verwerfen" class="zweit" onclick="return confirm('Entwurf verwerfen?')">Verwerfen</button></div></form>
        <form method="post" id="verwerfen"><input type="hidden" name="aktion" value="verwerfen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"></form>
      <?php else: ?>
        <h2>Aktionen</h2>
        <div class="aktionen-liste">
          <?php if (Mailer::configured() && ($r['rechnungs_email'] || $r['k_email'])): ?><form method="post"><input type="hidden" name="aktion" value="senden"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="zweit" style="width:100%">Per E-Mail senden</button></form>
          <?php elseif (!Mailer::configured()): ?><p class="klein">Mailversand nicht konfiguriert — PDF herunterladen und selbst senden.</p><?php endif; ?>
          <?php if ($offenS): ?>
            <form method="post" class="formular" style="padding:14px 16px"><input type="hidden" name="aktion" value="bezahlt"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <div class="zwei"><div class="g"><label>Zahlungseingang</label><input name="bezahlt_am" type="date" value="<?= date('Y-m-d') ?>"></div><div class="g"><label>Betrag</label><input name="betrag" inputmode="decimal" placeholder="<?= e(number_format((float) $r['brutto'] - (float) ($r['bezahlt_betrag'] ?? 0), 2, ',', '.')) ?>"><div class="tipp">Leer = voller Betrag</div></div></div>
              <button type="submit" class="mini">Bezahlt buchen</button></form>
            <?php if ($r['faellig_am'] < date('Y-m-d') && in_array($r['status'], ['gestellt', 'teilbezahlt', 'mahnung_1'], true)): ?><form method="post"><input type="hidden" name="aktion" value="mahnung"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="stufe" value="<?= $r['status'] === 'mahnung_1' ? 2 : 1 ?>"><button type="submit" class="zweit mini"><?= $r['status'] === 'mahnung_1' ? '2. Mahnung erstellen' : 'Zahlungserinnerung erstellen' ?></button></form><?php endif; ?>
          <?php endif; ?>
          <?php if ($r['status'] !== 'storniert' && !$r['storno_von_rechnung_id']): ?><form method="post"><input type="hidden" name="aktion" value="storno"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="zweit mini" onclick="return confirm('Rechnung stornieren? Es wird eine Gutschrift mit neuer Nummer erzeugt.')">Stornieren</button></form><?php endif; ?>
        </div>
        <?php $mahnungen = $db->select('mahnungen', 'rechnung_id = ?', [$id], 'ORDER BY stufe'); if ($mahnungen !== []): ?>
        <h2>Mahnungen</h2>
        <div class="liste"><?php foreach ($mahnungen as $mn): ?>
          <div class="eintrag eintrag-2"><div><div class="titel"><?= (int) $mn['stufe'] === 1 ? 'Zahlungserinnerung' : '2. Mahnung' ?> vom <?= datumDe($mn['datum']) ?></div><div class="sub">Frist <?= datumDe($mn['frist_bis']) ?> · offen <?= geld($mn['offen_betrag']) ?><?= (float) $mn['verzugszinsen'] > 0 ? ' + Zinsen ' . geld($mn['verzugszinsen']) : '' ?><?= (float) $mn['mahnspesen'] > 0 ? ' + Spesen ' . geld($mn['mahnspesen']) : '' ?> = <b><?= geld($mn['gesamt']) ?></b><?= $mn['gesendet_am'] ? ' · gesendet ' . e(date('d.m. H:i', strtotime($mn['gesendet_am']))) : '' ?></div></div>
            <div class="zeile-knoepfe" style="flex-wrap:nowrap"><a class="knopf mini zweit" href="/admin/?s=rechnungen&amp;t=mahnpdf&amp;id=<?= (int) $mn['id'] ?>" target="_blank">PDF</a><?php if (Mailer::configured() && ($r['rechnungs_email'] || $r['k_email'])): ?><form method="post" class="inl"><input type="hidden" name="aktion" value="mahnung_senden"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="rechnung_id" value="<?= $id ?>"><input type="hidden" name="mahnung_id" value="<?= (int) $mn['id'] ?>"><button type="submit" class="mini"><?= $mn['gesendet_am'] ? 'Erneut senden' : 'Per E-Mail senden' ?></button></form><?php endif; ?></div></div>
        <?php endforeach; ?></div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>

<?php
// =====================================================================
// REGIE
// =====================================================================
elseif ($t === 'regie'):
    $objekte = $db->rawAll('SELECT o.id, o.name, o.kunde_id, k.firmenname FROM objekte o JOIN kunden k ON k.id = o.kunde_id WHERE o.betrieb_id = ? AND o.aktiv = 1 ORDER BY k.firmenname, o.name', [$db->tenant()]);
    $kunden = $db->select('kunden', "status = 'aktiv'", [], 'ORDER BY firmenname');
    $arten = $db->select('leistungsarten', "aktiv = 1", [], 'ORDER BY sortierung');
    $offenR = $db->rawAll('SELECT r.*, k.firmenname, o.name AS objekt FROM regieleistungen r JOIN kunden k ON k.id = r.kunde_id LEFT JOIN objekte o ON o.id = r.objekt_id WHERE r.betrieb_id = ? AND r.rechnung_id IS NULL ORDER BY r.datum DESC LIMIT 100', [$db->tenant()]);
?>
  <div class="zweispalt">
    <section>
      <p class="unter">Zusatzleistungen außerhalb der Pauschalen — Sonderreinigungen, Nacharbeiten, Fahrtkosten. Sie landen automatisch auf der nächsten Monatsrechnung des Kunden.</p>
      <?php if ($offenR === []): ?><p class="leer">Keine offenen Regieleistungen.</p><?php else: ?>
      <table><thead><tr><th>Datum</th><th>Kunde / Objekt</th><th>Leistung</th><th class="r">Betrag</th><th></th></tr></thead><tbody>
        <?php foreach ($offenR as $x): ?><tr><td class="nowrap"><?= datumDe($x['datum']) ?></td><td><?= e($x['firmenname']) ?><?= $x['objekt'] ? '<div class="klein">' . e($x['objekt']) . '</div>' : '' ?></td><td><?= e($x['beschreibung']) ?><div class="klein"><?= e(rtrim(rtrim(number_format((float) $x['menge'], 2, ',', '.'), '0'), ',')) ?> <?= e($x['einheit']) ?> × <?= geld($x['einzelpreis']) ?><?= (float) $x['fahrtkosten'] > 0 ? ' + ' . geld($x['fahrtkosten']) . ' Fahrt' : '' ?></div></td><td class="r"><b><?= geld((float) $x['menge'] * (float) $x['einzelpreis'] + (float) $x['fahrtkosten']) ?></b></td>
          <td class="r"><form method="post" class="inl"><input type="hidden" name="aktion" value="regie_loeschen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="regie_id" value="<?= (int) $x['id'] ?>"><button type="submit" class="mini leise" onclick="return confirm('Entfernen?')">✕</button></form></td></tr><?php endforeach; ?>
      </tbody></table><?php endif; ?>
    </section>
    <section>
      <h2 style="margin-top:0">Erfassen</h2>
      <form method="post" class="formular"><input type="hidden" name="aktion" value="regie"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <div class="g"><label for="ro">Objekt</label><select id="ro" name="objekt_id"><option value="">— ohne Objekt, nur Kunde —</option><?php foreach ($objekte as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['firmenname']) ?> · <?= e($o['name']) ?></option><?php endforeach; ?></select></div>
        <div class="g"><label for="rk">Kunde (wenn kein Objekt)</label><select id="rk" name="kunde_id"><option value="">—</option><?php foreach ($kunden as $k): ?><option value="<?= (int) $k['id'] ?>"><?= e($k['firmenname']) ?></option><?php endforeach; ?></select></div>
        <div class="zwei"><div class="g"><label for="rd">Datum</label><input id="rd" name="datum" type="date" value="<?= date('Y-m-d') ?>" required></div><div class="g"><label for="rl">Leistungsart</label><select id="rl" name="leistungsart_id"><option value="">—</option><?php foreach ($arten as $a): ?><option value="<?= (int) $a['id'] ?>" data-satz="<?= e((string) ($a['standard_stundensatz'] ?? '')) ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></div></div>
        <div class="g"><label for="rb">Beschreibung</label><input id="rb" name="beschreibung" required maxlength="255" placeholder="Sonderreinigung Küche nach Wasserschaden"></div>
        <div class="drei"><div class="g"><label for="rm">Menge</label><input id="rm" name="menge" inputmode="decimal" value="1" required></div><div class="g"><label for="re">Einheit</label><input id="re" name="einheit" value="Std" maxlength="20"></div><div class="g"><label for="rp">Einzelpreis netto</label><input id="rp" name="einzelpreis" inputmode="decimal" required></div></div>
        <div class="g"><label for="rf">Fahrtkosten</label><input id="rf" name="fahrtkosten" inputmode="decimal" placeholder="0,00"></div>
        <button type="submit">Erfassen</button>
      </form>
      <script>document.getElementById('rl').addEventListener('change',function(){var s=this.selectedOptions[0].dataset.satz;if(s&&!document.getElementById('rp').value)document.getElementById('rp').value=String(parseFloat(s).toFixed(2)).replace('.',',');});</script>
    </section>
  </div>

<?php
// =====================================================================
// LAYOUT
// =====================================================================
elseif ($t === 'layout'):
    $lay = $db->rawOne('SELECT * FROM rechnungs_layout WHERE betrieb_id = ?', [$db->tenant()]) ?? [];
    $ust = CI::ustSatz(); $klein = CI::kleinunternehmer();
?>
  <form method="post" class="formular" style="max-width:820px"><input type="hidden" name="aktion" value="layout"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <h2 class="erste">Steuer</h2>
    <div class="zwei"><div class="g"><label>USt-Satz %</label><input name="ust_satz" inputmode="decimal" value="<?= e(str_replace('.', ',', (string) $ust)) ?>"></div>
      <div class="g"><label class="tag-haken" style="margin-top:28px"><input type="checkbox" name="kleinunternehmer" value="1" <?= $klein ? 'checked' : '' ?>> Kleinunternehmer (keine USt)</label></div></div>
    <h2>Bankverbindung</h2>
    <div class="drei"><div class="g"><label>Bank</label><input name="bankname" value="<?= e($lay['bankname'] ?? '') ?>" maxlength="100"></div><div class="g"><label>IBAN</label><input name="iban" value="<?= e($lay['iban'] ?? '') ?>" maxlength="34"></div><div class="g"><label>BIC</label><input name="bic" value="<?= e($lay['bic'] ?? '') ?>" maxlength="11"></div></div>
    <h2>Texte</h2>
    <div class="g"><label>Einleitung</label><input name="einleitungstext" value="<?= e($lay['einleitungstext'] ?? '') ?>" maxlength="1000" placeholder="Wir erlauben uns, folgende Leistungen in Rechnung zu stellen:"></div>
    <div class="g"><label>Zahlungsbedingungen</label><input name="zahlungsbedingungen" value="<?= e($lay['zahlungsbedingungen'] ?? '') ?>" maxlength="500"><div class="tipp">Leer = „Zahlbar bis [Fälligkeit] ohne Abzug." Das Zahlungsziel kommt aus dem Kunden.</div></div>
    <h2>Mahnwesen</h2>
    <div class="drei"><div class="g"><label>Spesen Zahlungserinnerung</label><input name="mahnspesen_1" value="<?= e(number_format((float) ($lay['mahnspesen_1'] ?? 0), 2, ',', '')) ?>" inputmode="decimal"><div class="tipp">Meist 0 €</div></div>
      <div class="g"><label>Betreibungskosten 2. Mahnung</label><input name="mahnspesen_2" value="<?= e(number_format((float) ($lay['mahnspesen_2'] ?? 40), 2, ',', '')) ?>" inputmode="decimal"><div class="tipp">§ 458 UGB: pauschal 40 € bei Unternehmern</div></div>
      <div class="g"><label>Verzugszinsen % p. a.</label><input name="verzugszins_prozent" value="<?= e(number_format((float) ($lay['verzugszins_prozent'] ?? 9.2), 2, ',', '')) ?>" inputmode="decimal"><div class="tipp">§ 456 UGB: Basiszinssatz + 9,2 Punkte (Unternehmer); Verbraucher 4 %</div></div></div>
    <div class="g" style="max-width:220px"><label>Zahlungsfrist in der Mahnung (Tage)</label><input name="mahnfrist_tage" type="number" min="3" max="30" value="<?= (int) ($lay['mahnfrist_tage'] ?? 10) ?>"></div>
    <div class="g"><label>Schlusstext</label><input name="schlusstext" value="<?= e($lay['schlusstext'] ?? '') ?>" maxlength="1000" placeholder="Vielen Dank für Ihr Vertrauen."></div>
    <div class="zwei"><div class="g"><label>Kopfzeile rechts oben</label><textarea name="kopfzeile" rows="2" maxlength="500"><?= e($lay['kopfzeile'] ?? '') ?></textarea></div><div class="g"><label>Fußzeile zusätzlich</label><textarea name="fusszeile" rows="2" maxlength="500" placeholder="Gerichtsstand, Firmenbuchnummer …"><?= e($lay['fusszeile'] ?? '') ?></textarea></div></div>
    <h2>Darstellung</h2>
    <div class="zwei"><div class="g"><label>Akzentfarbe</label><input name="farbe" type="color" value="<?= e($lay['farbe'] ?? $betrieb['farbe_primaer'] ?? '#0B5D4A') ?>" style="height:44px;padding:4px;width:80px"><div class="tipp">Leer = Hausfarbe aus den Betriebsdaten</div></div>
      <div class="g"><label class="tag-haken" style="margin-top:28px"><input type="checkbox" name="zeige_positionen_detail" value="1" <?= (int) ($lay['zeige_positionen_detail'] ?? 1) === 1 ? 'checked' : '' ?>> Menge und Einzelpreis anzeigen</label></div></div>
    <p class="klein" style="margin-bottom:14px">Logo und Firmendaten kommen aus den Betriebsdaten unter Einstellungen.</p>
    <button type="submit">Speichern</button>
  </form>
<?php endif; ?>

<?php
function rMarke(string $s, bool $ueber = false): string
{
    if ($ueber && in_array($s, ['gestellt', 'teilbezahlt', 'mahnung_1', 'mahnung_2'], true)) { return '<span class="marke-rot">überfällig' . ($s !== 'gestellt' && $s !== 'teilbezahlt' ? ' · ' . R_STATUS[$s] : '') . '</span>'; }
    return match ($s) {
        'bezahlt'  => '<span class="marke-ok">bezahlt</span>',
        'entwurf'  => '<span class="marke-test">Entwurf</span>',
        'storniert' => '<span class="marke-aus">storniert</span>',
        default    => '<span class="marke-test">' . e(R_STATUS[$s] ?? $s) . '</span>',
    };
}
