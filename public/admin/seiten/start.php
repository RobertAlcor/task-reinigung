<?php
/**
 * Startseite "Heute": Was das Buero jetzt wissen muss.
 * Variablen aus index.php: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

$heute  = date('Y-m-d');
$morgen = date('Y-m-d', strtotime('+1 day'));
$jetzt  = date('H:i:s');
$t      = $db->tenant();

// Einsaetze heute mit Mitarbeitern
$einsaetze = $db->rawAll(
    "SELECT e.id, e.uhrzeit_von, e.uhrzeit_bis, e.status, o.name AS objekt, o.plz, l.name AS leistung,
            s.firmenname AS sub,
            (SELECT GROUP_CONCAT(CONCAT(m.vorname,' ',m.nachname) ORDER BY m.nachname SEPARATOR ', ')
               FROM einsatz_mitarbeiter em JOIN mitarbeiter m ON m.id = em.mitarbeiter_id WHERE em.einsatz_id = e.id) AS team,
            (SELECT COUNT(*) FROM zeiterfassung z WHERE z.einsatz_id = e.id) AS eingestempelt
       FROM einsaetze e
       JOIN objekte o ON o.id = e.objekt_id
       JOIN leistungsarten l ON l.id = e.leistungsart_id
  LEFT JOIN subunternehmer s ON s.id = e.subunternehmer_id
      WHERE e.betrieb_id = ? AND e.datum = ? AND e.status <> 'storniert'
      ORDER BY e.uhrzeit_von",
    [$t, $heute]
);

// Fehlende Check-ins: Einsatz sollte laufen, niemand eingestempelt
$fehlend = array_values(array_filter($einsaetze, static fn($e) =>
    $e['status'] === 'geplant' && $e['uhrzeit_von'] <= $jetzt && (int) $e['eingestempelt'] === 0 && $e['sub'] === null));

// Ersatzbedarf: Einsaetze heute/morgen, deren Mitarbeiter abwesend ist
$ersatz = $db->rawAll(
    "SELECT e.id, e.datum, e.uhrzeit_von, o.name AS objekt, CONCAT(m.vorname,' ',m.nachname) AS mitarbeiter, a.typ
       FROM einsaetze e
       JOIN einsatz_mitarbeiter em ON em.einsatz_id = e.id
       JOIN mitarbeiter m ON m.id = em.mitarbeiter_id
       JOIN abwesenheiten a ON a.mitarbeiter_id = m.id AND a.status = 'genehmigt' AND e.datum BETWEEN a.datum_von AND a.datum_bis
       JOIN objekte o ON o.id = e.objekt_id
      WHERE e.betrieb_id = ? AND e.datum BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY) AND e.status = 'geplant'
        AND em.ist_ersatz = 0
      ORDER BY e.datum, e.uhrzeit_von LIMIT 20",
    [$t, $heute, $heute]
);

$beschwerden = $db->count('beschwerden', "status <> 'erledigt'");
$antraege    = $db->count('abwesenheiten', "status = 'beantragt'");
$anfragen    = $db->count('anfragen', "status = 'neu'");
$objekte     = $db->count('objekte', 'aktiv = 1');
$mitarbeiter = $db->count('mitarbeiter', 'aktiv = 1');
$erledigt    = count(array_filter($einsaetze, static fn($e) => $e['status'] === 'erledigt'));

$typName = ['urlaub' => 'Urlaub', 'krank' => 'abwesend', 'zeitausgleich' => 'Zeitausgleich', 'einschulung' => 'Einschulung', 'sonstiges' => 'abwesend'];
?>
<div class="kopfzeile">
  <h1>Heute, <?= e(strftime_de($heute)) ?></h1>
  <a class="knopf zweit" href="/admin/?s=dienstplan">Zum Dienstplan</a>
</div>

<div class="kennzahlen">
  <a href="/admin/?s=dienstplan"><b><?= count($einsaetze) ?><small style="font-size:16px;color:var(--text2);font-weight:600"> · <?= $erledigt ?> erledigt</small></b><span>Einsätze heute</span></a>
  <a href="/admin/?s=dienstplan" class="<?= $fehlend !== [] ? 'rot' : '' ?>"><b><?= count($fehlend) ?></b><span>nicht eingestempelt</span></a>
  <a href="/admin/?s=dienstplan" class="<?= $ersatz !== [] ? 'gelb' : '' ?>"><b><?= count($ersatz) ?></b><span>Ersatz nötig, 7 Tage</span></a>
  <a href="/admin/?s=beschwerden" class="<?= $beschwerden > 0 ? 'gelb' : '' ?>"><b><?= $beschwerden ?></b><span>offene Beschwerden</span></a>
</div>

<div class="zweispalt">
  <section>
    <h2>Einsätze heute</h2>
    <?php if ($einsaetze === []): ?>
      <p class="leer">Heute sind keine Einsätze geplant.</p>
    <?php else: ?>
    <div class="liste">
      <?php foreach ($einsaetze as $e): ?>
        <div class="eintrag">
          <div class="zeit"><?= e(substr($e['uhrzeit_von'], 0, 5)) ?></div>
          <div>
            <div class="titel"><?= e($e['objekt']) ?> <span class="klein"><?= e($e['plz']) ?></span></div>
            <div class="sub"><?= e($e['leistung']) ?> · <?= e($e['sub'] ?? $e['team'] ?? 'niemand zugeteilt') ?></div>
          </div>
          <div>
            <?php if ($e['status'] === 'erledigt'): ?><span class="marke-ok">erledigt</span>
            <?php elseif ($e['status'] === 'laeuft'): ?><span class="marke-test">läuft</span>
            <?php elseif ($e['status'] === 'ausgefallen'): ?><span class="marke-rot">ausgefallen</span>
            <?php elseif ($e['uhrzeit_von'] <= $jetzt && (int) $e['eingestempelt'] === 0 && $e['sub'] === null): ?><span class="marke-rot">nicht eingestempelt</span>
            <?php else: ?><span class="marke-aus">geplant</span><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <section>
    <?php $anfr = []; try { $anfr = $db->rawAll("SELECT a.status, a.antwort, a.beantwortet_am, a.gesendet_am, e.id einsatz_id, e.datum, e.uhrzeit_von, o.name objekt, CONCAT(m.vorname,' ',m.nachname) name FROM einsatz_anfragen a JOIN einsaetze e ON e.id = a.einsatz_id JOIN objekte o ON o.id = e.objekt_id JOIN mitarbeiter m ON m.id = a.mitarbeiter_id WHERE a.betrieb_id = ? AND (a.status = 'offen' OR a.beantwortet_am > DATE_SUB(NOW(), INTERVAL 48 HOUR)) ORDER BY a.status = 'offen' DESC, a.beantwortet_am DESC, a.gesendet_am DESC LIMIT 12", [$db->tenant()]); } catch (\Throwable) {} ?>
    <?php if ($anfr !== []): ?>
    <h2>Einsatzanfragen</h2>
    <div class="liste">
      <?php foreach ($anfr as $an): ?><div class="eintrag eintrag-2"><div><div class="titel"><?= e($an['name']) ?> — <?= datumDe($an['datum']) ?> <?= zeitKurz($an['uhrzeit_von']) ?> <?= e($an['objekt']) ?></div><div class="sub"><?= $an['status'] === 'offen' ? 'gefragt ' . e(date('d.m. H:i', strtotime($an['gesendet_am']))) . ', noch keine Antwort' : e(date('d.m. H:i', strtotime($an['beantwortet_am']))) . ($an['antwort'] ? ' · „' . e($an['antwort']) . '“' : '') ?></div></div>
        <a href="/admin/?s=dienstplan&amp;t=einsatz&amp;einsatz=<?= (int) $an['einsatz_id'] ?>"><?= $an['status'] === 'zugesagt' ? '<span class="marke-ok">zugesagt</span>' : ($an['status'] === 'abgesagt' ? '<span class="marke-rot">abgesagt</span>' : '<span class="marke-test">offen</span>') ?></a></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <h2>Handlungsbedarf</h2>
    <?php if ($ersatz === [] && $antraege === 0 && $anfragen === 0 && $beschwerden === 0): ?>
      <p class="leer">Nichts offen.</p>
    <?php else: ?>
    <div class="liste">
      <?php foreach ($ersatz as $r): ?>
        <div class="eintrag">
          <div class="zeit"><?= e(date('d.m.', strtotime($r['datum']))) ?></div>
          <div><div class="titel"><?= e($r['objekt']) ?></div><div class="sub"><?= e($r['mitarbeiter']) ?> ist <?= e($typName[$r['typ']] ?? 'abwesend') ?> — Ersatz einteilen</div></div>
          <a class="knopf mini" href="/admin/?s=dienstplan&amp;einsatz=<?= (int) $r['id'] ?>">Ersatz</a>
        </div>
      <?php endforeach; ?>
      <?php if ($antraege > 0): ?>
        <div class="eintrag"><div class="zeit">—</div><div><div class="titel"><?= $antraege ?> Abwesenheitsantr<?= $antraege === 1 ? 'ag' : 'äge' ?></div><div class="sub">warten auf Genehmigung</div></div><a class="knopf mini zweit" href="/admin/?s=mitarbeiter&amp;t=abwesenheiten">Ansehen</a></div>
      <?php endif; ?>
      <?php if ($anfragen > 0): ?>
        <div class="eintrag"><div class="zeit">—</div><div><div class="titel"><?= $anfragen ?> neue Anfrage<?= $anfragen === 1 ? '' : 'n' ?></div><div class="sub">über die Website</div></div><a class="knopf mini zweit" href="/admin/?s=kunden&amp;t=anfragen">Ansehen</a></div>
      <?php endif; ?>
      <?php if ($beschwerden > 0): ?>
        <div class="eintrag"><div class="zeit">—</div><div><div class="titel"><?= $beschwerden ?> offene Beschwerde<?= $beschwerden === 1 ? '' : 'n' ?></div><div class="sub">noch nicht erledigt</div></div><a class="knopf mini zweit" href="/admin/?s=beschwerden">Ansehen</a></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <h2>Bestand</h2>
    <div class="kennzahlen" style="grid-template-columns:1fr 1fr;margin-top:0">
      <a href="/admin/?s=objekte"><b><?= $objekte ?></b><span>aktive Objekte</span></a>
      <a href="/admin/?s=mitarbeiter"><b><?= $mitarbeiter ?></b><span>aktive Mitarbeiter</span></a>
    </div>
  </section>
</div>
<?php
function strftime_de(string $datum): string
{
    $tage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $ts = strtotime($datum);
    return $tage[(int) date('w', $ts)] . ', ' . date('d.m.Y', $ts);
}
