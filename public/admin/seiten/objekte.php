<?php
/**
 * Objekte: Liste, Anlage, Bearbeitung, Detail mit Leistungen (Turnus),
 * Stamm-Mitarbeitern, verschluesselten Zugangsdaten, QR-Schild.
 * Variablen: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

use App\Auth;
use App\Crypto;

$t   = $_GET['t'] ?? 'liste';        // liste | neu | detail | bearbeiten | schild
$id  = (int) ($_GET['id'] ?? 0);
$pr  = new Pruefung();
$satz = null;
$istInhaber = $user['rolle'] === 'inhaber';

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    $aktion = $_POST['aktion'] ?? '';

    if ($aktion === 'speichern') {
        $kundeId = $pr->int('kunde_id', true, 1);
        if ($kundeId !== null && $db->find('kunden', $kundeId) === null) {
            $pr->fehler['kunde_id'] = 'Kunde nicht gefunden.';
        }
        $daten = [
            'kunde_id'           => $kundeId,
            'name'               => $pr->text('name', 150, true, 'Bezeichnung'),
            'adresse'            => $pr->text('adresse', 150, true, 'Adresse'),
            'plz'                => $pr->text('plz', 10, true, 'PLZ'),
            'ort'                => $pr->text('ort', 80, true, 'Ort'),
            'ansprechpartner'    => $pr->text('ansprechpartner', 120),
            'telefon'            => $pr->text('telefon', 40),
            'email'              => $pr->email('email'),
            'zugang_info'        => $pr->text('zugang_info', 2000),
            'reinigung_hinweise' => $pr->text('reinigung_hinweise', 4000),
            'aktiv'              => $pr->haken('aktiv'),
        ];
        // Verschluesselte Felder nur vom Inhaber
        $schluessel = $istInhaber ? $pr->text('schluessel_info', 500) : null;
        $alarm      = $istInhaber ? $pr->text('alarm_code', 60) : null;
        $zugangAendern = $istInhaber && !empty($_POST['zugang_aendern']);

        if ($pr->ok()) {
            $db->transaction(static function () use ($db, $id, $daten, $zugangAendern, $schluessel, $alarm, &$ziel): void {
                if ($id > 0) {
                    $db->update('objekte', $id, $daten);
                    $ziel = $id;
                } else {
                    $daten['qr_token'] = Crypto::token(16);
                    $ziel = $db->insert('objekte', $daten);
                }
                if ($zugangAendern || $id === 0) {
                    if ($schluessel !== null || $alarm !== null) {
                        $db->raw(
                            'INSERT INTO objekt_zugang (objekt_id, betrieb_id, schluessel_info_enc, alarm_code_enc) VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE schluessel_info_enc = VALUES(schluessel_info_enc), alarm_code_enc = VALUES(alarm_code_enc)',
                            [$ziel, $db->tenant(), Crypto::encrypt($schluessel), Crypto::encrypt($alarm)]
                        );
                    } elseif ($zugangAendern) {
                        $db->raw('DELETE FROM objekt_zugang WHERE objekt_id = ? AND betrieb_id = ?', [$ziel, $db->tenant()]);
                    }
                }
            });
            flash('ok', $id > 0 ? 'Objekt gespeichert.' : 'Objekt angelegt. Als Nächstes die Leistungen eintragen.');
            weiter('/admin/?s=objekte&t=detail&id=' . $ziel);
        }
        $t = $id > 0 ? 'bearbeiten' : 'neu';
    }

    if ($aktion === 'leistung' && $id > 0 && $db->find('objekte', $id) !== null) {
        $lid = (int) ($_POST['leistung_id'] ?? 0);   // 0 = neu
        $la  = $pr->int('leistungsart_id', true, 1);
        if ($la !== null && $db->find('leistungsarten', $la) === null) {
            $pr->fehler['leistungsart_id'] = 'Leistungsart unbekannt.';
        }
        $turnus = $pr->auswahl('turnus', array_keys(TURNUS), true);
        $tage   = $pr->wochentage();
        if ($turnus !== 'einmalig' && $tage === 0) {
            $pr->fehler['tag'] = 'Mindestens einen Wochentag wählen.';
        }
        $daten = [
            'leistungsart_id'    => $la,
            'turnus'             => $turnus ?? 'woechentlich',
            'wochentage'         => $tage,
            'uhrzeit_von'        => $pr->zeit('uhrzeit_von', true),
            'uhrzeit_bis'        => $pr->zeit('uhrzeit_bis', true),
            'dauer_soll_minuten' => $pr->int('dauer_soll_minuten', true, 5, 1440),
            'stundensatz'        => $pr->decimal('stundensatz', false, 0),
            'preis_monat'        => $pr->decimal('preis_monat', false, 0),
            'preis_einheit'      => $pr->decimal('preis_einheit', false, 0),
            'gueltig_von'        => $pr->datum('gueltig_von'),
            'gueltig_bis'        => $pr->datum('gueltig_bis'),
            'aktiv'              => 1,
        ];
        if ($daten['uhrzeit_von'] && $daten['uhrzeit_bis'] && $daten['uhrzeit_bis'] <= $daten['uhrzeit_von']) {
            $pr->fehler['uhrzeit_bis'] = 'Ende muss nach dem Beginn liegen.';
        }
        $team = array_map('intval', (array) ($_POST['team'] ?? []));

        if ($pr->ok()) {
            $db->transaction(static function () use ($db, $id, $lid, $daten, $team): void {
                if ($lid > 0) {
                    $db->update('objekt_leistungen', $lid, $daten);
                    $olid = $lid;
                } else {
                    $daten['objekt_id'] = $id;
                    $olid = $db->insert('objekt_leistungen', $daten);
                }
                $db->raw('DELETE FROM objekt_leistung_mitarbeiter WHERE objekt_leistung_id = ? AND betrieb_id = ?', [$olid, $db->tenant()]);
                $r = 1;
                foreach ($team as $mid) {
                    if ($db->find('mitarbeiter', $mid) !== null) {
                        $db->insert('objekt_leistung_mitarbeiter', ['objekt_leistung_id' => $olid, 'mitarbeiter_id' => $mid, 'reihenfolge' => $r++]);
                    }
                }
            });
            flash('ok', 'Leistung gespeichert.');
            weiter('/admin/?s=objekte&t=detail&id=' . $id);
        }
        $leistungFehler = $pr->fehler;
        $leistungPost   = $_POST;
    }

    if ($aktion === 'leistung_beenden' && $id > 0) {
        $lid = (int) ($_POST['leistung_id'] ?? 0);
        $db->update('objekt_leistungen', $lid, ['aktiv' => 0, 'gueltig_bis' => date('Y-m-d')]);
        flash('ok', 'Leistung beendet. Bereits geplante Einsätze bleiben bestehen.');
        weiter('/admin/?s=objekte&t=detail&id=' . $id);
    }

    if ($aktion === 'qr_neu' && $id > 0 && $istInhaber) {
        $db->update('objekte', $id, ['qr_token' => Crypto::token(16), 'nfc_token' => null]);
        flash('ok', 'Neuer Code erzeugt. Das alte Schild ist ab sofort ungültig — bitte neues drucken.');
        weiter('/admin/?s=objekte&t=detail&id=' . $id);
    }
}

// ---------------------------------------------------------------------
// Daten laden
// ---------------------------------------------------------------------
if (in_array($t, ['detail', 'bearbeiten', 'schild'], true)) {
    $satz = $db->rawOne(
        'SELECT o.*, k.firmenname AS kunde_name FROM objekte o JOIN kunden k ON k.id = o.kunde_id WHERE o.id = ? AND o.betrieb_id = ?',
        [$id, $db->tenant()]
    );
    if ($satz === null) {
        flash('fehler', 'Objekt nicht gefunden.');
        weiter('/admin/?s=objekte');
    }
}
$kunden = $db->select('kunden', "status <> 'inaktiv'", [], 'ORDER BY firmenname');
$leistungsarten = $db->select('leistungsarten', 'aktiv = 1', [], 'ORDER BY sortierung, name');
$mitarbeiter = $db->select('mitarbeiter', 'aktiv = 1', [], 'ORDER BY nachname, vorname');

// =====================================================================
// QR-SCHILD (PDF)
// =====================================================================
if ($t === 'schild'):
    require_once __DIR__ . '/../../system/lib/qrcode/autoload.php';
    require_once __DIR__ . '/../../system/lib/dompdf/autoload.inc.php';
    $url = rtrim($config['app']['url'], '/') . '/app/?c=' . $satz['qr_token'];
    // PNG als eingebettetes Bild — dompdf rendert Inline-SVG nicht zuverlaessig
    $qo = new chillerlan\QRCode\QROptions([
        'outputType' => chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG, 'eccLevel' => chillerlan\QRCode\QRCode::ECC_M,
        'scale' => 12, 'addQuietzone' => false, 'outputBase64' => true, 'imageTransparent' => false,
    ]);
    $bild = (new chillerlan\QRCode\QRCode($qo))->render($url);   // data:image/png;base64,...
    $svg = '<img src="' . $bild . '" alt="QR-Code" width="310" height="310">';
    $html = '<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8"><style>
      @page{size:A5 portrait;margin:14mm}
      body{font-family:"DejaVu Sans",sans-serif;color:#13201B;text-align:center}
      .betrieb{font-size:9pt;color:#4E5C55;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6mm}
      h1{font-size:17pt;margin:0 0 2mm;line-height:1.2}
      .adr{font-size:10.5pt;color:#4E5C55;margin-bottom:8mm}
      .qr{width:82mm;height:82mm;margin:0 auto 7mm}
      .qr img{width:82mm;height:82mm}
      .anl{font-size:10pt;line-height:1.6;color:#13201B;text-align:left;margin:0 auto;width:96mm}
      .anl b{display:block;margin-bottom:2mm;font-size:10.5pt}
      .fuss{position:fixed;bottom:0;left:0;right:0;font-size:7.5pt;color:#8A968A}
    </style></head><body>
      <div class="betrieb">' . e($betrieb['name']) . '</div>
      <h1>' . e($satz['name']) . '</h1>
      <div class="adr">' . e($satz['adresse']) . ', ' . e($satz['plz']) . ' ' . e($satz['ort']) . '</div>
      <div class="qr">' . $svg . '</div>
      <div class="anl"><b>Für das Reinigungsteam</b>
        1. App öffnen, Einsatz auswählen.<br>
        2. Diesen Code beim Ankommen scannen.<br>
        3. Beim Gehen erneut scannen.</div>
      <div class="fuss">Objekt ' . (int) $satz['id'] . ' · Schild bei Verlust im Büro sperren lassen</div>
    </body></html>';
    $opt = new Dompdf\Options();
    $opt->set('isRemoteEnabled', false); $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('fontDir', __DIR__ . '/../../system/lib/dompdf/vendor/dompdf/dompdf/lib/fonts');
    $opt->set('fontCache', __DIR__ . '/../../system/rechnungen'); $opt->set('chroot', __DIR__ . '/../../system');
    if (!is_dir(__DIR__ . '/../../system/rechnungen')) { @mkdir(__DIR__ . '/../../system/rechnungen', 0750, true); }
    $pdf = new Dompdf\Dompdf($opt);
    $pdf->setPaper('A5'); $pdf->loadHtml($html, 'UTF-8'); $pdf->render();
    ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="QR-Schild-' . preg_replace('/[^A-Za-z0-9]+/', '-', $satz['name']) . '.pdf"');
    echo $pdf->output();
    exit;
endif;

// =====================================================================
// LISTE
// =====================================================================
if ($t === 'liste'):
    $suche = trim((string) ($_GET['q'] ?? ''));
    $zeigeInaktive = ($_GET['f'] ?? '') === 'alle';
    $where = $zeigeInaktive ? '1=1' : 'o.aktiv = 1'; $params = [$db->tenant()];
    if ($suche !== '') { $where .= ' AND (o.name LIKE ? OR o.adresse LIKE ? OR k.firmenname LIKE ?)'; array_push($params, "%{$suche}%", "%{$suche}%", "%{$suche}%"); }
    $objekte = $db->rawAll(
        "SELECT o.*, k.firmenname AS kunde_name,
                (SELECT COUNT(*) FROM objekt_leistungen ol WHERE ol.objekt_id = o.id AND ol.aktiv = 1) AS leistungen,
                (SELECT GROUP_CONCAT(DISTINCT CONCAT(m.vorname,' ',LEFT(m.nachname,1),'.') ORDER BY m.nachname SEPARATOR ', ')
                   FROM objekt_leistungen ol JOIN objekt_leistung_mitarbeiter olm ON olm.objekt_leistung_id = ol.id
                   JOIN mitarbeiter m ON m.id = olm.mitarbeiter_id WHERE ol.objekt_id = o.id AND ol.aktiv = 1) AS team
           FROM objekte o JOIN kunden k ON k.id = o.kunde_id
          WHERE o.betrieb_id = ? AND {$where} ORDER BY o.aktiv DESC, k.firmenname, o.name LIMIT 300", $params);
?>
<div class="kopfzeile">
  <h1>Objekte</h1>
  <a class="knopf" href="/admin/?s=objekte&amp;t=neu">Objekt anlegen</a>
</div>
<form method="get" class="filter">
  <input type="hidden" name="s" value="objekte">
  <input type="search" name="q" value="<?= e($suche) ?>" placeholder="Objekt, Adresse, Kunde" aria-label="Suche">
  <select name="f" aria-label="Filter"><option value="">Aktive</option><option value="alle" <?= $zeigeInaktive ? 'selected' : '' ?>>Alle</option></select>
  <button type="submit" class="zweit">Suchen</button>
</form>
<?php if ($objekte === []): ?><p class="leer">Keine Objekte gefunden.</p><?php else: ?>
<table>
  <thead><tr><th>Objekt</th><th>Kunde</th><th>Adresse</th><th class="r">Leistungen</th><th>Stammteam</th></tr></thead>
  <tbody>
  <?php foreach ($objekte as $o): ?>
    <tr class="<?= (int) $o['aktiv'] === 0 ? 'aus' : '' ?>">
      <td><a href="/admin/?s=objekte&amp;t=detail&amp;id=<?= (int) $o['id'] ?>"><?= e($o['name']) ?></a></td>
      <td><?= e($o['kunde_name']) ?></td>
      <td><?= e($o['adresse']) ?><div class="klein"><?= e($o['plz']) ?> <?= e($o['ort']) ?></div></td>
      <td class="r"><?= (int) $o['leistungen'] ?: '<span class="warn-text">0</span>' ?></td>
      <td class="klein"><?= e($o['team'] ?? '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php
// =====================================================================
// DETAIL
// =====================================================================
elseif ($t === 'detail'):
    $leistungen = $db->rawAll(
        'SELECT ol.*, l.name AS leistung_name, l.kategorie,
                (SELECT GROUP_CONCAT(olm.mitarbeiter_id) FROM objekt_leistung_mitarbeiter olm WHERE olm.objekt_leistung_id = ol.id) AS team_ids,
                (SELECT GROUP_CONCAT(CONCAT(m.vorname," ",m.nachname) ORDER BY olm.reihenfolge SEPARATOR ", ")
                   FROM objekt_leistung_mitarbeiter olm JOIN mitarbeiter m ON m.id = olm.mitarbeiter_id WHERE olm.objekt_leistung_id = ol.id) AS team
           FROM objekt_leistungen ol JOIN leistungsarten l ON l.id = ol.leistungsart_id
          WHERE ol.objekt_id = ? AND ol.betrieb_id = ? ORDER BY ol.aktiv DESC, ol.uhrzeit_von', [$id, $db->tenant()]);
    $bearbeiteLeistung = null;
    if (($lid = (int) ($_GET['l'] ?? 0)) > 0) {
        foreach ($leistungen as $l) { if ((int) $l['id'] === $lid) { $bearbeiteLeistung = $l; } }
    }
    $zugang = $istInhaber ? $db->rawOne('SELECT * FROM objekt_zugang WHERE objekt_id = ? AND betrieb_id = ?', [$id, $db->tenant()]) : null;
    $naechste = $db->rawAll('SELECT datum, uhrzeit_von, status FROM einsaetze WHERE objekt_id = ? AND betrieb_id = ? AND datum >= CURDATE() AND status <> \'storniert\' ORDER BY datum, uhrzeit_von LIMIT 5', [$id, $db->tenant()]);
    $lf = $leistungFehler ?? []; $lp = $leistungPost ?? null;
    $formLeistung = $lp ? null : $bearbeiteLeistung;
    $teamGewaehlt = $lp ? array_map('intval', (array) ($lp['team'] ?? [])) : ($bearbeiteLeistung ? array_map('intval', explode(',', (string) $bearbeiteLeistung['team_ids'])) : []);
    $tageGewaehlt = $lp ? array_map('intval', (array) ($lp['tag'] ?? [])) : ($bearbeiteLeistung ? array_keys(array_filter(WOCHENTAGE, static fn($k) => ((int) $bearbeiteLeistung['wochentage']) & (1 << ($k - 1)), ARRAY_FILTER_USE_KEY)) : [1, 3, 5]);
?>
<p class="zurueck"><a href="/admin/?s=objekte">← Objekte</a></p>
<div class="kopfzeile">
  <h1><?= e($satz['name']) ?><?= (int) $satz['aktiv'] === 0 ? ' <span class="marke-aus">inaktiv</span>' : '' ?></h1>
  <div class="zeile-knoepfe">
    <a class="knopf zweit" href="/admin/?s=objekte&amp;t=schild&amp;id=<?= $id ?>" target="_blank">QR-Schild drucken</a>
    <a class="knopf zweit" href="/admin/?s=objekte&amp;t=bearbeiten&amp;id=<?= $id ?>">Bearbeiten</a>
  </div>
</div>

<div class="zweispalt">
  <section>
    <dl class="fakten">
      <dt>Kunde</dt><dd><a href="/admin/?s=kunden&amp;t=detail&amp;id=<?= (int) $satz['kunde_id'] ?>"><?= e($satz['kunde_name']) ?></a></dd>
      <dt>Adresse</dt><dd><?= e($satz['adresse']) ?>, <?= e($satz['plz']) ?> <?= e($satz['ort']) ?></dd>
      <dt>Vor Ort</dt><dd><?= e($satz['ansprechpartner'] ?? '—') ?><?= $satz['telefon'] ? ' · ' . e($satz['telefon']) : '' ?></dd>
      <?php if ($satz['zugang_info']): ?><dt>Zugang</dt><dd><?= nl2br(e($satz['zugang_info'])) ?></dd><?php endif; ?>
      <?php if ($satz['reinigung_hinweise']): ?><dt>Hinweise</dt><dd><?= nl2br(e($satz['reinigung_hinweise'])) ?></dd><?php endif; ?>
      <?php if ($istInhaber): ?>
        <dt>Schlüssel</dt><dd><?= $zugang && $zugang['schluessel_info_enc'] ? e(Crypto::decrypt($zugang['schluessel_info_enc'])) : '—' ?></dd>
        <dt>Alarmcode</dt><dd><?= $zugang && $zugang['alarm_code_enc'] ? '<code>' . e(Crypto::decrypt($zugang['alarm_code_enc'])) . '</code>' : '—' ?> <span class="klein">nur für Inhaber sichtbar</span></dd>
      <?php endif; ?>
    </dl>

    <h2>Leistungen an diesem Objekt</h2>
    <?php if ($leistungen === []): ?><p class="leer">Noch keine Leistung eingetragen. Daraus entstehen die Einsätze im Dienstplan.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Leistung</th><th>Turnus</th><th>Zeit</th><th>Team</th><th class="r">Preis</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($leistungen as $l): ?>
        <tr class="<?= (int) $l['aktiv'] === 0 ? 'aus' : '' ?>">
          <td><b><?= e($l['leistung_name']) ?></b><?= (int) $l['aktiv'] === 0 ? '<div class="klein">beendet ' . datumDe($l['gueltig_bis']) . '</div>' : '' ?></td>
          <td><?= e(TURNUS[$l['turnus']] ?? $l['turnus']) ?><div class="klein"><?= e(wochentageText((int) $l['wochentage'])) ?></div></td>
          <td class="nowrap"><?= e(zeitKurz($l['uhrzeit_von'])) ?>–<?= e(zeitKurz($l['uhrzeit_bis'])) ?><div class="klein"><?= (int) $l['dauer_soll_minuten'] ?> Min Soll</div></td>
          <td class="klein"><?= e($l['team'] ?? '—') ?></td>
          <td class="r nowrap"><?= $l['preis_monat'] ? geld($l['preis_monat']) . '<div class="klein">pro Monat</div>' : ($l['preis_einheit'] ? geld($l['preis_einheit']) . '<div class="klein">pro Einsatz</div>' : ($l['stundensatz'] ? geld($l['stundensatz']) . '<div class="klein">pro Stunde</div>' : '—')) ?></td>
          <td class="r nowrap"><?php if ((int) $l['aktiv'] === 1): ?>
            <a class="knopf mini zweit" href="/admin/?s=objekte&amp;t=detail&amp;id=<?= $id ?>&amp;l=<?= (int) $l['id'] ?>#leistung">Ändern</a>
            <form method="post" class="inl" onsubmit="return confirm('Leistung beenden? Der Dienstplan erzeugt dann keine neuen Einsätze mehr dafür.')"><input type="hidden" name="aktion" value="leistung_beenden"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="leistung_id" value="<?= (int) $l['id'] ?>"><button type="submit" class="mini zweit">Beenden</button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <h2 id="leistung"><?= $bearbeiteLeistung ? 'Leistung ändern' : 'Leistung hinzufügen' ?></h2>
    <form method="post" class="formular">
      <input type="hidden" name="aktion" value="leistung"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="leistung_id" value="<?= (int) ($bearbeiteLeistung['id'] ?? 0) ?>">
      <div class="zwei">
        <div class="g"><label for="la">Leistungsart</label><select id="la" name="leistungsart_id" required>
          <?php foreach ($leistungsarten as $la): ?><option value="<?= (int) $la['id'] ?>" <?= (int) ($lp['leistungsart_id'] ?? $formLeistung['leistungsart_id'] ?? 0) === (int) $la['id'] ? 'selected' : '' ?>><?= e($la['name']) ?></option><?php endforeach; ?>
        </select><?= feldFehler($lf, 'leistungsart_id') ?></div>
        <div class="g"><label for="tu">Turnus</label><select id="tu" name="turnus">
          <?php foreach (TURNUS as $k => $v): ?><option value="<?= $k ?>" <?= ($lp['turnus'] ?? $formLeistung['turnus'] ?? 'woechentlich') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select></div>
      </div>
      <div class="g"><label>Wochentage</label>
        <div class="tage-wahl"><?php foreach (WOCHENTAGE as $n => $k): ?><label class="tag-haken"><input type="checkbox" name="tag[]" value="<?= $n ?>" <?= in_array($n, $tageGewaehlt, true) ? 'checked' : '' ?>> <?= $k ?></label><?php endforeach; ?></div>
        <div class="tipp">Bei 14-tägig und monatlich: Wochentag, an dem die Reinigung stattfindet.</div><?= feldFehler($lf, 'tag') ?></div>
      <div class="drei">
        <div class="g"><label for="uv">Von</label><input id="uv" name="uhrzeit_von" type="time" value="<?= e(zeitKurz($lp['uhrzeit_von'] ?? $formLeistung['uhrzeit_von'] ?? '18:00')) ?>" required><?= feldFehler($lf, 'uhrzeit_von') ?></div>
        <div class="g"><label for="ub">Bis</label><input id="ub" name="uhrzeit_bis" type="time" value="<?= e(zeitKurz($lp['uhrzeit_bis'] ?? $formLeistung['uhrzeit_bis'] ?? '20:00')) ?>" required><?= feldFehler($lf, 'uhrzeit_bis') ?></div>
        <div class="g"><label for="du">Soll-Dauer in Minuten</label><input id="du" name="dauer_soll_minuten" type="number" min="5" max="1440" value="<?= e((string) ($lp['dauer_soll_minuten'] ?? $formLeistung['dauer_soll_minuten'] ?? 120)) ?>" required><?= feldFehler($lf, 'dauer_soll_minuten') ?></div>
      </div>
      <div class="drei">
        <div class="g"><label for="pm">Pauschale pro Monat</label><input id="pm" name="preis_monat" inputmode="decimal" value="<?= e($lp['preis_monat'] ?? ($formLeistung && $formLeistung['preis_monat'] !== null ? number_format((float) $formLeistung['preis_monat'], 2, ',', '') : '')) ?>" placeholder="z. B. 480,00"><?= feldFehler($lf, 'preis_monat') ?></div>
        <div class="g"><label for="pe">oder Preis pro Einsatz</label><input id="pe" name="preis_einheit" inputmode="decimal" value="<?= e($lp['preis_einheit'] ?? ($formLeistung && $formLeistung['preis_einheit'] !== null ? number_format((float) $formLeistung['preis_einheit'], 2, ',', '') : '')) ?>"><?= feldFehler($lf, 'preis_einheit') ?></div>
        <div class="g"><label for="ss">oder Stundensatz</label><input id="ss" name="stundensatz" inputmode="decimal" value="<?= e($lp['stundensatz'] ?? ($formLeistung && $formLeistung['stundensatz'] !== null ? number_format((float) $formLeistung['stundensatz'], 2, ',', '') : '')) ?>"><div class="tipp">Leer = Standardsatz der Leistungsart</div><?= feldFehler($lf, 'stundensatz') ?></div>
      </div>
      <div class="zwei">
        <div class="g"><label for="gv">Gültig ab</label><input id="gv" name="gueltig_von" type="date" value="<?= e($lp['gueltig_von'] ?? $formLeistung['gueltig_von'] ?? date('Y-m-d')) ?>"></div>
        <div class="g"><label for="gb">Gültig bis</label><input id="gb" name="gueltig_bis" type="date" value="<?= e($lp['gueltig_bis'] ?? $formLeistung['gueltig_bis'] ?? '') ?>"><div class="tipp">Leer = unbefristet</div></div>
      </div>
      <div class="g"><label>Stammteam</label>
        <?php if ($mitarbeiter === []): ?><p class="tipp">Noch keine Mitarbeiter angelegt.</p><?php else: ?>
        <div class="team-wahl"><?php foreach ($mitarbeiter as $m): ?><label class="tag-haken"><input type="checkbox" name="team[]" value="<?= (int) $m['id'] ?>" <?= in_array((int) $m['id'], $teamGewaehlt, true) ? 'checked' : '' ?>> <?= e($m['vorname'] . ' ' . $m['nachname']) ?></label><?php endforeach; ?></div>
        <div class="tipp">Diese Mitarbeiter werden bei jedem erzeugten Einsatz zugeteilt.</div><?php endif; ?></div>
      <div class="zeile-knoepfe">
        <button type="submit"><?= $bearbeiteLeistung ? 'Änderung speichern' : 'Leistung hinzufügen' ?></button>
        <?php if ($bearbeiteLeistung): ?><a class="knopf zweit" href="/admin/?s=objekte&amp;t=detail&amp;id=<?= $id ?>">Abbrechen</a><?php endif; ?>
      </div>
    </form>
  </section>

  <section>
    <h2 style="margin-top:0">Check-in-Code</h2>
    <div class="qr-box">
      <p>Das QR-Schild hängt am Objekt. Mitarbeiter scannen es beim Kommen und Gehen — das beweist die Anwesenheit ohne Ortung.</p>
      <div class="zeile-knoepfe">
        <a class="knopf" href="/admin/?s=objekte&amp;t=schild&amp;id=<?= $id ?>" target="_blank">Schild als PDF</a>
        <?php if ($istInhaber): ?><form method="post" class="inl" onsubmit="return confirm('Neuen Code erzeugen? Das bisherige Schild wird ungültig.')"><input type="hidden" name="aktion" value="qr_neu"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="zweit">Code erneuern</button></form><?php endif; ?>
      </div>
    </div>

    <h2>Nächste Einsätze</h2>
    <?php if ($naechste === []): ?><p class="leer">Keine geplant. <a href="/admin/?s=dienstplan">Zum Dienstplan</a></p>
    <?php else: ?><div class="liste"><?php foreach ($naechste as $n): ?>
      <div class="eintrag eintrag-2"><div><div class="titel"><?= e(datumDe($n['datum'])) ?> · <?= e(zeitKurz($n['uhrzeit_von'])) ?></div></div><span class="marke-aus"><?= e($n['status']) ?></span></div>
    <?php endforeach; ?></div><?php endif; ?>
  </section>
</div>

<?php
// =====================================================================
// FORMULAR (neu / bearbeiten)
// =====================================================================
else:
    $f = $pr->fehler;
    $vorKunde = (int) ($_GET['kunde'] ?? 0);
    $zugang = ($istInhaber && $id > 0) ? $db->rawOne('SELECT * FROM objekt_zugang WHERE objekt_id = ? AND betrieb_id = ?', [$id, $db->tenant()]) : null;
?>
<p class="zurueck"><a href="<?= $id > 0 ? '/admin/?s=objekte&t=detail&id=' . $id : '/admin/?s=objekte' ?>">← Zurück</a></p>
<h1><?= $id > 0 ? 'Objekt bearbeiten' : 'Objekt anlegen' ?></h1>
<?php if ($kunden === []): ?><div class="meldung fehler">Zuerst einen Kunden anlegen — jedes Objekt gehört zu einem Kunden. <a href="/admin/?s=kunden&amp;t=neu">Kunde anlegen</a></div><?php endif; ?>
<form method="post" class="formular">
  <input type="hidden" name="aktion" value="speichern"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <h2 class="erste">Objekt</h2>
  <div class="g"><label for="ku">Kunde</label><select id="ku" name="kunde_id" required>
    <option value="">— wählen —</option>
    <?php foreach ($kunden as $k): ?><option value="<?= (int) $k['id'] ?>" <?= (int) wert('kunde_id', $satz, (string) $vorKunde) === (int) $k['id'] ? 'selected' : '' ?>><?= e($k['firmenname']) ?></option><?php endforeach; ?>
  </select><?= feldFehler($f, 'kunde_id') ?></div>
  <div class="g"><label for="na">Bezeichnung</label><input id="na" name="name" value="<?= e(wert('name', $satz)) ?>" required maxlength="150" placeholder="z. B. Kanzlei Berger, 2. Stock"><?= feldFehler($f, 'name') ?></div>
  <div class="g"><label for="ad">Straße und Hausnummer</label><input id="ad" name="adresse" value="<?= e(wert('adresse', $satz)) ?>" required maxlength="150"><?= feldFehler($f, 'adresse') ?></div>
  <div class="zwei">
    <div class="g"><label for="plz">PLZ</label><input id="plz" name="plz" value="<?= e(wert('plz', $satz)) ?>" required maxlength="10" inputmode="numeric"><?= feldFehler($f, 'plz') ?></div>
    <div class="g"><label for="ort">Ort</label><input id="ort" name="ort" value="<?= e(wert('ort', $satz, 'Wien')) ?>" required maxlength="80"><?= feldFehler($f, 'ort') ?></div>
  </div>
  <h2>Vor Ort</h2>
  <div class="drei">
    <div class="g"><label for="ap">Ansprechperson</label><input id="ap" name="ansprechpartner" value="<?= e(wert('ansprechpartner', $satz)) ?>" maxlength="120"></div>
    <div class="g"><label for="tel">Telefon</label><input id="tel" name="telefon" type="tel" value="<?= e(wert('telefon', $satz)) ?>" maxlength="40"></div>
    <div class="g"><label for="em">E-Mail</label><input id="em" name="email" type="email" value="<?= e(wert('email', $satz)) ?>" maxlength="150"><?= feldFehler($f, 'email') ?></div>
  </div>
  <div class="g"><label for="zi">Zugang (für das Team sichtbar)</label><textarea id="zi" name="zugang_info" maxlength="2000" placeholder="Stiege 2, 2. Stock links, Klingel „Berger“, Schlüssel beim Portier"><?= e(wert('zugang_info', $satz)) ?></textarea></div>
  <div class="g"><label for="rh">Reinigungshinweise (für das Team sichtbar)</label><textarea id="rh" name="reinigung_hinweise" maxlength="4000" placeholder="Parkett nur nebelfeucht, Serverraum nicht betreten, Küche zuletzt"><?= e(wert('reinigung_hinweise', $satz)) ?></textarea></div>
  <?php if ($istInhaber): ?>
  <h2>Vertrauliche Zugangsdaten</h2>
  <p class="tipp" style="margin-bottom:12px">Verschlüsselt gespeichert, nur für den Inhaber sichtbar, nicht in der Mitarbeiter-App.</p>
  <?php if ($id > 0): ?><label class="tag-haken" style="margin-bottom:12px"><input type="checkbox" name="zugang_aendern" value="1"> Zugangsdaten ändern</label><?php else: ?><input type="hidden" name="zugang_aendern" value="1"><?php endif; ?>
  <div class="zwei">
    <div class="g"><label for="sk">Schlüsselinfo</label><input id="sk" name="schluessel_info" maxlength="500" value="<?= e($_POST['schluessel_info'] ?? ($zugang && $zugang['schluessel_info_enc'] ? Crypto::decrypt($zugang['schluessel_info_enc']) : '')) ?>" placeholder="Schlüssel Nr. 14, Tresor Büro"></div>
    <div class="g"><label for="al">Alarmcode</label><input id="al" name="alarm_code" maxlength="60" value="<?= e($_POST['alarm_code'] ?? ($zugang && $zugang['alarm_code_enc'] ? Crypto::decrypt($zugang['alarm_code_enc']) : '')) ?>" autocomplete="off"></div>
  </div>
  <?php endif; ?>
  <h2>Status</h2>
  <label class="tag-haken"><input type="checkbox" name="aktiv" value="1" <?= (int) wert('aktiv', $satz, '1') === 1 ? 'checked' : '' ?>> Objekt ist aktiv</label>
  <div class="zeile-knoepfe" style="margin-top:20px">
    <button type="submit">Speichern</button>
    <a class="knopf zweit" href="<?= $id > 0 ? '/admin/?s=objekte&t=detail&id=' . $id : '/admin/?s=objekte' ?>">Abbrechen</a>
  </div>
</form>
<?php endif; ?>
