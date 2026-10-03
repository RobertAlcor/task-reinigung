<?php
/**
 * Beschwerden, Kundenfeedback und Faktenuebersicht.
 *
 * Die Uebersicht zeigt Zahlen je Objekt und je Mitarbeiter (Beschwerden,
 * Puenktlichkeit, Einsaetze) — ohne Punktwert, ohne Krankenstand. Die
 * Bewertung bleibt beim Menschen.
 * Variablen: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

use App\Auth;

$t  = $_GET['t'] ?? 'liste';    // liste | neu | detail | feedback | uebersicht
$id = (int) ($_GET['id'] ?? 0);
$pr = new Pruefung();

const B_KAT = ['qualitaet' => 'Reinigungsqualität', 'vergessen' => 'Etwas vergessen', 'puenktlichkeit' => 'Pünktlichkeit', 'verhalten' => 'Verhalten', 'schaden' => 'Schaden', 'sonstiges' => 'Sonstiges'];
const B_STATUS = ['offen' => 'offen', 'in_bearbeitung' => 'in Bearbeitung', 'erledigt' => 'erledigt'];

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    $aktion = $_POST['aktion'] ?? '';

    if ($aktion === 'anlegen') {
        $oid = $pr->int('objekt_id', true, 1);
        $obj = $oid ? $db->find('objekte', $oid) : null;
        if ($obj === null) { $pr->fehler['objekt_id'] = 'Objekt wählen.'; }
        $eid = $pr->int('einsatz_id');
        $mid = $pr->int('mitarbeiter_id');
        if ($eid !== null && $db->find('einsaetze', $eid) === null) { $eid = null; }
        if ($mid !== null && $db->find('mitarbeiter', $mid) === null) { $mid = null; }
        $daten = [
            'kunde_id'       => $obj['kunde_id'] ?? null,
            'objekt_id'      => $oid,
            'einsatz_id'     => $eid,
            'mitarbeiter_id' => $mid,
            'datum'          => $pr->datum('datum', true),
            'kategorie'      => $pr->auswahl('kategorie', array_keys(B_KAT), true),
            'beschreibung'   => $pr->text('beschreibung', 4000, true, 'Beschreibung'),
            'status'         => 'offen',
        ];
        if ($pr->ok()) {
            $neu = $db->insert('beschwerden', $daten);
            // Office-Benachrichtigung laut Regel
            $regel = $db->selectOne('benachrichtigungs_regeln', "ereignis = 'beschwerde' AND aktiv = 1");
            if ($regel && (int) $regel['office_informieren'] === 1 && $betrieb['email']) {
                $db->insert('benachrichtigungen', ['kanal' => 'mail', 'empfaenger_typ' => 'office', 'empfaenger_email' => $betrieb['email'],
                    'betreff' => strtr((string) $regel['vorlage_betreff'], ['{objekt}' => $obj['name']]),
                    'inhalt' => strtr((string) $regel['vorlage_text'], ['{objekt}' => $obj['name'], '{datum}' => datumDe($daten['datum'])]) . "\n\n" . $daten['beschreibung'],
                    'referenz_typ' => 'beschwerde', 'referenz_id' => $neu]);
            }
            flash('ok', 'Beschwerde erfasst.');
            weiter('/admin/?s=beschwerden&t=detail&id=' . $neu);
        }
        $t = 'neu';
    }

    if ($aktion === 'bearbeiten' && $id > 0 && $db->find('beschwerden', $id) !== null) {
        $st = $pr->auswahl('status', array_keys(B_STATUS), true);
        $mid = $pr->int('mitarbeiter_id');
        if ($mid !== null && $db->find('mitarbeiter', $mid) === null) { $mid = null; }
        if ($pr->ok()) {
            $db->update('beschwerden', $id, ['status' => $st, 'massnahmen' => $pr->text('massnahmen', 4000), 'mitarbeiter_id' => $mid,
                'kategorie' => $pr->auswahl('kategorie', array_keys(B_KAT)) ?? 'sonstiges',
                'bearbeitet_von' => (int) $user['id'], 'bearbeitet_am' => date('Y-m-d H:i:s')]);
            flash('ok', 'Gespeichert.');
        }
        weiter('/admin/?s=beschwerden&t=detail&id=' . $id);
    }

    if ($aktion === 'feedback') {
        $oid = $pr->int('objekt_id', true, 1);
        $obj = $oid ? $db->find('objekte', $oid) : null;
        if ($obj === null) { $pr->fehler['objekt_id'] = 'Objekt wählen.'; }
        $bew = $pr->int('bewertung', true, 1, 5);
        if ($pr->ok()) {
            $db->insert('kundenfeedback', ['kunde_id' => $obj['kunde_id'], 'objekt_id' => $oid, 'bewertung' => $bew,
                'kommentar' => $pr->text('kommentar', 2000), 'kontaktperson' => $pr->text('kontaktperson', 120), 'datum' => $pr->datum('datum') ?? date('Y-m-d')]);
            flash('ok', 'Feedback gespeichert.');
        } else {
            flash('fehler', implode(' ', $pr->fehler));
        }
        weiter('/admin/?s=beschwerden&t=feedback');
    }
}

$objekte = $db->rawAll('SELECT o.id, o.name, k.firmenname FROM objekte o JOIN kunden k ON k.id = o.kunde_id WHERE o.betrieb_id = ? AND o.aktiv = 1 ORDER BY k.firmenname, o.name', [$db->tenant()]);
$mitarbeiter = $db->select('mitarbeiter', 'aktiv = 1', [], 'ORDER BY nachname, vorname');

$tabs = ['liste' => 'Beschwerden', 'feedback' => 'Kundenfeedback', 'uebersicht' => 'Übersicht'];
?>
<div class="kopfzeile">
  <h1>Qualität</h1>
  <a class="knopf" href="/admin/?s=beschwerden&amp;t=neu">Beschwerde erfassen</a>
</div>
<nav class="tabs">
  <?php foreach ($tabs as $k => $v): ?><a href="/admin/?s=beschwerden&amp;t=<?= $k ?>" class="<?= ($t === $k || ($t === 'detail' && $k === 'liste') || ($t === 'neu' && $k === 'liste')) ? 'aktiv' : '' ?>"><?= e($v) ?></a><?php endforeach; ?>
</nav>

<?php
// =====================================================================
// LISTE
// =====================================================================
if ($t === 'liste'):
    $filter = $_GET['f'] ?? 'offen';
    $where = $filter === 'alle' ? '1=1' : ($filter === 'offen' ? "b.status <> 'erledigt'" : "b.status = 'erledigt'");
    $liste = $db->rawAll(
        "SELECT b.*, o.name AS objekt, k.firmenname AS kunde, CONCAT(m.vorname,' ',m.nachname) AS mitarbeiter
           FROM beschwerden b JOIN objekte o ON o.id = b.objekt_id LEFT JOIN kunden k ON k.id = b.kunde_id LEFT JOIN mitarbeiter m ON m.id = b.mitarbeiter_id
          WHERE b.betrieb_id = ? AND {$where} ORDER BY b.status = 'erledigt', b.datum DESC LIMIT 200", [$db->tenant()]);
?>
  <form method="get" class="filter"><input type="hidden" name="s" value="beschwerden">
    <select name="f" onchange="this.form.submit()" aria-label="Filter"><option value="offen" <?= $filter === 'offen' ? 'selected' : '' ?>>Offene</option><option value="erledigt" <?= $filter === 'erledigt' ? 'selected' : '' ?>>Erledigte</option><option value="alle" <?= $filter === 'alle' ? 'selected' : '' ?>>Alle</option></select></form>
  <?php if ($liste === []): ?><p class="leer">Keine Beschwerden.</p><?php else: ?>
  <table>
    <thead><tr><th>Datum</th><th>Objekt</th><th>Kategorie</th><th>Beschreibung</th><th>Mitarbeiter</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($liste as $b): ?>
      <tr class="<?= $b['status'] === 'erledigt' ? 'aus' : '' ?>">
        <td class="nowrap"><a href="/admin/?s=beschwerden&amp;t=detail&amp;id=<?= (int) $b['id'] ?>"><?= datumDe($b['datum']) ?></a></td>
        <td><?= e($b['objekt']) ?><div class="klein"><?= e($b['kunde'] ?? '') ?></div></td>
        <td><?= e(B_KAT[$b['kategorie']] ?? $b['kategorie'] ?? '—') ?></td>
        <td><?= e(mb_strimwidth($b['beschreibung'], 0, 90, '…')) ?></td>
        <td><?= e($b['mitarbeiter'] ?? '—') ?></td>
        <td><?= bMarke($b['status']) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>

<?php
// =====================================================================
// NEU
// =====================================================================
elseif ($t === 'neu'):
    $f = $pr->fehler;
    $vorObjekt = (int) ($_GET['objekt'] ?? ($_POST['objekt_id'] ?? 0));
    $einsaetze = $vorObjekt > 0 ? $db->rawAll(
        "SELECT e.id, e.datum, e.uhrzeit_von, (SELECT GROUP_CONCAT(CONCAT(m.vorname,' ',m.nachname) SEPARATOR ', ') FROM einsatz_mitarbeiter em JOIN mitarbeiter m ON m.id = em.mitarbeiter_id WHERE em.einsatz_id = e.id) AS team
           FROM einsaetze e WHERE e.objekt_id = ? AND e.betrieb_id = ? AND e.datum <= CURDATE() AND e.status <> 'storniert' ORDER BY e.datum DESC LIMIT 15", [$vorObjekt, $db->tenant()]) : [];
?>
  <form method="post" class="formular">
    <input type="hidden" name="aktion" value="anlegen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <h2 class="erste">Beschwerde erfassen</h2>
    <div class="zwei">
      <div class="g"><label for="ob">Objekt</label><select id="ob" name="objekt_id" required onchange="location.href='/admin/?s=beschwerden&t=neu&objekt='+this.value">
        <option value="">— wählen —</option><?php foreach ($objekte as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $vorObjekt === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['firmenname']) ?> · <?= e($o['name']) ?></option><?php endforeach; ?>
      </select><?= feldFehler($f, 'objekt_id') ?></div>
      <div class="g"><label for="da">Datum des Vorfalls</label><input id="da" name="datum" type="date" value="<?= e(wert('datum', null, date('Y-m-d'))) ?>" required><?= feldFehler($f, 'datum') ?></div>
    </div>
    <div class="zwei">
      <div class="g"><label for="ei">Betroffener Einsatz</label><select id="ei" name="einsatz_id"><option value="">— keiner / unbekannt —</option>
        <?php foreach ($einsaetze as $es): ?><option value="<?= (int) $es['id'] ?>" <?= (int) wert('einsatz_id') === (int) $es['id'] ? 'selected' : '' ?>><?= datumDe($es['datum']) ?> <?= zeitKurz($es['uhrzeit_von']) ?> · <?= e($es['team'] ?? 'ohne Team') ?></option><?php endforeach; ?></select>
        <?php if ($vorObjekt === 0): ?><div class="tipp">Erst Objekt wählen</div><?php endif; ?></div>
      <div class="g"><label for="mi">Betroffener Mitarbeiter</label><select id="mi" name="mitarbeiter_id"><option value="">— unbekannt —</option><?php foreach ($mitarbeiter as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) wert('mitarbeiter_id') === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nachname'] . ', ' . $m['vorname']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="g"><label for="ka">Kategorie</label><select id="ka" name="kategorie"><?php foreach (B_KAT as $k => $v): ?><option value="<?= $k ?>" <?= wert('kategorie') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="g"><label for="be">Was wurde beanstandet?</label><textarea id="be" name="beschreibung" rows="4" required maxlength="4000"><?= e(wert('beschreibung')) ?></textarea><?= feldFehler($f, 'beschreibung') ?></div>
    <div class="zeile-knoepfe"><button type="submit">Erfassen</button><a class="knopf zweit" href="/admin/?s=beschwerden">Abbrechen</a></div>
  </form>

<?php
// =====================================================================
// DETAIL
// =====================================================================
elseif ($t === 'detail'):
    $b = $db->rawOne("SELECT b.*, o.name AS objekt, k.firmenname AS kunde, k.email AS kunde_email, bu.benutzername AS bearbeiter,
                             e.datum AS e_datum, e.uhrzeit_von AS e_von
                        FROM beschwerden b JOIN objekte o ON o.id = b.objekt_id LEFT JOIN kunden k ON k.id = b.kunde_id
                   LEFT JOIN benutzer bu ON bu.id = b.bearbeitet_von LEFT JOIN einsaetze e ON e.id = b.einsatz_id
                       WHERE b.id = ? AND b.betrieb_id = ?", [$id, $db->tenant()]);
    if ($b === null) { flash('fehler', 'Nicht gefunden.'); weiter('/admin/?s=beschwerden'); }
?>
  <p class="zurueck"><a href="/admin/?s=beschwerden">← Beschwerden</a></p>
  <div class="zweispalt">
    <section>
      <dl class="fakten">
        <dt>Objekt</dt><dd><a href="/admin/?s=objekte&amp;t=detail&amp;id=<?= (int) $b['objekt_id'] ?>"><?= e($b['objekt']) ?></a><div class="klein"><?= e($b['kunde'] ?? '') ?></div></dd>
        <dt>Datum</dt><dd><?= datumDe($b['datum']) ?></dd>
        <dt>Einsatz</dt><dd><?= $b['e_datum'] ? datumDe($b['e_datum']) . ' ' . zeitKurz($b['e_von']) : '—' ?></dd>
        <dt>Erfasst</dt><dd><?= e(date('d.m.Y H:i', strtotime($b['erstellt_am']))) ?></dd>
        <?php if ($b['bearbeiter']): ?><dt>Bearbeitet</dt><dd><?= e($b['bearbeiter']) ?>, <?= e(date('d.m.Y H:i', strtotime($b['bearbeitet_am']))) ?></dd><?php endif; ?>
      </dl>
      <h2>Beanstandung</h2>
      <div class="notiz" style="max-width:none"><?= nl2br(e($b['beschreibung'])) ?></div>
    </section>
    <section>
      <form method="post" class="formular">
        <input type="hidden" name="aktion" value="bearbeiten"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <h2 class="erste">Bearbeitung</h2>
        <div class="zwei">
          <div class="g"><label for="st">Status</label><select id="st" name="status"><?php foreach (B_STATUS as $k => $v): ?><option value="<?= $k ?>" <?= $b['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
          <div class="g"><label for="ka">Kategorie</label><select id="ka" name="kategorie"><?php foreach (B_KAT as $k => $v): ?><option value="<?= $k ?>" <?= $b['kategorie'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="g"><label for="mi">Betroffener Mitarbeiter</label><select id="mi" name="mitarbeiter_id"><option value="">— unbekannt —</option><?php foreach ($mitarbeiter as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) $b['mitarbeiter_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nachname'] . ', ' . $m['vorname']) ?></option><?php endforeach; ?></select></div>
        <div class="g"><label for="ma">Maßnahmen und Ergebnis</label><textarea id="ma" name="massnahmen" rows="5" maxlength="4000" placeholder="Nachreinigung am …, Gespräch mit …, Kunde informiert am …"><?= e($b['massnahmen'] ?? '') ?></textarea></div>
        <button type="submit">Speichern</button>
      </form>
    </section>
  </div>

<?php
// =====================================================================
// FEEDBACK
// =====================================================================
elseif ($t === 'feedback'):
    $fb = $db->rawAll("SELECT f.*, o.name AS objekt, k.firmenname AS kunde FROM kundenfeedback f JOIN kunden k ON k.id = f.kunde_id LEFT JOIN objekte o ON o.id = f.objekt_id WHERE f.betrieb_id = ? ORDER BY f.datum DESC LIMIT 100", [$db->tenant()]);
    $schnitt = $db->rawOne("SELECT ROUND(AVG(bewertung),1) s, COUNT(*) n FROM kundenfeedback WHERE betrieb_id = ? AND datum >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)", [$db->tenant()]);
?>
  <div class="zweispalt">
    <section>
      <div class="kennzahlen" style="grid-template-columns:1fr 1fr;margin-top:0"><div><b><?= $schnitt['n'] > 0 ? e(str_replace('.', ',', (string) $schnitt['s'])) : '—' ?><small style="font-size:15px;color:var(--text2);font-weight:600"> / 5</small></b><span>Durchschnitt, 12 Monate</span></div><div><b><?= (int) $schnitt['n'] ?></b><span>Rückmeldungen</span></div></div>
      <?php if ($fb === []): ?><p class="leer">Noch kein Feedback erfasst.</p><?php else: ?>
      <table><thead><tr><th>Datum</th><th>Kunde / Objekt</th><th>Bewertung</th><th>Kommentar</th></tr></thead><tbody>
        <?php foreach ($fb as $x): ?><tr><td class="nowrap"><?= datumDe($x['datum']) ?></td><td><?= e($x['kunde']) ?><div class="klein"><?= e($x['objekt'] ?? '') ?></div></td><td><?= sterne((int) $x['bewertung']) ?></td><td><?= e($x['kommentar'] ?? '') ?><?= $x['kontaktperson'] ? '<div class="klein">' . e($x['kontaktperson']) . '</div>' : '' ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </section>
    <section>
      <h2 style="margin-top:0">Feedback erfassen</h2>
      <form method="post" class="formular">
        <input type="hidden" name="aktion" value="feedback"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <div class="g"><label for="fo">Objekt</label><select id="fo" name="objekt_id" required><option value="">— wählen —</option><?php foreach ($objekte as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['firmenname']) ?> · <?= e($o['name']) ?></option><?php endforeach; ?></select></div>
        <div class="zwei">
          <div class="g"><label for="fbw">Bewertung</label><select id="fbw" name="bewertung"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> — <?= ['', 'sehr unzufrieden', 'unzufrieden', 'in Ordnung', 'zufrieden', 'sehr zufrieden'][$i] ?></option><?php endfor; ?></select></div>
          <div class="g"><label for="fd">Datum</label><input id="fd" name="datum" type="date" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <div class="g"><label for="fk">Kontaktperson</label><input id="fk" name="kontaktperson" maxlength="120"></div>
        <div class="g"><label for="fc">Kommentar</label><textarea id="fc" name="kommentar" rows="3" maxlength="2000"></textarea></div>
        <button type="submit">Speichern</button>
      </form>
    </section>
  </div>

<?php
// =====================================================================
// UEBERSICHT (Fakten, kein Score)
// =====================================================================
elseif ($t === 'uebersicht'):
    $vonDatum = date('Y-m-d', strtotime('-12 months'));
    $jeMa = $db->rawAll(
        "SELECT m.id, CONCAT(m.vorname,' ',m.nachname) AS name,
                (SELECT COUNT(*) FROM einsatz_mitarbeiter em JOIN einsaetze e ON e.id = em.einsatz_id WHERE em.mitarbeiter_id = m.id AND e.datum >= ? AND e.status = 'erledigt') AS einsaetze,
                (SELECT COUNT(*) FROM zeiterfassung z JOIN einsaetze e ON e.id = z.einsatz_id WHERE z.mitarbeiter_id = m.id AND e.datum >= ? AND TIME(z.checkin_zeit) > ADDTIME(e.uhrzeit_von, '00:15:00')) AS verspaetet,
                (SELECT COUNT(*) FROM beschwerden b WHERE b.mitarbeiter_id = m.id AND b.datum >= ?) AS beschwerden,
                (SELECT COUNT(*) FROM zeiterfassung z WHERE z.mitarbeiter_id = m.id AND z.checkin_methode = 'manuell' AND z.checkin_zeit >= ?) AS nachtraege
           FROM mitarbeiter m WHERE m.betrieb_id = ? AND m.aktiv = 1 ORDER BY m.nachname", [$vonDatum, $vonDatum, $vonDatum, $vonDatum, $db->tenant()]);
    $jeObj = $db->rawAll(
        "SELECT o.id, o.name, k.firmenname AS kunde,
                (SELECT COUNT(*) FROM einsaetze e WHERE e.objekt_id = o.id AND e.datum >= ? AND e.status = 'erledigt') AS einsaetze,
                (SELECT COUNT(*) FROM einsaetze e WHERE e.objekt_id = o.id AND e.datum >= ? AND e.status = 'ausgefallen') AS ausgefallen,
                (SELECT COUNT(*) FROM beschwerden b WHERE b.objekt_id = o.id AND b.datum >= ?) AS beschwerden,
                (SELECT ROUND(AVG(f.bewertung),1) FROM kundenfeedback f WHERE f.objekt_id = o.id AND f.datum >= ?) AS bewertung
           FROM objekte o JOIN kunden k ON k.id = o.kunde_id WHERE o.betrieb_id = ? AND o.aktiv = 1 ORDER BY beschwerden DESC, o.name", [$vonDatum, $vonDatum, $vonDatum, $vonDatum, $db->tenant()]);
?>
  <p class="unter">Zahlen der letzten zwölf Monate. Verspätet = Check-in mehr als 15 Minuten nach Beginn. Keine Bewertung, kein Punktwert — die Einordnung bleibt beim Menschen. Krankenstände fließen hier bewusst nicht ein.</p>
  <h2 style="margin-top:0">Je Mitarbeiter</h2>
  <table><thead><tr><th>Mitarbeiter</th><th class="r">Einsätze</th><th class="r">Verspätet</th><th class="r">Nachträge</th><th class="r">Beschwerden</th></tr></thead><tbody>
    <?php foreach ($jeMa as $m): ?><tr><td><a href="/admin/?s=mitarbeiter&amp;t=detail&amp;id=<?= (int) $m['id'] ?>"><?= e($m['name']) ?></a></td><td class="r"><?= (int) $m['einsaetze'] ?></td><td class="r"><?= (int) $m['verspaetet'] ?><?= $m['einsaetze'] > 0 ? '<div class="klein">' . round(100 * $m['verspaetet'] / $m['einsaetze']) . ' %</div>' : '' ?></td><td class="r"><?= (int) $m['nachtraege'] ?></td><td class="r"><?= (int) $m['beschwerden'] > 0 ? '<span class="marke-test">' . (int) $m['beschwerden'] . '</span>' : '0' ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <h2>Je Objekt</h2>
  <table><thead><tr><th>Objekt</th><th class="r">Einsätze</th><th class="r">Ausgefallen</th><th class="r">Beschwerden</th><th>Feedback</th></tr></thead><tbody>
    <?php foreach ($jeObj as $o): ?><tr><td><a href="/admin/?s=objekte&amp;t=detail&amp;id=<?= (int) $o['id'] ?>"><?= e($o['name']) ?></a><div class="klein"><?= e($o['kunde']) ?></div></td><td class="r"><?= (int) $o['einsaetze'] ?></td><td class="r"><?= (int) $o['ausgefallen'] ?></td><td class="r"><?= (int) $o['beschwerden'] > 0 ? '<span class="marke-test">' . (int) $o['beschwerden'] . '</span>' : '0' ?></td><td><?= $o['bewertung'] ? sterne((int) round((float) $o['bewertung'])) . ' <span class="klein">' . e(str_replace('.', ',', (string) $o['bewertung'])) . '</span>' : '—' ?></td></tr><?php endforeach; ?>
  </tbody></table>
<?php endif; ?>

<?php
function bMarke(string $s): string
{
    return match ($s) {
        'erledigt'       => '<span class="marke-ok">erledigt</span>',
        'in_bearbeitung' => '<span class="marke-test">in Bearbeitung</span>',
        default          => '<span class="marke-rot">offen</span>',
    };
}
function sterne(int $n): string
{
    return '<span aria-label="' . $n . ' von 5">' . str_repeat('★', max(0, min(5, $n))) . '<span style="color:var(--linie)">' . str_repeat('★', 5 - max(0, min(5, $n))) . '</span></span>';
}
