<?php
/**
 * Zeiterfassung: Uebersicht je Monat, Korrektur (nur mit Grund),
 * manueller Eintrag bei vergessenem Check-in, Monatsauswertung je
 * Mitarbeiter als PDF zur Unterschrift, CSV-Export fuers Lohnbuero.
 * Variablen: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

use App\Auth;

$pr = new Pruefung();
$t = $_GET['t'] ?? 'monat';
$monat = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) === 1 ? $_GET['m'] : date('Y-m');
$mVon = $monat . '-01';
$mBis = date('Y-m-t', strtotime($mVon));
$filterMa = (int) ($_GET['ma'] ?? 0);
$zurueck = '/admin/?s=zeiten&m=' . $monat . ($filterMa ? '&ma=' . $filterMa : '');

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    $aktion = $_POST['aktion'] ?? '';

    if ($aktion === 'korrektur') {
        $zid = (int) ($_POST['zeit_id'] ?? 0);
        $z = $db->find('zeiterfassung', $zid);
        $ein = $pr->text('checkin', 16, true, 'Kommen'); $aus = $pr->text('checkout', 16);
        $pause = $pr->int('pause_minuten', false, 0, 480) ?? 0;
        $grund = $pr->text('korrektur_grund', 255, true, 'Grund');
        if ($z === null) { flash('fehler', 'Eintrag nicht gefunden.'); weiter($zurueck); }
        $einTs = $ein ? strtotime($ein) : false; $ausTs = $aus ? strtotime($aus) : false;
        if ($einTs === false) { $pr->fehler['checkin'] = 'Ungültige Zeit.'; }
        if ($aus && ($ausTs === false || $ausTs <= $einTs)) { $pr->fehler['checkout'] = 'Gehen muss nach Kommen liegen.'; }
        if ($pr->ok()) {
            $brutto = $ausTs ? (int) round(($ausTs - $einTs) / 60) : null;
            $netto = $brutto !== null ? max(0, $brutto - $pause) : null;
            $db->update('zeiterfassung', $zid, [
                'checkin_zeit' => date('Y-m-d H:i:s', $einTs), 'checkout_zeit' => $ausTs ? date('Y-m-d H:i:s', $ausTs) : null,
                'checkout_methode' => $ausTs ? 'manuell' : null, 'pause_minuten' => $pause, 'minuten_brutto' => $brutto, 'minuten_netto' => $netto,
                'korrigiert_von' => (int) $user['id'], 'korrektur_grund' => $grund, 'korrigiert_am' => date('Y-m-d H:i:s'),
            ]);
            if ($ausTs) { $db->update('einsaetze', (int) $z['einsatz_id'], ['status' => 'erledigt']); }
            flash('ok', 'Korrektur gespeichert und protokolliert.');
            weiter($zurueck);
        }
        flash('fehler', 'Korrektur: ' . implode(' ', $pr->fehler));
        weiter($zurueck);
    }

    if ($aktion === 'manuell') {
        $eid = $pr->int('einsatz_id', true, 1); $mid = $pr->int('mitarbeiter_id', true, 1);
        $ein = $pr->text('checkin', 16, true, 'Kommen'); $aus = $pr->text('checkout', 16, true, 'Gehen');
        $pause = $pr->int('pause_minuten', false, 0, 480) ?? 0;
        $grund = $pr->text('korrektur_grund', 255, true, 'Grund');
        $einTs = $ein ? strtotime($ein) : false; $ausTs = $aus ? strtotime($aus) : false;
        if ($einTs === false || $ausTs === false || $ausTs <= $einTs) { $pr->fehler['checkout'] = 'Zeiten prüfen.'; }
        if ($pr->ok() && $db->find('einsaetze', $eid) !== null && $db->find('mitarbeiter', $mid) !== null) {
            $vorh = $db->rawOne('SELECT id FROM zeiterfassung WHERE einsatz_id = ? AND mitarbeiter_id = ?', [$eid, $mid]);
            if ($vorh !== null) { flash('fehler', 'Für diesen Einsatz gibt es bereits eine Zeit — bitte korrigieren statt neu anlegen.'); weiter($zurueck); }
            $brutto = (int) round(($ausTs - $einTs) / 60); $netto = max(0, $brutto - $pause);
            $db->insert('zeiterfassung', [
                'einsatz_id' => $eid, 'mitarbeiter_id' => $mid, 'checkin_zeit' => date('Y-m-d H:i:s', $einTs), 'checkin_methode' => 'manuell',
                'checkout_zeit' => date('Y-m-d H:i:s', $ausTs), 'checkout_methode' => 'manuell', 'pause_minuten' => $pause,
                'minuten_brutto' => $brutto, 'minuten_netto' => $netto,
                'korrigiert_von' => (int) $user['id'], 'korrektur_grund' => $grund, 'korrigiert_am' => date('Y-m-d H:i:s'),
            ]);
            // Mitarbeiter dem Einsatz zuteilen, falls noch nicht
            if ($db->rawOne('SELECT id FROM einsatz_mitarbeiter WHERE einsatz_id = ? AND mitarbeiter_id = ?', [$eid, $mid]) === null) {
                $db->insert('einsatz_mitarbeiter', ['einsatz_id' => $eid, 'mitarbeiter_id' => $mid, 'ist_ersatz' => 1]);
            }
            $db->update('einsaetze', $eid, ['status' => 'erledigt']);
            flash('ok', 'Zeit nachgetragen und protokolliert.');
        } else {
            flash('fehler', 'Nachtrag: ' . implode(' ', $pr->fehler ?: ['Einsatz oder Mitarbeiter unbekannt.']));
        }
        weiter($zurueck);
    }

    if ($aktion === 'monatsabschluss') {
        $mid = (int) ($_POST['mitarbeiter_id'] ?? 0);
        $m = $db->find('mitarbeiter', $mid);
        if ($m === null) { flash('fehler', 'Mitarbeiter nicht gefunden.'); weiter($zurueck); }
        [$jahr, $mon] = array_map('intval', explode('-', $monat));
        $k = monatsKennzahlen($db, $m, $monat);
        $db->raw(
            'INSERT INTO stundenkonto (betrieb_id, mitarbeiter_id, jahr, monat, soll_minuten, ist_minuten, differenz_minuten)
             VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE soll_minuten = VALUES(soll_minuten), ist_minuten = VALUES(ist_minuten), differenz_minuten = VALUES(differenz_minuten)',
            [$db->tenant(), $mid, $jahr, $mon, $k['soll'], $k['ist'], $k['ist'] - $k['soll']]
        );
        flash('ok', 'Monat festgehalten. Das PDF zur Unterschrift kann jetzt erzeugt werden.');
        weiter($zurueck . '&ma=' . $mid);
    }
}

// =====================================================================
// PDF Monatsauswertung
// =====================================================================
if ($t === 'pdf'):
    $mid = (int) ($_GET['ma'] ?? 0);
    $m = $db->find('mitarbeiter', $mid);
    if ($m === null) { flash('fehler', 'Mitarbeiter nicht gefunden.'); weiter($zurueck); }
    $k = monatsKennzahlen($db, $m, $monat);
    $zeilen = zeitenLaden($db, $mVon, $mBis, $mid);
    $mon = new DateTimeImmutable($mVon);
    $MON = ['Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    $tab = '';
    foreach ($zeilen as $z) {
        $tab .= '<tr><td>' . e(date('D d.m.', strtotime($z['datum']))) . '</td><td>' . e($z['objekt']) . '</td><td>' . e(date('H:i', strtotime($z['checkin_zeit']))) . '</td><td>' . ($z['checkout_zeit'] ? e(date('H:i', strtotime($z['checkout_zeit']))) : '—') . '</td><td class="r">' . (int) $z['pause_minuten'] . '</td><td class="r">' . ($z['minuten_netto'] !== null ? minStd((int) $z['minuten_netto']) : '—') . '</td><td class="k">' . ($z['korrektur_grund'] ? 'korrigiert: ' . e($z['korrektur_grund']) : '') . '</td></tr>';
    }
    $html = '<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8"><style>
      @page{margin:18mm 16mm 22mm}body{font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;color:#13201B;line-height:1.4}
      .kopf{display:flex;justify-content:space-between;border-bottom:.8pt solid #0B5D4A;padding-bottom:3mm;margin-bottom:5mm}
      h1{font-size:15pt;margin:0 0 1mm;color:#0B5D4A}.sub{color:#4E5C55;font-size:9pt}
      table{width:100%;border-collapse:collapse;margin-top:3mm}th{text-align:left;font-size:8pt;color:#4E5C55;border-bottom:.6pt solid #0B5D4A;padding:1.5mm 1mm;font-weight:normal}
      td{padding:1.8mm 1mm;border-bottom:.3pt solid #DDE3DE;vertical-align:top}.r{text-align:right}.k{font-size:8pt;color:#4E5C55}
      .summe{margin-top:5mm;width:80mm;margin-left:auto}.summe td{border:0;padding:1mm}.summe .g td{border-top:.8pt solid #0B5D4A;font-weight:bold;font-size:11pt}
      .hinweis{margin-top:6mm;font-size:8.5pt;color:#4E5C55}
      .unterschrift{margin-top:16mm;display:flex;gap:20mm}.unterschrift div{flex:1;border-top:.6pt solid #13201B;padding-top:2mm;font-size:8.5pt;color:#4E5C55}
    </style></head><body>
      <div class="kopf"><div><h1>Arbeitszeitaufzeichnung ' . e($MON[(int) $mon->format('n') - 1]) . ' ' . $mon->format('Y') . '</h1><div class="sub">' . e($m['vorname'] . ' ' . $m['nachname']) . ' · Personalnummer ' . e($m['personalnummer']) . ' · ' . e(ANSTELLUNG[$m['anstellung']] ?? '') . ', ' . number_format((float) $m['stunden_woche'], 1, ',', '') . ' Std/Woche</div></div><div class="sub" style="text-align:right">' . e($betrieb['name']) . '<br>Aufzeichnung gemäß § 26 AZG</div></div>
      <table><tr><th>Tag</th><th>Objekt</th><th>Kommen</th><th>Gehen</th><th class="r">Pause</th><th class="r">Netto</th><th></th></tr>' . ($tab ?: '<tr><td colspan="7">Keine Einsätze in diesem Monat.</td></tr>') . '</table>
      <table class="summe"><tr><td>Sollstunden</td><td class="r">' . minStd($k['soll']) . '</td></tr><tr><td>Geleistete Stunden</td><td class="r">' . minStd($k['ist']) . '</td></tr><tr class="g"><td>Differenz</td><td class="r">' . ($k['ist'] - $k['soll'] >= 0 ? '+' : '−') . minStd(abs($k['ist'] - $k['soll'])) . '</td></tr></table>
      <p class="hinweis">Sollstunden aus Wochenstunden und Arbeitstagen des Monats, abzüglich genehmigter Abwesenheiten (' . e($k['abwesend_tage']) . ' Tage). Korrekturen sind mit Grund vermerkt. Der Mitarbeiter hat Anspruch auf Übermittlung dieser Aufzeichnung.</p>
      <div class="unterschrift"><div>Datum, Unterschrift Mitarbeiter</div><div>Datum, Unterschrift Betrieb</div></div>
    </body></html>';
    require_once __DIR__ . '/../../system/lib/dompdf/autoload.inc.php';
    $opt = new Dompdf\Options(); $opt->set('isRemoteEnabled', false); $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('fontDir', __DIR__ . '/../../system/lib/dompdf/vendor/dompdf/dompdf/lib/fonts'); $opt->set('fontCache', __DIR__ . '/../../system/rechnungen'); $opt->set('chroot', __DIR__ . '/../../system');
    if (!is_dir(__DIR__ . '/../../system/rechnungen')) { @mkdir(__DIR__ . '/../../system/rechnungen', 0750, true); }
    $pdf = new Dompdf\Dompdf($opt); $pdf->setPaper('A4'); $pdf->loadHtml($html, 'UTF-8'); $pdf->render();
    ob_end_clean();
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Stunden-' . e($m['personalnummer']) . '-' . $monat . '.pdf"');
    echo $pdf->output(); exit;
endif;

// =====================================================================
// CSV Export (alle Mitarbeiter, Monat) fuers Lohnbuero
// =====================================================================
if ($t === 'csv'):
    $mas = $db->select('mitarbeiter', '', [], 'ORDER BY nachname, vorname');
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="Stunden-' . $monat . '.csv"');
    echo "\xEF\xBB\xBF";   // BOM fuer Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Personalnummer', 'Nachname', 'Vorname', 'Anstellung', 'Std/Woche', 'Lohngruppe', 'Sollstunden', 'Iststunden', 'Differenz', 'Abwesenheitstage', 'Einsätze'], ';');
    foreach ($mas as $m) {
        $k = monatsKennzahlen($db, $m, $monat);
        if ($k['ist'] === 0 && (int) $m['aktiv'] === 0) { continue; }
        fputcsv($out, [$m['personalnummer'], $m['nachname'], $m['vorname'], ANSTELLUNG[$m['anstellung']] ?? '', number_format((float) $m['stunden_woche'], 2, ',', ''), $user['rolle'] === 'inhaber' ? ($m['lohngruppe'] ?? '') : '', number_format($k['soll'] / 60, 2, ',', ''), number_format($k['ist'] / 60, 2, ',', ''), number_format(($k['ist'] - $k['soll']) / 60, 2, ',', ''), $k['abwesend_tage'], $k['einsaetze']], ';');
    }
    fclose($out); exit;
endif;

// =====================================================================
// MONATSÜBERSICHT
// =====================================================================
$mas = $db->select('mitarbeiter', 'aktiv = 1', [], 'ORDER BY nachname, vorname');
$zeilen = zeitenLaden($db, $mVon, $mBis, $filterMa);
$offen = array_filter($zeilen, static fn($z) => $z['checkout_zeit'] === null);
$konten = [];
foreach ($db->select('stundenkonto', 'jahr = ? AND monat = ?', [(int) substr($monat, 0, 4), (int) substr($monat, 5, 2)]) as $k) { $konten[(int) $k['mitarbeiter_id']] = $k; }
$vorMonat = date('Y-m', strtotime($mVon . ' -1 month')); $nachMonat = date('Y-m', strtotime($mVon . ' +1 month'));
$MON = ['Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
// Einsaetze ohne Zeit (fuer Nachtrag)
$ohneZeit = $db->rawAll(
    "SELECT e.id, e.datum, e.uhrzeit_von, o.name AS objekt, m.id AS mitarbeiter_id, CONCAT(m.vorname,' ',m.nachname) AS name
       FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id JOIN einsatz_mitarbeiter em ON em.einsatz_id = e.id JOIN mitarbeiter m ON m.id = em.mitarbeiter_id
      WHERE e.betrieb_id = ? AND e.datum BETWEEN ? AND ? AND e.datum <= CURDATE() AND e.status IN ('geplant','laeuft','erledigt')
        AND NOT EXISTS (SELECT 1 FROM zeiterfassung z WHERE z.einsatz_id = e.id AND z.mitarbeiter_id = m.id)" . ($filterMa ? ' AND m.id = ?' : '') . "
      ORDER BY e.datum DESC, e.uhrzeit_von LIMIT 60", $filterMa ? [$db->tenant(), $mVon, $mBis, $filterMa] : [$db->tenant(), $mVon, $mBis]);
?>
<div class="kopfzeile">
  <h1>Zeiterfassung</h1>
  <div class="zeile-knoepfe">
    <a class="knopf zweit" href="/admin/?s=zeiten&amp;t=csv&amp;m=<?= $monat ?>">Export Lohnbüro (CSV)</a>
  </div>
</div>
<div class="wochen-nav">
  <a class="knopf zweit mini" href="/admin/?s=zeiten&amp;m=<?= $vorMonat ?>&amp;ma=<?= $filterMa ?>">← <?= e($MON[(int) substr($vorMonat, 5, 2) - 1]) ?></a>
  <a class="knopf zweit mini" href="/admin/?s=zeiten&amp;m=<?= $nachMonat ?>&amp;ma=<?= $filterMa ?>"><?= e($MON[(int) substr($nachMonat, 5, 2) - 1]) ?> →</a>
  <b><?= e($MON[(int) substr($monat, 5, 2) - 1]) ?> <?= substr($monat, 0, 4) ?></b>
  <form method="get" class="inl filter" style="margin:0 0 0 auto"><input type="hidden" name="s" value="zeiten"><input type="hidden" name="m" value="<?= $monat ?>">
    <select name="ma" onchange="this.form.submit()" aria-label="Mitarbeiter"><option value="0">Alle Mitarbeiter</option><?php foreach ($mas as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $filterMa === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nachname'] . ' ' . $m['vorname']) ?></option><?php endforeach; ?></select></form>
</div>

<h2 style="margin-top:6px">Monatsstand je Mitarbeiter</h2>
<table>
  <thead><tr><th>Mitarbeiter</th><th class="r">Soll</th><th class="r">Ist</th><th class="r">Differenz</th><th class="r">Einsätze</th><th>Abschluss</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($mas as $m): if ($filterMa && (int) $m['id'] !== $filterMa) continue; $k = monatsKennzahlen($db, $m, $monat); $kt = $konten[(int) $m['id']] ?? null; $diff = $k['ist'] - $k['soll']; ?>
    <tr>
      <td><a href="/admin/?s=zeiten&amp;m=<?= $monat ?>&amp;ma=<?= (int) $m['id'] ?>"><?= e($m['nachname'] . ', ' . $m['vorname']) ?></a></td>
      <td class="r"><?= minStd($k['soll']) ?></td><td class="r"><b><?= minStd($k['ist']) ?></b></td>
      <td class="r <?= $diff < -60 ? 'warn-text' : '' ?>"><?= ($diff >= 0 ? '+' : '−') . minStd(abs($diff)) ?></td>
      <td class="r"><?= $k['einsaetze'] ?></td>
      <td><?= $kt ? ($kt['unterschrieben_am'] ? '<span class="marke-ok">unterschrieben ' . e(date('d.m.', strtotime($kt['unterschrieben_am']))) . '</span>' : '<span class="marke-test">festgehalten</span>') : '<span class="klein">offen</span>' ?></td>
      <td class="r nowrap">
        <form method="post" class="inl"><input type="hidden" name="aktion" value="monatsabschluss"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="mitarbeiter_id" value="<?= (int) $m['id'] ?>"><button class="mini zweit"><?= $kt ? 'Neu berechnen' : 'Festhalten' ?></button></form>
        <a class="knopf-link" href="/admin/?s=zeiten&amp;t=pdf&amp;m=<?= $monat ?>&amp;ma=<?= (int) $m['id'] ?>" target="_blank">PDF</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="klein" style="margin-top:8px">Soll = Wochenstunden ÷ Arbeitstage × Arbeitstage des Monats, abzüglich genehmigter Abwesenheiten. „Festhalten" speichert den Stand; das PDF dient der Unterschrift durch den Mitarbeiter.</p>

<?php if ($offen !== []): ?>
<h2>Nicht ausgestempelt</h2>
<div class="meldung fehler"><?= count($offen) ?> Einsatz<?= count($offen) === 1 ? '' : 'e' ?> mit Kommen, aber ohne Gehen. Unten korrigieren.</div>
<?php endif; ?>

<h2>Einzelne Zeiten</h2>
<?php if ($zeilen === []): ?><p class="leer">Keine Zeiten in diesem Monat.</p><?php else: ?>
<table>
  <thead><tr><th>Tag</th><th>Mitarbeiter</th><th>Objekt</th><th>Kommen</th><th>Gehen</th><th class="r">Pause</th><th class="r">Netto</th><th class="r">Soll</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($zeilen as $z): $bearb = (int) ($_GET['k'] ?? 0) === (int) $z['id']; ?>
    <tr class="<?= $z['checkout_zeit'] === null ? 'zeile-offen' : '' ?>">
      <td><?= e(date('d.m.', strtotime($z['datum']))) ?></td>
      <td><?= e($z['name']) ?></td>
      <td><?= e($z['objekt']) ?></td>
      <td><?= e(date('H:i', strtotime($z['checkin_zeit']))) ?> <span class="klein"><?= e($z['checkin_methode']) ?></span></td>
      <td><?= $z['checkout_zeit'] ? e(date('H:i', strtotime($z['checkout_zeit']))) : '<span class="warn-text">offen</span>' ?></td>
      <td class="r"><?= (int) $z['pause_minuten'] ?></td>
      <td class="r"><b><?= $z['minuten_netto'] !== null ? minStd((int) $z['minuten_netto']) : '—' ?></b><?= $z['korrektur_grund'] ? '<div class="klein" title="' . e($z['korrektur_grund']) . '">korrigiert</div>' : '' ?></td>
      <td class="r klein"><?= minStd((int) $z['dauer_soll_minuten']) ?></td>
      <td class="r"><a class="knopf-link" href="<?= $zurueck ?>&amp;k=<?= (int) $z['id'] ?>#korr">Korrigieren</a></td>
    </tr>
    <?php if ($bearb): ?>
    <tr><td colspan="9" style="background:var(--papier)">
      <form method="post" class="korrektur" id="korr">
        <input type="hidden" name="aktion" value="korrektur"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="zeit_id" value="<?= (int) $z['id'] ?>">
        <div><label>Kommen</label><input name="checkin" type="datetime-local" value="<?= e(date('Y-m-d\TH:i', strtotime($z['checkin_zeit']))) ?>" required></div>
        <div><label>Gehen</label><input name="checkout" type="datetime-local" value="<?= $z['checkout_zeit'] ? e(date('Y-m-d\TH:i', strtotime($z['checkout_zeit']))) : '' ?>"></div>
        <div><label>Pause Min</label><input name="pause_minuten" type="number" min="0" max="480" value="<?= (int) $z['pause_minuten'] ?>"></div>
        <div style="flex:2"><label>Grund (Pflicht)</label><input name="korrektur_grund" maxlength="255" required placeholder="z. B. Check-out vergessen, laut Mitarbeiter 20:05"></div>
        <div><label>&nbsp;</label><button type="submit">Speichern</button></div>
      </form>
    </td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($ohneZeit !== []): ?>
<h2>Nachtragen</h2>
<p class="klein" style="margin-bottom:10px">Einsätze im Monat ohne Zeiterfassung. Nachträge werden als manuell gekennzeichnet und protokolliert.</p>
<form method="post" class="formular" style="max-width:900px">
  <input type="hidden" name="aktion" value="manuell"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <div class="g"><label for="ne">Einsatz und Mitarbeiter</label><select id="ne" name="einsatz_paar" onchange="var v=this.value.split('|');this.form.einsatz_id.value=v[0];this.form.mitarbeiter_id.value=v[1];var d=v[2];this.form.checkin.value=d+'T'+v[3];this.form.checkout.value=d+'T'+v[4];">
    <?php foreach ($ohneZeit as $o): $von = zeitKurz($o['uhrzeit_von']); $bis = date('H:i', strtotime($o['datum'] . ' ' . $o['uhrzeit_von']) + 7200); ?>
      <option value="<?= (int) $o['id'] ?>|<?= (int) $o['mitarbeiter_id'] ?>|<?= e($o['datum']) ?>|<?= e($von) ?>|<?= e($bis) ?>"><?= e(date('d.m.', strtotime($o['datum']))) ?> <?= e($von) ?> · <?= e($o['objekt']) ?> · <?= e($o['name']) ?></option>
    <?php endforeach; ?>
  </select></div>
  <input type="hidden" name="einsatz_id" value="<?= (int) $ohneZeit[0]['id'] ?>"><input type="hidden" name="mitarbeiter_id" value="<?= (int) $ohneZeit[0]['mitarbeiter_id'] ?>">
  <div class="drei">
    <div class="g"><label>Kommen</label><input name="checkin" type="datetime-local" value="<?= e($ohneZeit[0]['datum'] . 'T' . zeitKurz($ohneZeit[0]['uhrzeit_von'])) ?>" required></div>
    <div class="g"><label>Gehen</label><input name="checkout" type="datetime-local" value="<?= e($ohneZeit[0]['datum'] . 'T' . date('H:i', strtotime($ohneZeit[0]['datum'] . ' ' . $ohneZeit[0]['uhrzeit_von']) + 7200)) ?>" required></div>
    <div class="g"><label>Pause Min</label><input name="pause_minuten" type="number" min="0" max="480" value="0"></div>
  </div>
  <div class="g"><label>Grund (Pflicht)</label><input name="korrektur_grund" maxlength="255" required placeholder="z. B. Handy defekt, Zeiten laut Mitarbeiter"></div>
  <button type="submit">Nachtragen</button>
</form>
<?php endif; ?>

<?php
// =====================================================================
// Hilfsfunktionen
// =====================================================================
function zeitenLaden(App\Database $db, string $von, string $bis, int $ma): array
{
    $params = [$db->tenant(), $von, $bis];
    $w = '';
    if ($ma > 0) { $w = ' AND z.mitarbeiter_id = ?'; $params[] = $ma; }
    return $db->rawAll(
        "SELECT z.*, e.datum, e.dauer_soll_minuten, o.name AS objekt, CONCAT(m.vorname,' ',m.nachname) AS name
           FROM zeiterfassung z JOIN einsaetze e ON e.id = z.einsatz_id JOIN objekte o ON o.id = e.objekt_id JOIN mitarbeiter m ON m.id = z.mitarbeiter_id
          WHERE z.betrieb_id = ? AND e.datum BETWEEN ? AND ?{$w} ORDER BY e.datum, z.checkin_zeit", $params);
}

/** Soll/Ist in Minuten fuer einen Monat. Soll = Wochenstunden/Arbeitstage × Arbeitstage im Monat − Abwesenheitstage. */
function monatsKennzahlen(App\Database $db, array $m, string $monat): array
{
    $von = $monat . '-01'; $bis = date('Y-m-t', strtotime($von));
    // Arbeitstage Mo–Fr (bzw. laut arbeitstage_woche anteilig)
    $arbeitstage = 0;
    for ($d = strtotime($von); $d <= strtotime($bis); $d += 86400) { if ((int) date('N', $d) <= 5) { $arbeitstage++; } }
    $tageProWoche = max(1, min(7, (int) $m['arbeitstage_woche']));
    $arbeitstage = (int) round($arbeitstage * $tageProWoche / 5);
    $abw = (float) ($db->rawOne(
        "SELECT IFNULL(SUM(LEAST(tage, DATEDIFF(LEAST(datum_bis, ?), GREATEST(datum_von, ?)) + 1)),0) s FROM abwesenheiten
          WHERE mitarbeiter_id = ? AND betrieb_id = ? AND status = 'genehmigt' AND datum_von <= ? AND datum_bis >= ?",
        [$bis, $von, (int) $m['id'], $db->tenant(), $bis, $von])['s'] ?? 0);
    $minProTag = (float) $m['stunden_woche'] * 60 / $tageProWoche;
    $soll = (int) round(max(0, $arbeitstage - $abw) * $minProTag);
    $ist = $db->rawOne(
        'SELECT IFNULL(SUM(z.minuten_netto),0) s, COUNT(*) n FROM zeiterfassung z JOIN einsaetze e ON e.id = z.einsatz_id
          WHERE z.mitarbeiter_id = ? AND z.betrieb_id = ? AND e.datum BETWEEN ? AND ? AND z.checkout_zeit IS NOT NULL',
        [(int) $m['id'], $db->tenant(), $von, $bis]);
    return ['soll' => $soll, 'ist' => (int) ($ist['s'] ?? 0), 'einsaetze' => (int) ($ist['n'] ?? 0), 'abwesend_tage' => number_format($abw, 1, ',', '')];
}

function minStd(int $min): string
{
    return sprintf('%d:%02d', intdiv($min, 60), $min % 60);
}
