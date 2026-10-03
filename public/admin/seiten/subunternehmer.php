<?php
/**
 * Subunternehmer: Stammdaten, Zuordnung zu Objekt-Leistungen, Einsatzuebersicht
 * mit Abrechnungsbasis (Stunden × Satz). Aufruf ueber Mitarbeiter → Subunternehmer.
 * Variablen: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

use App\Auth;

$t  = $_GET['t'] ?? 'liste';
$id = (int) ($_GET['id'] ?? 0);
$pr = new Pruefung();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'speichern') {
        $daten = ['firmenname' => $pr->text('firmenname', 150, true, 'Firmenname'), 'ansprechpartner' => $pr->text('ansprechpartner', 120), 'email' => $pr->email('email'), 'telefon' => $pr->text('telefon', 40),
            'adresse' => $pr->text('adresse', 150), 'plz' => $pr->text('plz', 10), 'ort' => $pr->text('ort', 80), 'uid_nummer' => $pr->text('uid_nummer', 20), 'stundensatz' => $pr->decimal('stundensatz', false, 0), 'notizen' => $pr->text('notizen', 4000), 'aktiv' => $pr->haken('aktiv')];
        if ($pr->ok()) {
            if ($id > 0) { $db->update('subunternehmer', $id, $daten); $ziel = $id; } else { $ziel = $db->insert('subunternehmer', $daten); }
            flash('ok', 'Gespeichert.'); weiter('/admin/?s=subunternehmer&t=detail&id=' . $ziel);
        }
        $t = $id > 0 ? 'bearbeiten' : 'neu';
    }
    if ($aktion === 'zuordnen' && $id > 0) {
        $olid = $pr->int('objekt_leistung_id', true, 1);
        if ($olid !== null && $db->find('objekt_leistungen', $olid) !== null) {
            $db->raw('INSERT INTO subunternehmer_objekte (betrieb_id, subunternehmer_id, objekt_leistung_id, stundensatz_override, aktiv) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE aktiv = 1, stundensatz_override = VALUES(stundensatz_override)',
                [$db->tenant(), $id, $olid, $pr->decimal('stundensatz_override', false, 0)]);
            // Kuenftige Einsaetze dieser Leistung dem Subunternehmer zuweisen, Mitarbeiter entfernen
            $db->raw('UPDATE einsaetze SET subunternehmer_id = ? WHERE objekt_leistung_id = ? AND betrieb_id = ? AND datum >= CURDATE() AND status = \'geplant\'', [$id, $olid, $db->tenant()]);
            $db->raw('DELETE em FROM einsatz_mitarbeiter em JOIN einsaetze e ON e.id = em.einsatz_id WHERE e.objekt_leistung_id = ? AND e.betrieb_id = ? AND e.datum >= CURDATE() AND e.status = \'geplant\'', [$olid, $db->tenant()]);
            flash('ok', 'Zugeordnet. Geplante Einsätze wurden übertragen.');
        }
        weiter('/admin/?s=subunternehmer&t=detail&id=' . $id);
    }
    if ($aktion === 'zuordnung_beenden' && $id > 0) {
        $zid = (int) ($_POST['zuordnung_id'] ?? 0); $z = $db->find('subunternehmer_objekte', $zid);
        if ($z !== null) {
            $db->update('subunternehmer_objekte', $zid, ['aktiv' => 0]);
            $db->raw('UPDATE einsaetze SET subunternehmer_id = NULL WHERE objekt_leistung_id = ? AND subunternehmer_id = ? AND betrieb_id = ? AND datum >= CURDATE() AND status = \'geplant\'', [(int) $z['objekt_leistung_id'], $id, $db->tenant()]);
            flash('ok', 'Zuordnung beendet. Geplante Einsätze sind wieder unbesetzt — bitte im Dienstplan Team zuteilen.');
        }
        weiter('/admin/?s=subunternehmer&t=detail&id=' . $id);
    }
}

$satz = null;
if (in_array($t, ['detail', 'bearbeiten'], true)) { $satz = $db->find('subunternehmer', $id); if ($satz === null) { flash('fehler', 'Nicht gefunden.'); weiter('/admin/?s=subunternehmer'); } }
?>
<p class="zurueck"><a href="/admin/?s=mitarbeiter">← Mitarbeiter</a></p>
<?php if ($t === 'liste'):
    $liste = $db->rawAll("SELECT s.*, (SELECT COUNT(*) FROM subunternehmer_objekte so WHERE so.subunternehmer_id = s.id AND so.aktiv = 1) AS leistungen, (SELECT COUNT(*) FROM einsaetze e WHERE e.subunternehmer_id = s.id AND e.datum >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND e.status = 'erledigt') AS einsaetze_30 FROM subunternehmer s WHERE s.betrieb_id = ? ORDER BY s.aktiv DESC, s.firmenname", [$db->tenant()]);
?>
  <div class="kopfzeile"><h1>Subunternehmer</h1><a class="knopf" href="/admin/?s=subunternehmer&amp;t=neu">Subunternehmer anlegen</a></div>
  <p class="unter">Fremdfirmen, die einzelne Objekte übernehmen. Ihre Einsätze laufen im selben Dienstplan; die Zeiterfassung erfolgt nicht per App, sondern nach deren Abrechnung.</p>
  <?php if ($liste === []): ?><p class="leer">Noch keine Subunternehmer.</p><?php else: ?>
  <table><thead><tr><th>Firma</th><th>Kontakt</th><th class="r">€ / Std</th><th class="r">Leistungen</th><th class="r">Einsätze 30 T.</th></tr></thead><tbody>
    <?php foreach ($liste as $s): ?><tr class="<?= (int) $s['aktiv'] === 0 ? 'aus' : '' ?>"><td><a href="/admin/?s=subunternehmer&amp;t=detail&amp;id=<?= (int) $s['id'] ?>"><?= e($s['firmenname']) ?></a></td><td><?= e($s['ansprechpartner'] ?? '') ?><div class="klein"><?= e($s['telefon'] ?? '') ?></div></td><td class="r"><?= geld($s['stundensatz']) ?></td><td class="r"><?= (int) $s['leistungen'] ?></td><td class="r"><?= (int) $s['einsaetze_30'] ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>

<?php elseif ($t === 'detail'):
    $zuord = $db->rawAll('SELECT so.*, ol.turnus, ol.wochentage, ol.uhrzeit_von, ol.dauer_soll_minuten, o.name AS objekt, l.name AS leistung FROM subunternehmer_objekte so JOIN objekt_leistungen ol ON ol.id = so.objekt_leistung_id JOIN objekte o ON o.id = ol.objekt_id JOIN leistungsarten l ON l.id = ol.leistungsart_id WHERE so.subunternehmer_id = ? AND so.betrieb_id = ? AND so.aktiv = 1 ORDER BY o.name', [$id, $db->tenant()]);
    $frei = $db->rawAll('SELECT ol.id, o.name AS objekt, l.name AS leistung FROM objekt_leistungen ol JOIN objekte o ON o.id = ol.objekt_id JOIN leistungsarten l ON l.id = ol.leistungsart_id WHERE ol.betrieb_id = ? AND ol.aktiv = 1 AND ol.id NOT IN (SELECT objekt_leistung_id FROM subunternehmer_objekte WHERE aktiv = 1) ORDER BY o.name', [$db->tenant()]);
    $monat = $db->rawAll("SELECT DATE_FORMAT(e.datum,'%Y-%m') m, COUNT(*) n, SUM(e.dauer_soll_minuten) min FROM einsaetze e WHERE e.subunternehmer_id = ? AND e.betrieb_id = ? AND e.status = 'erledigt' GROUP BY m ORDER BY m DESC LIMIT 6", [$id, $db->tenant()]);
?>
  <div class="kopfzeile"><h1><?= e($satz['firmenname']) ?><?= (int) $satz['aktiv'] === 0 ? ' <span class="marke-aus">inaktiv</span>' : '' ?></h1><a class="knopf zweit" href="/admin/?s=subunternehmer&amp;t=bearbeiten&amp;id=<?= $id ?>">Bearbeiten</a></div>
  <div class="zweispalt">
    <section>
      <dl class="fakten"><dt>Kontakt</dt><dd><?= e($satz['ansprechpartner'] ?? '—') ?><?= $satz['telefon'] ? ' · ' . e($satz['telefon']) : '' ?><?= $satz['email'] ? '<div class="klein">' . e($satz['email']) . '</div>' : '' ?></dd><dt>Adresse</dt><dd><?= e(trim(($satz['adresse'] ?? '') . ', ' . ($satz['plz'] ?? '') . ' ' . ($satz['ort'] ?? ''), ', ')) ?: '—' ?></dd><dt>UID</dt><dd><?= e($satz['uid_nummer'] ?? '—') ?></dd><dt>Einkaufssatz</dt><dd><?= geld($satz['stundensatz']) ?> / Std</dd><?php if ($satz['notizen']): ?><dt>Notizen</dt><dd><?= nl2br(e($satz['notizen'])) ?></dd><?php endif; ?></dl>
      <h2>Übernommene Leistungen</h2>
      <?php if ($zuord === []): ?><p class="leer">Keine.</p><?php else: ?><table><thead><tr><th>Objekt</th><th>Leistung</th><th>Turnus</th><th class="r">€ / Std</th><th></th></tr></thead><tbody>
        <?php foreach ($zuord as $z): ?><tr><td><b><?= e($z['objekt']) ?></b></td><td><?= e($z['leistung']) ?></td><td class="klein"><?= e(TURNUS[$z['turnus']] ?? $z['turnus']) ?> · <?= e(wochentageText((int) $z['wochentage'])) ?> · <?= zeitKurz($z['uhrzeit_von']) ?></td><td class="r"><?= geld($z['stundensatz_override'] ?? $satz['stundensatz']) ?></td><td class="r"><form method="post" class="inl" onsubmit="return confirm('Zuordnung beenden?')"><input type="hidden" name="aktion" value="zuordnung_beenden"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="zuordnung_id" value="<?= (int) $z['id'] ?>"><button type="submit" class="mini leise">Beenden</button></form></td></tr><?php endforeach; ?>
      </tbody></table><?php endif; ?>
      <?php if ($frei !== []): ?>
      <form method="post" class="formular" style="margin-top:14px"><input type="hidden" name="aktion" value="zuordnen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <h2 class="erste">Leistung übertragen</h2>
        <div class="zwei"><div class="g"><label>Objekt-Leistung</label><select name="objekt_leistung_id" required><?php foreach ($frei as $f): ?><option value="<?= (int) $f['id'] ?>"><?= e($f['objekt']) ?> · <?= e($f['leistung']) ?></option><?php endforeach; ?></select></div><div class="g"><label>Abweichender Satz</label><input name="stundensatz_override" inputmode="decimal" placeholder="<?= e(number_format((float) $satz['stundensatz'], 2, ',', '')) ?>"></div></div>
        <p class="klein" style="margin-bottom:12px">Geplante Einsätze werden dem Subunternehmer übertragen; das Stammteam wird dort abgezogen.</p>
        <button type="submit" class="mini">Übertragen</button></form>
      <?php endif; ?>
    </section>
    <section>
      <h2 style="margin-top:0">Abrechnungsbasis</h2>
      <p class="klein" style="margin-bottom:10px">Erledigte Einsätze je Monat × Soll-Dauer — zum Abgleich mit der Rechnung des Subunternehmers.</p>
      <?php if ($monat === []): ?><p class="leer">Noch keine erledigten Einsätze.</p><?php else: ?><table><thead><tr><th>Monat</th><th class="r">Einsätze</th><th class="r">Soll-Std</th><th class="r">≈ Betrag</th></tr></thead><tbody>
        <?php foreach ($monat as $m): ?><tr><td><?= e(substr($m['m'], 5) . '/' . substr($m['m'], 0, 4)) ?></td><td class="r"><?= (int) $m['n'] ?></td><td class="r"><?= e(number_format((int) $m['min'] / 60, 1, ',', '')) ?></td><td class="r"><?= geld(((int) $m['min'] / 60) * (float) $satz['stundensatz']) ?></td></tr><?php endforeach; ?>
      </tbody></table><?php endif; ?>
    </section>
  </div>

<?php else: $f = $pr->fehler; ?>
  <h1><?= $id > 0 ? 'Subunternehmer bearbeiten' : 'Subunternehmer anlegen' ?></h1>
  <form method="post" class="formular"><input type="hidden" name="aktion" value="speichern"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="g"><label for="fn">Firmenname</label><input id="fn" name="firmenname" value="<?= e(wert('firmenname', $satz)) ?>" required maxlength="150"><?= feldFehler($f, 'firmenname') ?></div>
    <div class="drei"><div class="g"><label for="ap">Ansprechperson</label><input id="ap" name="ansprechpartner" value="<?= e(wert('ansprechpartner', $satz)) ?>"></div><div class="g"><label for="tel">Telefon</label><input id="tel" name="telefon" type="tel" value="<?= e(wert('telefon', $satz)) ?>"></div><div class="g"><label for="em">E-Mail</label><input id="em" name="email" type="email" value="<?= e(wert('email', $satz)) ?>"><?= feldFehler($f, 'email') ?></div></div>
    <div class="g"><label for="ad">Adresse</label><input id="ad" name="adresse" value="<?= e(wert('adresse', $satz)) ?>"></div>
    <div class="drei"><div class="g"><label for="plz">PLZ</label><input id="plz" name="plz" value="<?= e(wert('plz', $satz)) ?>"></div><div class="g"><label for="ort">Ort</label><input id="ort" name="ort" value="<?= e(wert('ort', $satz, 'Wien')) ?>"></div><div class="g"><label for="uid">UID</label><input id="uid" name="uid_nummer" value="<?= e(wert('uid_nummer', $satz)) ?>"></div></div>
    <div class="g" style="max-width:220px"><label for="ss">Einkaufssatz € / Std</label><input id="ss" name="stundensatz" inputmode="decimal" value="<?= e(wert('stundensatz', $satz) !== '' ? str_replace('.', ',', wert('stundensatz', $satz)) : '') ?>"><?= feldFehler($f, 'stundensatz') ?></div>
    <div class="g"><label for="no">Notizen</label><textarea id="no" name="notizen"><?= e(wert('notizen', $satz)) ?></textarea></div>
    <label class="tag-haken" style="margin-bottom:16px"><input type="checkbox" name="aktiv" value="1" <?= (int) wert('aktiv', $satz, '1') === 1 ? 'checked' : '' ?>> Aktiv</label>
    <div class="zeile-knoepfe"><button type="submit">Speichern</button><a class="knopf zweit" href="/admin/?s=subunternehmer">Abbrechen</a></div>
  </form>
<?php endif; ?>
