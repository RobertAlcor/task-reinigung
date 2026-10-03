<?php
/**
 * Mitarbeiter: Stammdaten, sensible Daten (verschluesselt, nur Inhaber),
 * App-Zugang mit PIN, Verfuegbarkeit, Abwesenheiten mit Genehmigung.
 * Variablen: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

use App\Auth;
use App\Crypto;

$t   = $_GET['t'] ?? 'liste';        // liste | neu | detail | bearbeiten | abwesenheiten
$id  = (int) ($_GET['id'] ?? 0);
$pr  = new Pruefung();
$satz = null;
$istInhaber = $user['rolle'] === 'inhaber';
$ABW_TYP = ['urlaub' => 'Urlaub', 'krank' => 'Krankenstand', 'zeitausgleich' => 'Zeitausgleich', 'einschulung' => 'Einschulung', 'sonstiges' => 'Sonstiges'];

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    $aktion = $_POST['aktion'] ?? '';

    if ($aktion === 'speichern') {
        $pnr = $pr->text('personalnummer', 20, true, 'Personalnummer');
        if ($pnr !== null && preg_match('/^[A-Za-z0-9-]{1,20}$/', $pnr) !== 1) {
            $pr->fehler['personalnummer'] = 'Nur Buchstaben, Ziffern und Bindestrich.';
        }
        // Personalnummer je Betrieb eindeutig, und global als App-Login eindeutig
        if ($pnr !== null) {
            $dbl = $db->rawOne('SELECT id FROM mitarbeiter WHERE betrieb_id = ? AND personalnummer = ? AND id <> ? LIMIT 1', [$db->tenant(), $pnr, $id]);
            if ($dbl !== null) {
                $pr->fehler['personalnummer'] = 'Diese Personalnummer ist bereits vergeben.';
            }
        }
        $daten = [
            'personalnummer'         => $pnr,
            'vorname'                => $pr->text('vorname', 80, true, 'Vorname'),
            'nachname'               => $pr->text('nachname', 80, true, 'Nachname'),
            'telefon'                => $pr->text('telefon', 40),
            'email'                  => $pr->email('email'),
            'adresse'                => $pr->text('adresse', 150),
            'plz'                    => $pr->text('plz', 10),
            'ort'                    => $pr->text('ort', 80),
            'geburtsdatum'           => $pr->datum('geburtsdatum'),
            'eintrittsdatum'         => $pr->datum('eintrittsdatum'),
            'austrittsdatum'         => $pr->datum('austrittsdatum'),
            'anstellung'             => $pr->auswahl('anstellung', array_keys(ANSTELLUNG), true) ?? 'teilzeit',
            'stunden_woche'          => $pr->decimal('stunden_woche', true, 0) ?? 0,
            'arbeitstage_woche'      => $pr->int('arbeitstage_woche', false, 1, 7) ?? 5,
            'urlaubstage_jahr'       => $pr->decimal('urlaubstage_jahr', false, 0) ?? 25,
            'fuehrerschein'          => $pr->haken('fuehrerschein'),
            'hat_auto'               => $pr->haken('hat_auto'),
            'notfallkontakt_name'    => $pr->text('notfallkontakt_name', 120),
            'notfallkontakt_telefon' => $pr->text('notfallkontakt_telefon', 40),
            'notizen'                => $pr->text('notizen', 4000),
            'aktiv'                  => $pr->haken('aktiv'),
        ];
        if ($istInhaber) {
            $daten['lohngruppe']        = $pr->text('lohngruppe', 10);
            $daten['stundenlohn']       = $pr->decimal('stundenlohn', false, 0);
            $daten['verrechnungssatz']  = $pr->decimal('verrechnungssatz', false, 0);
            $daten['dienstjahre_vorher'] = $pr->decimal('dienstjahre_vorher', false, 0) ?? 0;
        }
        $pin = trim((string) ($_POST['pin'] ?? ''));
        if ($id === 0 && preg_match('/^\d{4}$/', $pin) !== 1) {
            $pr->fehler['pin'] = 'Vier Ziffern für den App-Zugang.';
        }
        if ($id > 0 && $pin !== '' && preg_match('/^\d{4}$/', $pin) !== 1) {
            $pr->fehler['pin'] = 'Vier Ziffern.';
        }
        $svnr = $istInhaber ? $pr->text('svnr', 20) : null;
        $iban = $istInhaber ? $pr->text('iban', 34) : null;
        $kinder = $istInhaber ? ($pr->int('anzahl_kinder', false, 0, 20) ?? 0) : null;
        if ($svnr !== null) {
            $svnr = str_replace(' ', '', $svnr);
            if (preg_match('/^\d{10}$/', $svnr) !== 1) {
                $pr->fehler['svnr'] = 'Zehn Ziffern (vierstellige Nummer und Geburtsdatum).';
            }
        }
        if ($iban !== null) {
            $iban = strtoupper(str_replace(' ', '', $iban));
            if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
                $pr->fehler['iban'] = 'Keine gültige IBAN.';
            }
        }
        $sensibelAendern = $istInhaber && ($id === 0 || !empty($_POST['sensibel_aendern']));

        if ($pr->ok()) {
            $ziel = 0;
            $db->transaction(static function () use ($db, $id, $daten, $pin, $sensibelAendern, $svnr, $iban, $kinder, &$ziel): void {
                if ($id > 0) {
                    $db->update('mitarbeiter', $id, $daten);
                    $ziel = $id;
                    // Personalnummer als App-Login nachziehen
                    $db->raw('UPDATE benutzer SET benutzername = ?, aktiv = ? WHERE mitarbeiter_id = ? AND betrieb_id = ?',
                        [$daten['personalnummer'], $daten['aktiv'], $id, $db->tenant()]);
                    if ($pin !== '') {
                        $db->raw('UPDATE benutzer SET pin_hash = ?, fehlversuche = 0, gesperrt_bis = NULL WHERE mitarbeiter_id = ? AND betrieb_id = ?',
                            [password_hash($pin, PASSWORD_DEFAULT), $id, $db->tenant()]);
                    }
                } else {
                    $ziel = $db->insert('mitarbeiter', $daten);
                    $db->insert('benutzer', [
                        'rolle' => 'mitarbeiter', 'benutzername' => $daten['personalnummer'],
                        'pin_hash' => password_hash($pin, PASSWORD_DEFAULT), 'mitarbeiter_id' => $ziel, 'aktiv' => $daten['aktiv'],
                    ]);
                }
                // Qualifikationen
                $db->raw('DELETE FROM mitarbeiter_qualifikationen WHERE mitarbeiter_id = ? AND betrieb_id = ?', [$ziel, $db->tenant()]);
                foreach (array_map('intval', (array) ($_POST['quali'] ?? [])) as $qid) {
                    if ($db->find('qualifikationen', $qid) !== null) { $db->insert('mitarbeiter_qualifikationen', ['mitarbeiter_id' => $ziel, 'qualifikation_id' => $qid, 'seit' => date('Y-m-d')]); }
                }
                if ($sensibelAendern) {
                    $db->raw(
                        'INSERT INTO mitarbeiter_sensibel (mitarbeiter_id, betrieb_id, svnr_enc, iban_enc, anzahl_kinder) VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE svnr_enc = VALUES(svnr_enc), iban_enc = VALUES(iban_enc), anzahl_kinder = VALUES(anzahl_kinder)',
                        [$ziel, $db->tenant(), Crypto::encrypt($svnr), Crypto::encrypt($iban), $kinder ?? 0]
                    );
                }
            });
            flash('ok', $id > 0 ? 'Mitarbeiter gespeichert.' : 'Mitarbeiter angelegt. App-Zugang: Personalnummer ' . $daten['personalnummer'] . ' und die vergebene PIN.');
            weiter('/admin/?s=mitarbeiter&t=detail&id=' . $ziel);
        }
        $t = $id > 0 ? 'bearbeiten' : 'neu';
    }

    if ($aktion === 'verfuegbarkeit' && $id > 0 && $db->find('mitarbeiter', $id) !== null) {
        // Alle Zeilen ersetzen: tag[], von[], bis[]
        $tage = (array) ($_POST['v_tag'] ?? []); $von = (array) ($_POST['v_von'] ?? []); $bis = (array) ($_POST['v_bis'] ?? []);
        $zeilen = [];
        foreach ($tage as $i => $tg) {
            $tg = (int) $tg; $v = trim((string) ($von[$i] ?? '')); $b = trim((string) ($bis[$i] ?? ''));
            if ($tg < 1 || $tg > 7 || $v === '' || $b === '') { continue; }
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $b) !== 1 || $b <= $v) {
                flash('fehler', 'Verfügbarkeit: Zeiten prüfen (Ende nach Beginn).');
                weiter('/admin/?s=mitarbeiter&t=detail&id=' . $id);
            }
            $zeilen[] = [$tg, $v . ':00', $b . ':00'];
        }
        $db->transaction(static function () use ($db, $id, $zeilen): void {
            $db->raw('DELETE FROM mitarbeiter_verfuegbarkeit WHERE mitarbeiter_id = ? AND betrieb_id = ?', [$id, $db->tenant()]);
            foreach ($zeilen as [$tg, $v, $b]) {
                $db->insert('mitarbeiter_verfuegbarkeit', ['mitarbeiter_id' => $id, 'wochentag' => $tg, 'uhrzeit_von' => $v, 'uhrzeit_bis' => $b]);
            }
        });
        flash('ok', 'Verfügbarkeit gespeichert.');
        weiter('/admin/?s=mitarbeiter&t=detail&id=' . $id);
    }

    if ($aktion === 'abwesenheit_neu') {
        $mid = $pr->int('mitarbeiter_id', true, 1) ?? $id;
        $typ = $pr->auswahl('typ', array_keys($ABW_TYP), true);
        $von = $pr->datum('datum_von', true); $bis = $pr->datum('datum_bis', true);
        if ($von && $bis && $bis < $von) { $pr->fehler['datum_bis'] = 'Ende vor Beginn.'; }
        if ($pr->ok() && $db->find('mitarbeiter', $mid) !== null) {
            $tage = (int) $pr->decimal('tage', false, 0) ?: (int) ((strtotime($bis) - strtotime($von)) / 86400) + 1;
            $db->insert('abwesenheiten', [
                'mitarbeiter_id' => $mid, 'typ' => $typ, 'datum_von' => $von, 'datum_bis' => $bis, 'tage' => $tage,
                'status' => 'genehmigt', 'genehmigt_von' => (int) $user['id'], 'genehmigt_am' => date('Y-m-d H:i:s'),
                'kommentar_office' => $pr->text('kommentar_office', 255),
            ]);
            flash('ok', 'Abwesenheit eingetragen. Betroffene Einsätze erscheinen auf der Startseite unter Ersatzbedarf.');
        } else {
            flash('fehler', 'Abwesenheit: ' . implode(' ', $pr->fehler));
        }
        weiter('/admin/?s=mitarbeiter&t=detail&id=' . $mid);
    }

    if ($aktion === 'abwesenheit_status') {
        $aid = (int) ($_POST['abwesenheit_id'] ?? 0);
        $st  = $pr->auswahl('status', ['genehmigt', 'abgelehnt'], true);
        $abw = $db->find('abwesenheiten', $aid);
        if ($abw !== null && $st !== null) {
            $db->update('abwesenheiten', $aid, ['status' => $st, 'genehmigt_von' => (int) $user['id'], 'genehmigt_am' => date('Y-m-d H:i:s'), 'kommentar_office' => $pr->text('kommentar_office', 255)]);
            flash('ok', $st === 'genehmigt' ? 'Genehmigt.' : 'Abgelehnt.');
        }
        weiter((string) ($_POST['zurueck'] ?? '/admin/?s=mitarbeiter&t=abwesenheiten'));
    }

    if ($aktion === 'abwesenheit_loeschen') {
        $aid = (int) ($_POST['abwesenheit_id'] ?? 0);
        $abw = $db->find('abwesenheiten', $aid);
        if ($abw !== null) {
            $db->delete('abwesenheiten', $aid);
            flash('ok', 'Abwesenheit entfernt.');
            weiter('/admin/?s=mitarbeiter&t=detail&id=' . (int) $abw['mitarbeiter_id']);
        }
        weiter('/admin/?s=mitarbeiter');
    }

    if ($aktion === 'app_sperren' && $id > 0) {
        $db->raw('UPDATE benutzer SET aktiv = 0 WHERE mitarbeiter_id = ? AND betrieb_id = ?', [$id, $db->tenant()]);
        $db->raw('UPDATE api_tokens SET widerrufen = 1 WHERE benutzer_id IN (SELECT id FROM benutzer WHERE mitarbeiter_id = ? AND betrieb_id = ?)', [$id, $db->tenant()]);
        flash('ok', 'App-Zugang gesperrt, alle Geräte abgemeldet.');
        weiter('/admin/?s=mitarbeiter&t=detail&id=' . $id);
    }
    if ($aktion === 'app_freigeben' && $id > 0) {
        $db->raw('UPDATE benutzer SET aktiv = 1, fehlversuche = 0, gesperrt_bis = NULL WHERE mitarbeiter_id = ? AND betrieb_id = ?', [$id, $db->tenant()]);
        flash('ok', 'App-Zugang freigegeben.');
        weiter('/admin/?s=mitarbeiter&t=detail&id=' . $id);
    }
}

// ---------------------------------------------------------------------
// Daten laden
// ---------------------------------------------------------------------
if (in_array($t, ['detail', 'bearbeiten', 'datenschutz'], true)) {
    $satz = $db->find('mitarbeiter', $id);
    if ($satz === null) {
        flash('fehler', 'Mitarbeiter nicht gefunden.');
        weiter('/admin/?s=mitarbeiter');
    }
}

// =====================================================================
// LISTE
// =====================================================================
// =====================================================================
// DATENSCHUTZINFORMATION (PDF, Art. 13 DSGVO)
// =====================================================================
if ($t === 'datenschutz'):
    require_once __DIR__ . '/../../system/lib/dompdf/autoload.inc.php';
    $html = datenschutzHtml($betrieb, $satz, App\Invoice::settings());
    $opt = new Dompdf\Options();
    $opt->set('isRemoteEnabled', false); $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('fontDir', __DIR__ . '/../../system/lib/dompdf/vendor/dompdf/dompdf/lib/fonts');
    $opt->set('fontCache', __DIR__ . '/../../system/rechnungen'); $opt->set('chroot', __DIR__ . '/../../system');
    if (!is_dir(__DIR__ . '/../../system/rechnungen')) { @mkdir(__DIR__ . '/../../system/rechnungen', 0750, true); }
    $pdf = new Dompdf\Dompdf($opt); $pdf->setPaper('A4'); $pdf->loadHtml($html, 'UTF-8'); $pdf->render();
    ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Datenschutzinformation-' . preg_replace('/[^A-Za-z0-9]+/', '-', $satz['nachname']) . '.pdf"');
    echo $pdf->output();
    exit;
endif;

if ($t === 'liste'):
    $suche = trim((string) ($_GET['q'] ?? ''));
    $alle = ($_GET['f'] ?? '') === 'alle';
    $where = $alle ? '1=1' : 'aktiv = 1'; $params = [];
    if ($suche !== '') { $where .= ' AND (vorname LIKE ? OR nachname LIKE ? OR personalnummer LIKE ?)'; $params = ["%{$suche}%", "%{$suche}%", "%{$suche}%"]; }
    $liste = $db->select('mitarbeiter', $where, $params, 'ORDER BY aktiv DESC, nachname, vorname');
    $abwesend = [];
    foreach ($db->rawAll("SELECT mitarbeiter_id, typ, datum_bis FROM abwesenheiten WHERE betrieb_id = ? AND status = 'genehmigt' AND CURDATE() BETWEEN datum_von AND datum_bis", [$db->tenant()]) as $a) {
        $abwesend[(int) $a['mitarbeiter_id']] = $a;
    }
    $offene = $db->count('abwesenheiten', "status = 'beantragt'");
    $appStatus = [];
    foreach ($db->rawAll("SELECT mitarbeiter_id, aktiv, letzter_login FROM benutzer WHERE betrieb_id = ? AND rolle = 'mitarbeiter'", [$db->tenant()]) as $b) {
        $appStatus[(int) $b['mitarbeiter_id']] = $b;
    }
?>
<div class="kopfzeile">
  <h1>Mitarbeiter</h1>
  <div class="zeile-knoepfe">
    <a class="knopf zweit" href="/admin/?s=subunternehmer">Subunternehmer</a>
    <a class="knopf zweit" href="/admin/?s=mitarbeiter&amp;t=abwesenheiten">Abwesenheiten<?= $offene > 0 ? ' · ' . $offene . ' offen' : '' ?></a>
    <a class="knopf" href="/admin/?s=mitarbeiter&amp;t=neu">Mitarbeiter anlegen</a>
  </div>
</div>
<form method="get" class="filter">
  <input type="hidden" name="s" value="mitarbeiter">
  <input type="search" name="q" value="<?= e($suche) ?>" placeholder="Name oder Personalnummer" aria-label="Suche">
  <select name="f" aria-label="Filter"><option value="">Aktive</option><option value="alle" <?= $alle ? 'selected' : '' ?>>Alle</option></select>
  <button type="submit" class="zweit">Suchen</button>
</form>
<?php if ($liste === []): ?><p class="leer">Keine Mitarbeiter gefunden.</p><?php else: ?>
<table>
  <thead><tr><th>Name</th><th>Nr.</th><th>Anstellung</th><th class="r">Std/Woche</th><th>Heute</th><th>App</th></tr></thead>
  <tbody>
  <?php foreach ($liste as $m): $ab = $abwesend[(int) $m['id']] ?? null; $ap = $appStatus[(int) $m['id']] ?? null; ?>
    <tr class="<?= (int) $m['aktiv'] === 0 ? 'aus' : '' ?>">
      <td><a href="/admin/?s=mitarbeiter&amp;t=detail&amp;id=<?= (int) $m['id'] ?>"><?= e($m['nachname']) ?>, <?= e($m['vorname']) ?></a><?= $m['telefon'] ? '<div class="klein">' . e($m['telefon']) . '</div>' : '' ?></td>
      <td><?= e($m['personalnummer']) ?></td>
      <td><?= e(ANSTELLUNG[$m['anstellung']] ?? $m['anstellung']) ?></td>
      <td class="r"><?= number_format((float) $m['stunden_woche'], 1, ',', '') ?></td>
      <td><?= $ab ? '<span class="marke-test">' . e($ab['typ'] === 'krank' ? 'abwesend' : ($ABW_TYP[$ab['typ']] ?? 'abwesend')) . ' bis ' . e(date('d.m.', strtotime($ab['datum_bis']))) . '</span>' : '<span class="marke-ok">verfügbar</span>' ?></td>
      <td class="klein"><?= $ap === null ? '—' : ((int) $ap['aktiv'] === 0 ? '<span class="marke-rot">gesperrt</span>' : ($ap['letzter_login'] ? 'zuletzt ' . e(date('d.m.', strtotime($ap['letzter_login']))) : 'noch nie angemeldet')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php
// =====================================================================
// ABWESENHEITEN (alle)
// =====================================================================
elseif ($t === 'abwesenheiten'):
    $rows = $db->rawAll(
        "SELECT a.*, m.vorname, m.nachname FROM abwesenheiten a JOIN mitarbeiter m ON m.id = a.mitarbeiter_id
          WHERE a.betrieb_id = ? AND (a.status = 'beantragt' OR a.datum_bis >= DATE_SUB(CURDATE(), INTERVAL 60 DAY))
          ORDER BY a.status = 'beantragt' DESC, a.datum_von DESC LIMIT 200", [$db->tenant()]);
    $mitarbeiter = $db->select('mitarbeiter', 'aktiv = 1', [], 'ORDER BY nachname, vorname');
?>
<div class="kopfzeile"><h1>Abwesenheiten</h1><a class="knopf zweit" href="/admin/?s=mitarbeiter">Zu den Mitarbeitern</a></div>

<h2 style="margin-top:0">Eintragen</h2>
<form method="post" class="formular" style="max-width:900px">
  <input type="hidden" name="aktion" value="abwesenheit_neu"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <div class="drei">
    <div class="g"><label for="am">Mitarbeiter</label><select id="am" name="mitarbeiter_id" required><option value="">— wählen —</option><?php foreach ($mitarbeiter as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['nachname'] . ', ' . $m['vorname']) ?></option><?php endforeach; ?></select></div>
    <div class="g"><label for="at">Art</label><select id="at" name="typ"><?php foreach ($ABW_TYP as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="g"><label for="ak">Kommentar</label><input id="ak" name="kommentar_office" maxlength="255" placeholder="optional, kein Grund bei Krankenstand"></div>
  </div>
  <div class="drei">
    <div class="g"><label for="av">Von</label><input id="av" name="datum_von" type="date" required value="<?= date('Y-m-d') ?>"></div>
    <div class="g"><label for="ab">Bis</label><input id="ab" name="datum_bis" type="date" required value="<?= date('Y-m-d') ?>"></div>
    <div class="g"><label for="ag">Tage</label><input id="ag" name="tage" inputmode="decimal" placeholder="automatisch"><div class="tipp">Leer = Kalendertage; bei halben Tagen z. B. 0,5</div></div>
  </div>
  <button type="submit">Eintragen</button>
</form>

<h2>Übersicht</h2>
<?php if ($rows === []): ?><p class="leer">Keine Einträge.</p><?php else: ?>
<table>
  <thead><tr><th>Mitarbeiter</th><th>Art</th><th>Von</th><th>Bis</th><th class="r">Tage</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $a): ?>
    <tr>
      <td><a href="/admin/?s=mitarbeiter&amp;t=detail&amp;id=<?= (int) $a['mitarbeiter_id'] ?>"><?= e($a['nachname'] . ', ' . $a['vorname']) ?></a></td>
      <td><?= e($ABW_TYP[$a['typ']] ?? $a['typ']) ?></td>
      <td><?= datumDe($a['datum_von']) ?></td><td><?= datumDe($a['datum_bis']) ?></td>
      <td class="r"><?= number_format((float) $a['tage'], 1, ',', '') ?></td>
      <td><?= abwMarke($a['status']) ?><?= $a['kommentar_office'] ? '<div class="klein">' . e($a['kommentar_office']) . '</div>' : '' ?></td>
      <td class="r nowrap"><?php if ($a['status'] === 'beantragt'): ?>
        <form method="post" class="inl"><input type="hidden" name="aktion" value="abwesenheit_status"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="abwesenheit_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="zurueck" value="/admin/?s=mitarbeiter&t=abwesenheiten"><button name="status" value="genehmigt" class="mini">Genehmigen</button> <button name="status" value="abgelehnt" class="mini zweit">Ablehnen</button></form>
      <?php endif; ?></td>
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
    $verf = $db->select('mitarbeiter_verfuegbarkeit', 'mitarbeiter_id = ?', [$id], 'ORDER BY wochentag, uhrzeit_von');
    $abw  = $db->select('abwesenheiten', 'mitarbeiter_id = ?', [$id], 'ORDER BY datum_von DESC LIMIT 30');
    $app  = $db->rawOne("SELECT * FROM benutzer WHERE mitarbeiter_id = ? AND betrieb_id = ? AND rolle = 'mitarbeiter'", [$id, $db->tenant()]);
    $sens = $istInhaber ? $db->rawOne('SELECT * FROM mitarbeiter_sensibel WHERE mitarbeiter_id = ? AND betrieb_id = ?', [$id, $db->tenant()]) : null;
    $objekte = $db->rawAll(
        'SELECT DISTINCT o.id, o.name, l.name AS leistung, ol.uhrzeit_von, ol.wochentage
           FROM objekt_leistung_mitarbeiter olm JOIN objekt_leistungen ol ON ol.id = olm.objekt_leistung_id AND ol.aktiv = 1
           JOIN objekte o ON o.id = ol.objekt_id JOIN leistungsarten l ON l.id = ol.leistungsart_id
          WHERE olm.mitarbeiter_id = ? AND olm.betrieb_id = ? ORDER BY o.name', [$id, $db->tenant()]);
    $naechste = $db->rawAll(
        'SELECT e.datum, e.uhrzeit_von, e.status, o.name FROM einsatz_mitarbeiter em JOIN einsaetze e ON e.id = em.einsatz_id JOIN objekte o ON o.id = e.objekt_id
          WHERE em.mitarbeiter_id = ? AND em.betrieb_id = ? AND e.datum >= CURDATE() AND e.status <> \'storniert\' ORDER BY e.datum, e.uhrzeit_von LIMIT 6', [$id, $db->tenant()]);
    $urlaubGenommen = (float) ($db->rawOne("SELECT IFNULL(SUM(tage),0) s FROM abwesenheiten WHERE mitarbeiter_id = ? AND betrieb_id = ? AND typ = 'urlaub' AND status = 'genehmigt' AND YEAR(datum_von) = YEAR(CURDATE())", [$id, $db->tenant()])['s'] ?? 0);
    $verfByTag = [];
    foreach ($verf as $v) { $verfByTag[(int) $v['wochentag']][] = zeitKurz($v['uhrzeit_von']) . '–' . zeitKurz($v['uhrzeit_bis']); }
?>
<p class="zurueck"><a href="/admin/?s=mitarbeiter">← Mitarbeiter</a></p>
<div class="kopfzeile">
  <h1><?= e($satz['vorname'] . ' ' . $satz['nachname']) ?><?= (int) $satz['aktiv'] === 0 ? ' <span class="marke-aus">inaktiv</span>' : '' ?></h1>
  <a class="knopf zweit" href="/admin/?s=mitarbeiter&amp;t=datenschutz&amp;id=<?= $id ?>" target="_blank">Datenschutzinformation</a>
  <a class="knopf zweit" href="/admin/?s=mitarbeiter&amp;t=bearbeiten&amp;id=<?= $id ?>">Bearbeiten</a>
</div>

<div class="zweispalt">
  <section>
    <dl class="fakten">
      <dt>Personalnummer</dt><dd><?= e($satz['personalnummer']) ?></dd>
      <dt>Anstellung</dt><dd><?= e(ANSTELLUNG[$satz['anstellung']] ?? '') ?>, <?= number_format((float) $satz['stunden_woche'], 1, ',', '') ?> Std/Woche, <?= (int) $satz['arbeitstage_woche'] ?> Tage</dd>
      <dt>Telefon</dt><dd><?= $satz['telefon'] ? '<a href="tel:' . e(preg_replace('/\s+/', '', $satz['telefon'])) . '">' . e($satz['telefon']) . '</a>' : '—' ?></dd>
      <dt>E-Mail</dt><dd><?= e($satz['email'] ?? '—') ?></dd>
      <dt>Adresse</dt><dd><?= e(trim(($satz['adresse'] ?? '') . ', ' . ($satz['plz'] ?? '') . ' ' . ($satz['ort'] ?? ''), ', ')) ?: '—' ?></dd>
      <dt>Eintritt</dt><dd><?= datumDe($satz['eintrittsdatum']) ?><?= $satz['austrittsdatum'] ? ' · Austritt ' . datumDe($satz['austrittsdatum']) : '' ?></dd>
      <dt>Urlaub <?= date('Y') ?></dt><dd><?= number_format($urlaubGenommen, 1, ',', '') ?> von <?= number_format((float) $satz['urlaubstage_jahr'], 1, ',', '') ?> Tagen</dd>
      <dt>Qualifikationen</dt><dd><?php $mq = $db->rawAll('SELECT q.name FROM mitarbeiter_qualifikationen mq JOIN qualifikationen q ON q.id = mq.qualifikation_id WHERE mq.mitarbeiter_id = ? AND mq.betrieb_id = ? ORDER BY q.sortierung', [$id, $db->tenant()]); echo $mq ? e(implode(', ', array_column($mq, 'name'))) : '<span class="warn-text">keine — wird im Dienstplan nicht vorgeschlagen</span>'; ?></dd>
      <dt>Mobilität</dt><dd><?= (int) $satz['fuehrerschein'] ? 'Führerschein' : 'kein Führerschein' ?><?= (int) $satz['hat_auto'] ? ', eigenes Auto' : '' ?></dd>
      <?php if ($satz['notfallkontakt_name']): ?><dt>Notfallkontakt</dt><dd><?= e($satz['notfallkontakt_name']) ?><?= $satz['notfallkontakt_telefon'] ? ' · ' . e($satz['notfallkontakt_telefon']) : '' ?></dd><?php endif; ?>
      <?php if ($istInhaber): ?>
        <dt>Lohn</dt><dd><?= $satz['lohngruppe'] ? 'Lohngruppe ' . e($satz['lohngruppe']) : '—' ?><?= $satz['stundenlohn'] ? ' · ' . geld($satz['stundenlohn']) . '/Std' : '' ?><?= $satz['verrechnungssatz'] ? ' · Verrechnung ' . geld($satz['verrechnungssatz']) . '/Std' : '' ?></dd>
        <dt>SVNR</dt><dd><?= $sens && $sens['svnr_enc'] ? e(Crypto::decrypt($sens['svnr_enc'])) : '—' ?> <span class="klein">nur Inhaber</span></dd>
        <dt>IBAN</dt><dd><?= $sens && $sens['iban_enc'] ? e(chunk_split(Crypto::decrypt($sens['iban_enc']), 4, ' ')) : '—' ?></dd>
        <dt>Kinder</dt><dd><?= $sens ? (int) $sens['anzahl_kinder'] : '—' ?></dd>
      <?php endif; ?>
      <?php if ($satz['notizen']): ?><dt>Notizen</dt><dd><?= nl2br(e($satz['notizen'])) ?></dd><?php endif; ?>
    </dl>

    <h2>Verfügbarkeit</h2>
    <p class="klein" style="margin-bottom:10px">Regelmäßige Zeiten. Daraus schlägt der Dienstplan Ersatz vor. Leere Zeilen werden ignoriert.</p>
    <form method="post" class="formular verf">
      <input type="hidden" name="aktion" value="verfuegbarkeit"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <?php $vz = $verf; $ziel = max(3, count($verf) + 1); while (count($vz) < $ziel) { $vz[] = null; } ?>
      <?php foreach ($vz as $i => $v): ?>
      <div class="verf-zeile">
        <select name="v_tag[]" aria-label="Wochentag"><option value="">—</option><?php foreach (WOCHENTAGE as $n => $k): ?><option value="<?= $n ?>" <?= $v && (int) $v['wochentag'] === $n ? 'selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select>
        <input name="v_von[]" type="time" value="<?= $v ? e(zeitKurz($v['uhrzeit_von'])) : '' ?>" aria-label="Von">
        <input name="v_bis[]" type="time" value="<?= $v ? e(zeitKurz($v['uhrzeit_bis'])) : '' ?>" aria-label="Bis">
      </div>
      <?php endforeach; ?>
      <button type="submit" class="mini">Verfügbarkeit speichern</button>
    </form>

    <h2>Abwesenheiten</h2>
    <form method="post" class="formular" style="margin-bottom:14px">
      <input type="hidden" name="aktion" value="abwesenheit_neu"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="mitarbeiter_id" value="<?= $id ?>">
      <div class="drei">
        <div class="g"><label>Art</label><select name="typ"><?php foreach ($ABW_TYP as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
        <div class="g"><label>Von</label><input name="datum_von" type="date" required value="<?= date('Y-m-d') ?>"></div>
        <div class="g"><label>Bis</label><input name="datum_bis" type="date" required value="<?= date('Y-m-d') ?>"></div>
      </div>
      <button type="submit" class="mini">Eintragen</button>
    </form>
    <?php if ($abw === []): ?><p class="leer">Keine Einträge.</p><?php else: ?>
    <table>
      <thead><tr><th>Art</th><th>Von</th><th>Bis</th><th class="r">Tage</th><th>Status</th><th></th></tr></thead>
      <tbody><?php foreach ($abw as $a): ?>
        <tr><td><?= e($ABW_TYP[$a['typ']] ?? $a['typ']) ?></td><td><?= datumDe($a['datum_von']) ?></td><td><?= datumDe($a['datum_bis']) ?></td><td class="r"><?= number_format((float) $a['tage'], 1, ',', '') ?></td><td><?= abwMarke($a['status']) ?></td>
        <td class="r nowrap"><?php if ($a['status'] === 'beantragt'): ?>
          <form method="post" class="inl"><input type="hidden" name="aktion" value="abwesenheit_status"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="abwesenheit_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="zurueck" value="/admin/?s=mitarbeiter&t=detail&id=<?= $id ?>"><button name="status" value="genehmigt" class="mini">Genehmigen</button> <button name="status" value="abgelehnt" class="mini zweit">Ablehnen</button></form>
        <?php elseif ($a['datum_von'] >= date('Y-m-d')): ?>
          <form method="post" class="inl" onsubmit="return confirm('Abwesenheit entfernen?')"><input type="hidden" name="aktion" value="abwesenheit_loeschen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="abwesenheit_id" value="<?= (int) $a['id'] ?>"><button class="mini zweit">Entfernen</button></form>
        <?php endif; ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
    <?php endif; ?>
  </section>

  <section>
    <h2 style="margin-top:0">App-Zugang</h2>
    <div class="qr-box">
      <?php if ($app === null): ?><p>Kein App-Zugang vorhanden.</p>
      <?php else: ?>
      <dl class="fakten" style="margin:0 0 14px;padding:0;border:0;background:transparent">
        <dt>Login</dt><dd><code><?= e($app['benutzername']) ?></code> + PIN</dd>
        <dt>Status</dt><dd><?= (int) $app['aktiv'] === 0 ? '<span class="marke-rot">gesperrt</span>' : ($app['gesperrt_bis'] && strtotime($app['gesperrt_bis']) > time() ? '<span class="marke-test">vorübergehend gesperrt (Fehlversuche)</span>' : '<span class="marke-ok">aktiv</span>') ?></dd>
        <dt>Letzter Login</dt><dd><?= $app['letzter_login'] ? e(date('d.m.Y H:i', strtotime($app['letzter_login']))) : 'noch nie' ?></dd>
      </dl>
      <div class="zeile-knoepfe">
        <a class="knopf mini zweit" href="/admin/?s=mitarbeiter&amp;t=bearbeiten&amp;id=<?= $id ?>#app-zugang">PIN neu setzen</a>
        <?php if ((int) $app['aktiv'] === 1): ?>
          <form method="post" class="inl" onsubmit="return confirm('App-Zugang sperren? Alle Geräte werden abgemeldet.')"><input type="hidden" name="aktion" value="app_sperren"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="mini zweit">Sperren</button></form>
        <?php else: ?>
          <form method="post" class="inl"><input type="hidden" name="aktion" value="app_freigeben"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="mini">Freigeben</button></form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <h2>Regelmäßige Verfügbarkeit</h2>
    <?php if ($verfByTag === []): ?><p class="leer">Noch nicht eingetragen.</p><?php else: ?>
    <div class="liste"><?php foreach (WOCHENTAGE as $n => $k): if (!isset($verfByTag[$n])) continue; ?>
      <div class="eintrag eintrag-2"><div><div class="titel"><?= $k ?></div></div><div class="klein"><?= e(implode(', ', $verfByTag[$n])) ?></div></div>
    <?php endforeach; ?></div><?php endif; ?>

    <h2>Stammobjekte</h2>
    <?php if ($objekte === []): ?><p class="leer">Keinem Objekt fest zugeteilt.</p><?php else: ?>
    <div class="liste"><?php foreach ($objekte as $o): ?>
      <div class="eintrag eintrag-2"><div><div class="titel"><a href="/admin/?s=objekte&amp;t=detail&amp;id=<?= (int) $o['id'] ?>"><?= e($o['name']) ?></a></div><div class="sub"><?= e($o['leistung']) ?> · <?= e(wochentageText((int) $o['wochentage'])) ?> ab <?= e(zeitKurz($o['uhrzeit_von'])) ?></div></div></div>
    <?php endforeach; ?></div><?php endif; ?>

    <?php $beschw = $db->rawAll('SELECT b.id, b.datum, b.kategorie, b.status, o.name objekt FROM beschwerden b JOIN objekte o ON o.id = b.objekt_id WHERE b.mitarbeiter_id = ? AND b.betrieb_id = ? AND b.datum >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) ORDER BY b.datum DESC LIMIT 10', [$id, $db->tenant()]); ?>
    <h2>Beschwerden, 12 Monate</h2>
    <?php if ($beschw === []): ?><p class="leer" style="padding:10px 0">Keine.</p><?php else: ?>
    <div class="liste"><?php foreach ($beschw as $bx): ?><div class="eintrag eintrag-2"><div><div class="titel"><a href="/admin/?s=beschwerden&amp;t=detail&amp;id=<?= (int) $bx['id'] ?>"><?= datumDe($bx['datum']) ?> · <?= e(['qualitaet' => 'Qualität', 'vergessen' => 'Vergessen', 'puenktlichkeit' => 'Pünktlichkeit', 'verhalten' => 'Verhalten', 'schaden' => 'Schaden', 'sonstiges' => 'Sonstiges'][$bx['kategorie']] ?? $bx['kategorie']) ?></a></div><div class="sub"><?= e($bx['objekt']) ?></div></div><?= $bx['status'] === 'erledigt' ? '<span class="marke-ok">erledigt</span>' : '<span class="marke-test">offen</span>' ?></div><?php endforeach; ?></div>
    <p class="klein"><a href="/admin/?s=berichte&amp;t=beschwerden&amp;mid=<?= $id ?>">Auswertung</a> · <a href="/admin/?s=berichte&amp;t=abwesenheiten">Abwesenheiten aller Mitarbeiter</a></p>
    <?php endif; ?>

    <h2>Nächste Einsätze</h2>
    <?php if ($naechste === []): ?><p class="leer">Keine geplant.</p><?php else: ?>
    <div class="liste"><?php foreach ($naechste as $n): ?>
      <div class="eintrag eintrag-2"><div><div class="titel"><?= datumDe($n['datum']) ?> · <?= e(zeitKurz($n['uhrzeit_von'])) ?></div><div class="sub"><?= e($n['name']) ?></div></div><span class="marke-aus"><?= e($n['status']) ?></span></div>
    <?php endforeach; ?></div><?php endif; ?>
  </section>
</div>

<?php
// =====================================================================
// FORMULAR
// =====================================================================
else:
    $naechstePnr = $db->rawOne("SELECT MAX(CAST(personalnummer AS UNSIGNED)) m FROM mitarbeiter WHERE betrieb_id = ? AND personalnummer REGEXP '^[0-9]+$'", [$db->tenant()]);
    $vorschlagPnr = (string) (max(1000, (int) ($naechstePnr['m'] ?? 1000)) + 1);
    $f = $pr->fehler;
    $sens = ($istInhaber && $id > 0) ? $db->rawOne('SELECT * FROM mitarbeiter_sensibel WHERE mitarbeiter_id = ? AND betrieb_id = ?', [$id, $db->tenant()]) : null;
?>
<p class="zurueck"><a href="<?= $id > 0 ? '/admin/?s=mitarbeiter&t=detail&id=' . $id : '/admin/?s=mitarbeiter' ?>">← Zurück</a></p>
<h1><?= $id > 0 ? 'Mitarbeiter bearbeiten' : 'Mitarbeiter anlegen' ?></h1>
<form method="post" class="formular" style="max-width:820px">
  <input type="hidden" name="aktion" value="speichern"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <h2 class="erste">Person</h2>
  <div class="drei">
    <div class="g"><label for="pn">Personalnummer</label><input id="pn" name="personalnummer" value="<?= e(wert('personalnummer', $satz, $vorschlagPnr)) ?>" required maxlength="20"><div class="tipp">Login für die App</div><?= feldFehler($f, 'personalnummer') ?></div>
    <div class="g"><label for="vn">Vorname</label><input id="vn" name="vorname" value="<?= e(wert('vorname', $satz)) ?>" required maxlength="80"><?= feldFehler($f, 'vorname') ?></div>
    <div class="g"><label for="nn">Nachname</label><input id="nn" name="nachname" value="<?= e(wert('nachname', $satz)) ?>" required maxlength="80"><?= feldFehler($f, 'nachname') ?></div>
  </div>
  <div class="drei">
    <div class="g"><label for="tel">Telefon</label><input id="tel" name="telefon" type="tel" value="<?= e(wert('telefon', $satz)) ?>" maxlength="40"></div>
    <div class="g"><label for="em">E-Mail</label><input id="em" name="email" type="email" value="<?= e(wert('email', $satz)) ?>" maxlength="150"><?= feldFehler($f, 'email') ?></div>
    <div class="g"><label for="gd">Geburtsdatum</label><input id="gd" name="geburtsdatum" type="date" value="<?= e(wert('geburtsdatum', $satz)) ?>"><?= feldFehler($f, 'geburtsdatum') ?></div>
  </div>
  <div class="g"><label for="ad">Straße und Hausnummer</label><input id="ad" name="adresse" value="<?= e(wert('adresse', $satz)) ?>" maxlength="150"></div>
  <div class="zwei">
    <div class="g"><label for="plz">PLZ</label><input id="plz" name="plz" value="<?= e(wert('plz', $satz)) ?>" maxlength="10"></div>
    <div class="g"><label for="ort">Ort</label><input id="ort" name="ort" value="<?= e(wert('ort', $satz, 'Wien')) ?>" maxlength="80"></div>
  </div>
  <div class="zwei">
    <div class="g"><label for="nk">Notfallkontakt</label><input id="nk" name="notfallkontakt_name" value="<?= e(wert('notfallkontakt_name', $satz)) ?>" maxlength="120"></div>
    <div class="g"><label for="nt">Telefon Notfallkontakt</label><input id="nt" name="notfallkontakt_telefon" type="tel" value="<?= e(wert('notfallkontakt_telefon', $satz)) ?>" maxlength="40"></div>
  </div>

  <h2>Anstellung</h2>
  <div class="drei">
    <div class="g"><label for="an">Beschäftigungsausmaß</label><select id="an" name="anstellung"><?php foreach (ANSTELLUNG as $k => $v): ?><option value="<?= $k ?>" <?= wert('anstellung', $satz, 'teilzeit') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="g"><label for="sw">Stunden pro Woche</label><input id="sw" name="stunden_woche" inputmode="decimal" value="<?= e(wert('stunden_woche', $satz) !== '' ? str_replace('.', ',', wert('stunden_woche', $satz)) : '') ?>" required><?= feldFehler($f, 'stunden_woche') ?></div>
    <div class="g"><label for="aw">Arbeitstage pro Woche</label><input id="aw" name="arbeitstage_woche" type="number" min="1" max="7" value="<?= e(wert('arbeitstage_woche', $satz, '5')) ?>"></div>
  </div>
  <div class="drei">
    <div class="g"><label for="ei">Eintritt</label><input id="ei" name="eintrittsdatum" type="date" value="<?= e(wert('eintrittsdatum', $satz, $id > 0 ? '' : date('Y-m-d'))) ?>"><?= feldFehler($f, 'eintrittsdatum') ?></div>
    <div class="g"><label for="au">Austritt</label><input id="au" name="austrittsdatum" type="date" value="<?= e(wert('austrittsdatum', $satz)) ?>"></div>
    <div class="g"><label for="uj">Urlaubstage pro Jahr</label><input id="uj" name="urlaubstage_jahr" inputmode="decimal" value="<?= e(str_replace('.', ',', wert('urlaubstage_jahr', $satz, '25'))) ?>"></div>
  </div>
  <?php $alleQ = $db->select('qualifikationen', 'aktiv = 1', [], 'ORDER BY sortierung'); $meineQ = $id > 0 ? array_map('intval', array_column($db->select('mitarbeiter_qualifikationen', 'mitarbeiter_id = ?', [$id]), 'qualifikation_id')) : []; if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { $meineQ = array_map('intval', (array) ($_POST['quali'] ?? [])); } ?>
  <h2>Qualifikationen</h2>
  <p class="tipp" style="margin-bottom:10px">Wofür diese Kraft eingeteilt werden darf. Der Dienstplan schlägt nur qualifizierte Mitarbeiter für eine Leistung vor.</p>
  <div class="team-wahl" style="margin-bottom:16px"><?php foreach ($alleQ as $qq): ?><label class="tag-haken" title="<?= e($qq['beschreibung'] ?? '') ?>"><input type="checkbox" name="quali[]" value="<?= (int) $qq['id'] ?>" <?= in_array((int) $qq['id'], $meineQ, true) ? 'checked' : '' ?>> <?= e($qq['name']) ?></label><?php endforeach; ?></div>
  <div class="zeile-knoepfe" style="margin-bottom:6px">
    <label class="tag-haken"><input type="checkbox" name="fuehrerschein" value="1" <?= (int) wert('fuehrerschein', $satz, '0') === 1 ? 'checked' : '' ?>> Führerschein</label>
    <label class="tag-haken"><input type="checkbox" name="hat_auto" value="1" <?= (int) wert('hat_auto', $satz, '0') === 1 ? 'checked' : '' ?>> eigenes Auto</label>
    <label class="tag-haken"><input type="checkbox" name="aktiv" value="1" <?= (int) wert('aktiv', $satz, '1') === 1 ? 'checked' : '' ?>> aktiv</label>
  </div>

  <?php if ($istInhaber): ?>
  <h2>Lohn und Verrechnung <span class="klein">nur Inhaber</span></h2>
  <div class="drei">
    <div class="g"><label for="lg">KV-Lohngruppe</label><input id="lg" name="lohngruppe" value="<?= e(wert('lohngruppe', $satz)) ?>" maxlength="10" placeholder="z. B. 6"></div>
    <div class="g"><label for="sl">Stundenlohn brutto</label><input id="sl" name="stundenlohn" inputmode="decimal" value="<?= e(wert('stundenlohn', $satz) !== '' ? str_replace('.', ',', wert('stundenlohn', $satz)) : '') ?>"><?= feldFehler($f, 'stundenlohn') ?></div>
    <div class="g"><label for="vs">Verrechnungssatz an Kunden</label><input id="vs" name="verrechnungssatz" inputmode="decimal" value="<?= e(wert('verrechnungssatz', $satz) !== '' ? str_replace('.', ',', wert('verrechnungssatz', $satz)) : '') ?>"><div class="tipp">optional, überschreibt Objekt und Leistungsart</div></div>
  </div>
  <div class="g" style="max-width:260px"><label for="dj">Anrechenbare Vordienstjahre</label><input id="dj" name="dienstjahre_vorher" inputmode="decimal" value="<?= e(str_replace('.', ',', wert('dienstjahre_vorher', $satz, '0'))) ?>"></div>

  <h2>Sensible Daten <span class="klein">verschlüsselt, nur Inhaber</span></h2>
  <?php if ($id > 0): ?><label class="tag-haken" style="margin-bottom:12px"><input type="checkbox" name="sensibel_aendern" value="1"> Sensible Daten ändern</label><?php endif; ?>
  <div class="drei">
    <div class="g"><label for="sv">Sozialversicherungsnummer</label><input id="sv" name="svnr" inputmode="numeric" maxlength="20" value="<?= e($_POST['svnr'] ?? ($sens && $sens['svnr_enc'] ? Crypto::decrypt($sens['svnr_enc']) : '')) ?>" placeholder="1234 010180" autocomplete="off"><?= feldFehler($f, 'svnr') ?></div>
    <div class="g"><label for="ib">IBAN</label><input id="ib" name="iban" maxlength="40" value="<?= e($_POST['iban'] ?? ($sens && $sens['iban_enc'] ? Crypto::decrypt($sens['iban_enc']) : '')) ?>" autocomplete="off"><?= feldFehler($f, 'iban') ?></div>
    <div class="g"><label for="ki">Kinder (für Lohnverrechnung)</label><input id="ki" name="anzahl_kinder" type="number" min="0" max="20" value="<?= e($_POST['anzahl_kinder'] ?? (string) ($sens['anzahl_kinder'] ?? 0)) ?>"></div>
  </div>
  <?php endif; ?>

  <h2 id="app-zugang">App-Zugang</h2>
  <div class="g" style="max-width:260px"><label for="pi">PIN (4 Ziffern)</label><input id="pi" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" <?= $id === 0 ? 'required' : '' ?> autocomplete="off" placeholder="<?= $id > 0 ? 'leer = unverändert' : '' ?>"><div class="tipp">Login in der App: Personalnummer + PIN. Nach fünf Fehlversuchen 15 Minuten Sperre.</div><?= feldFehler($f, 'pin') ?></div>

  <h2>Notizen</h2>
  <div class="g"><textarea name="notizen" maxlength="4000" aria-label="Notizen"><?= e(wert('notizen', $satz)) ?></textarea></div>

  <div class="zeile-knoepfe">
    <button type="submit">Speichern</button>
    <a class="knopf zweit" href="<?= $id > 0 ? '/admin/?s=mitarbeiter&t=detail&id=' . $id : '/admin/?s=mitarbeiter' ?>">Abbrechen</a>
  </div>
</form>
<?php endif; ?>

<?php
function abwMarke(string $s): string
{
    return match ($s) {
        'genehmigt' => '<span class="marke-ok">genehmigt</span>',
        'abgelehnt' => '<span class="marke-aus">abgelehnt</span>',
        default     => '<span class="marke-test">beantragt</span>',
    };
}

/** Datenschutzinformation fuer Mitarbeiter nach Art. 13 DSGVO. */
function datenschutzHtml(array $betrieb, array $m, array $anb): string
{
    $e = static fn(?string $x): string => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    $firma = $e($betrieb['name']);
    $adr = trim(($betrieb['adresse'] ?? '') . ', ' . ($betrieb['plz'] ?? '') . ' ' . ($betrieb['ort'] ?? ''), ', ');
    $anbieter = $e($anb['firma'] ?? '') . (($anb['adresse'] ?? '') !== '' ? ', ' . $e($anb['adresse']) . ', ' . $e($anb['plz']) . ' ' . $e($anb['ort']) : '');
    $name = $e($m['vorname'] . ' ' . $m['nachname']);
    $adrHtml = $adr !== '' ? ', ' . $e($adr) : '';
    return <<<HTML
<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8"><style>
@page{margin:20mm 20mm 22mm}body{font-family:'DejaVu Sans',sans-serif;font-size:9.5pt;line-height:1.5;color:#13201B}
h1{font-size:15pt;color:#0B5D4A;margin:0 0 2mm}.u{color:#4E5C55;margin-bottom:6mm}
h2{font-size:10.5pt;margin:5mm 0 1.5mm;color:#0B5D4A}p{margin:0 0 2mm}ul{margin:0 0 2mm 5mm;padding:0}li{margin-bottom:1mm}
.box{border:0.5pt solid #CFD6D0;padding:3mm 4mm;margin-top:6mm;font-size:9pt}
.unterschrift{margin-top:12mm;width:100%}.unterschrift td{width:48%;border-top:0.5pt solid #13201B;padding-top:1.5mm;font-size:8.5pt;color:#4E5C55}
.unterschrift td.leer{border:0;width:4%}
</style></head><body>
<h1>Information über die Verarbeitung Ihrer personenbezogenen Daten</h1>
<div class="u">gemäß Art. 13 DSGVO · für {$name}</div>

<h2>1. Verantwortlicher</h2>
<p><b>{$firma}</b>{$adrHtml}</p>

<h2>2. Welche Daten wir verarbeiten und wozu</h2>
<ul>
<li><b>Stammdaten</b> (Name, Kontaktdaten, Anschrift, Geburtsdatum, Personalnummer, Beschäftigungsausmaß, Lohngruppe, Sozialversicherungsnummer, Bankverbindung, Ein- und Austritt, Anzahl der Kinder): zur Erfüllung des Dienstvertrags und der gesetzlichen Melde-, Abgaben- und Lohnverrechnungspflichten. Rechtsgrundlage: Art. 6 Abs. 1 lit. b und c DSGVO.</li>
<li><b>Arbeitszeitdaten</b> (Beginn, Ende, Pausen, Einsatzort): zur gesetzlich vorgeschriebenen Arbeitszeitaufzeichnung nach § 26 AZG sowie zur Lohnabrechnung. Rechtsgrundlage: Art. 6 Abs. 1 lit. c DSGVO. Die Erfassung erfolgt durch Scannen eines Codes am Objekt beim Kommen und Gehen. <b>Es findet keine Ortung Ihres Geräts statt.</b> Erfasst wird nur, dass Sie zu einem Zeitpunkt den Code des jeweiligen Objekts gescannt haben.</li>
<li><b>Abwesenheiten</b> (Art und Zeitraum): zur Dienstplanung und Urlaubsverwaltung. Bei Krankenstand wird kein Grund erfasst. Rechtsgrundlage: Art. 6 Abs. 1 lit. b und c DSGVO.</li>
<li><b>Fotografien am Einsatzort</b>: Sie fotografieren die gereinigten Bereiche zur Dokumentation der Leistung gegenüber dem Kunden. Diese Fotos zeigen Räume, nicht Personen. Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO (Nachweis der Leistungserbringung). Löschung nach drei Monaten.</li>
<li><b>Notfallkontakt</b>: zur Verständigung einer Vertrauensperson bei einem Unfall. Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO.</li>
<li><b>Nutzungsdaten der App</b> (Anmeldezeitpunkte, technische Kennungen): zur Sicherheit des Zugangs. Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO. Löschung nach zwölf Monaten.</li>
</ul>

<h2>3. Wer die Daten erhält</h2>
<ul>
<li><b>Auftragsverarbeiter</b>: Die Software wird betrieben von {$anbieter}. Der Anbieter verarbeitet die Daten ausschließlich in unserem Auftrag auf Servern in Österreich und ist vertraglich zur Vertraulichkeit verpflichtet.</li>
<li><b>Behörden und Sozialversicherung</b>: soweit gesetzlich vorgeschrieben (Österreichische Gesundheitskasse, Finanzamt).</li>
<li><b>Lohnverrechnung / Steuerberatung</b>: soweit wir diese extern beauftragen.</li>
<li><b>Kunden</b>: erhalten Leistungsnachweise mit Ihrem Namen, den Zeiten und den Fotos des jeweiligen Einsatzes. Weitere Daten werden nicht weitergegeben.</li>
</ul>
<p>Eine Übermittlung in Drittländer außerhalb der EU findet nicht statt.</p>

<h2>4. Wie lange wir speichern</h2>
<p>Arbeitszeitaufzeichnungen: mindestens zwei Jahre nach § 26 AZG. Lohnunterlagen: sieben Jahre nach § 132 BAO. Fotografien: drei Monate. Nutzungsdaten: zwölf Monate. Übrige Daten: für die Dauer des Dienstverhältnisses und danach, solange gesetzliche Aufbewahrungspflichten oder Verjährungsfristen bestehen.</p>

<h2>5. Ihre Rechte</h2>
<p>Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch, soweit die gesetzlichen Voraussetzungen vorliegen. In der App können Sie Ihre Stammdaten einsehen und Änderungen beantragen. Beschwerden können Sie an die Österreichische Datenschutzbehörde, Barichgasse 40–42, 1030 Wien, richten.</p>

<h2>6. Pflicht zur Bereitstellung</h2>
<p>Die Angabe der unter Punkt 2 genannten Stammdaten ist gesetzlich bzw. vertraglich erforderlich; ohne sie kann das Dienstverhältnis nicht begründet oder abgerechnet werden. Die Angabe eines Notfallkontakts ist freiwillig.</p>

<div class="box">Dieses Informationsblatt wurde mir ausgehändigt und erläutert. Ich habe es zur Kenntnis genommen. Dies ist keine Einwilligung — die Verarbeitung beruht auf den oben genannten gesetzlichen und vertraglichen Grundlagen.</div>
<table class="unterschrift"><tr><td>Ort, Datum</td><td class="leer"></td><td>Unterschrift {$name}</td></tr></table>
</body></html>
HTML;
}
