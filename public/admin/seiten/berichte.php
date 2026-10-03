<?php
/**
 * Berichte: Kennzahlen 12 Monate, Kunden, Objekte (Deckungsbeitrag nur
 * Inhaber), Mitarbeiter, offene Posten. Jede Tabelle als CSV.
 * Variablen: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

use App\Report;
use App\CustomerInvoice as CI;

const B_KAT_LABEL = ['qualitaet' => 'Reinigungsqualität', 'vergessen' => 'Etwas vergessen', 'puenktlichkeit' => 'Pünktlichkeit', 'verhalten' => 'Verhalten', 'schaden' => 'Schaden', 'sonstiges' => 'Sonstiges'];
$t = $_GET['t'] ?? 'uebersicht';
$istInhaber = $user['rolle'] === 'inhaber';
$jahr = (int) ($_GET['jahr'] ?? date('Y')); if ($jahr < 2020 || $jahr > (int) date('Y') + 1) { $jahr = (int) date('Y'); }
$monatW = (int) ($_GET['monat'] ?? 0);
if ($monatW >= 1 && $monatW <= 12) { $von = sprintf('%04d-%02d-01', $jahr, $monatW); $bis = date('Y-m-t', strtotime($von)); $zeitraumText = CI::monatName($monatW) . ' ' . $jahr; }
else { $von = "{$jahr}-01-01"; $bis = "{$jahr}-12-31"; $zeitraumText = 'Jahr ' . $jahr; }
$monate = Report::monate(12);
$labels = array_map(static fn($m) => mb_substr(CI::monatName((int) substr($m, 5)), 0, 3), $monate);
$zahl = static fn($v, int $d = 1): string => number_format((float) $v, $d, ',', '.');

// CSV-Export
if (($_GET['csv'] ?? '') !== '') {
    $was = $_GET['csv'];
    if ($was === 'kunden') { Report::csv("Kunden-{$von}-{$bis}", ['firmenname' => 'Kunde', 'rechnungen' => 'Rechnungen', 'netto' => 'Netto', 'brutto' => 'Brutto', 'offen' => 'Offen', 'ueberfaellig' => 'Überfällig', 'zahlungsdauer' => 'Zahlungsdauer Tage', 'beschwerden' => 'Beschwerden'], Report::kunden($von, $bis)); }
    if ($was === 'objekte') { $sp = ['name' => 'Objekt', 'firmenname' => 'Kunde', 'einsaetze' => 'Einsätze', 'ausgefallen' => 'Ausgefallen', 'stunden' => 'Stunden Ist', 'soll_stunden' => 'Stunden Soll', 'umsatz' => 'Umsatz netto', 'beschwerden' => 'Beschwerden']; if ($istInhaber) { $sp += ['kosten' => 'Lohnkosten inkl. NK', 'deckung' => 'Deckungsbeitrag', 'deckung_prozent' => 'DB %']; } Report::csv("Objekte-{$von}-{$bis}", $sp, Report::objekte($von, $bis, $istInhaber)); }
    if ($was === 'mitarbeiter') { Report::csv("Mitarbeiter-{$von}-{$bis}", ['name' => 'Mitarbeiter', 'einsaetze' => 'Einsätze', 'stunden' => 'Stunden Ist', 'soll' => 'Stunden Soll', 'verspaetet' => 'Verspätet', 'nachtraege' => 'Nachträge', 'urlaub' => 'Urlaubstage', 'beschwerden' => 'Beschwerden'], Report::mitarbeiter($von, $bis)); }
    if ($was === 'abwesenheiten') { Report::csv("Abwesenheiten-{$von}-{$bis}", ['name' => 'Mitarbeiter', 'arbeitstage' => 'Arbeitstage', 'krank_tage' => 'Krankenstandstage', 'krank_faelle' => 'Krankmeldungen', 'krank_kurz' => 'davon 1 Tag', 'krank_quote' => 'Krankenquote %', 'urlaub_tage' => 'Urlaub', 'za_tage' => 'Zeitausgleich', 'sonst_tage' => 'Sonstige'], Report::abwesenheiten($von, $bis)); }
    if ($was === 'beschwerden') { Report::csv("Beschwerden-{$von}-{$bis}", ['name' => 'Mitarbeiter', 'einsaetze' => 'Einsätze', 'gesamt' => 'Beschwerden', 'je_100' => 'je 100 Einsätze', 'qualitaet' => 'Qualität', 'vergessen' => 'Vergessen', 'puenktlichkeit' => 'Pünktlichkeit', 'verhalten' => 'Verhalten', 'schaden' => 'Schaden', 'offen' => 'Offen'], Report::beschwerdenJeMitarbeiter($von, $bis)); }
    if ($was === 'monate') { $u = Report::umsatzJeMonat($monate); $s = Report::stundenJeMonat($monate); $ei = Report::einsaetzeJeMonat($monate); $rows = []; foreach ($monate as $m) { $rows[] = ['monat' => $m, 'umsatz' => $u[$m], 'stunden' => $s[$m], 'erledigt' => $ei['erledigt'][$m], 'ausgefallen' => $ei['ausgefallen'][$m], 'verspaetet' => $ei['verspaetet'][$m]]; } Report::csv('Monate', ['monat' => 'Monat', 'umsatz' => 'Umsatz netto', 'stunden' => 'Stunden', 'erledigt' => 'Einsätze erledigt', 'ausgefallen' => 'Ausgefallen', 'verspaetet' => 'Verspätet'], $rows); }
    weiter('/admin/?s=berichte');
}
$tabs = ['uebersicht' => 'Übersicht', 'kunden' => 'Kunden', 'objekte' => 'Objekte', 'mitarbeiter' => 'Mitarbeiter', 'abwesenheiten' => 'Abwesenheiten', 'beschwerden' => 'Beschwerden', 'posten' => 'Offene Posten'];
if (!isset($tabs[$t])) { $t = 'uebersicht'; }
$q = '&jahr=' . $jahr . ($monatW ? '&monat=' . $monatW : '');
?>
<div class="kopfzeile"><h1>Berichte</h1>
  <?php if ($t !== 'uebersicht' && $t !== 'posten'): ?><a class="knopf zweit" href="/admin/?s=berichte&amp;t=<?= e($t) ?>&amp;csv=<?= e($t) ?><?= e($q) ?>">Als CSV</a><?php else: ?><a class="knopf zweit" href="/admin/?s=berichte&amp;csv=monate">Monatswerte als CSV</a><?php endif; ?></div>
<nav class="tabs"><?php foreach ($tabs as $k => $v): ?><a href="/admin/?s=berichte&amp;t=<?= $k ?><?= e($q) ?>" class="<?= $t === $k ? 'aktiv' : '' ?>"><?= e($v) ?></a><?php endforeach; ?></nav>
<?php if (in_array($t, ['kunden', 'objekte', 'mitarbeiter', 'abwesenheiten', 'beschwerden'], true)): ?>
<form method="get" class="filter"><input type="hidden" name="s" value="berichte"><input type="hidden" name="t" value="<?= e($t) ?>">
  <select name="monat" aria-label="Monat"><option value="0">Ganzes Jahr</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $monatW ? 'selected' : '' ?>><?= CI::monatName($m) ?></option><?php endfor; ?></select>
  <select name="jahr" aria-label="Jahr"><?php for ($j = (int) date('Y') - 2; $j <= (int) date('Y'); $j++): ?><option value="<?= $j ?>" <?= $j === $jahr ? 'selected' : '' ?>><?= $j ?></option><?php endfor; ?></select>
  <button type="submit" class="zweit">Anzeigen</button><span class="klein" style="align-self:center;margin-left:8px"><?= e($zeitraumText) ?></span></form>
<?php endif; ?>

<?php if ($t === 'uebersicht'):
    $u = Report::umsatzJeMonat($monate); $s = Report::stundenJeMonat($monate); $ei = Report::einsaetzeJeMonat($monate); $op = Report::offenePosten();
    $heuer = array_sum(array_filter($u, static fn($k) => str_starts_with($k, date('Y')), ARRAY_FILTER_USE_KEY));
    $letzter = $monate[count($monate) - 2]; $akt = $monate[count($monate) - 1];
    $satz = ($s[$letzter] > 0) ? $u[$letzter] / $s[$letzter] : 0;
?>
  <div class="kennzahlen">
    <div><b><?= geld($heuer) ?></b><span>Umsatz netto <?= date('Y') ?></span></div>
    <div><b><?= geld($u[$letzter]) ?></b><span>Umsatz <?= e(CI::monatName((int) substr($letzter, 5))) ?></span></div>
    <div><b><?= $zahl($s[$letzter], 0) ?> Std</b><span>geleistet <?= e(CI::monatName((int) substr($letzter, 5))) ?></span></div>
    <div><b><?= $satz > 0 ? geld($satz) : '—' ?></b><span>Umsatz je Stunde <?= e(CI::monatName((int) substr($letzter, 5))) ?></span></div>
  </div>
  <div class="bericht-block"><h2>Umsatz netto je Monat</h2><?= Report::balken([['name' => 'Umsatz', 'werte' => array_values($u)]], $labels, '€') ?></div>
  <div class="zweispalt">
    <div class="bericht-block"><h2>Geleistete Stunden</h2><?= Report::balken([['name' => 'Stunden', 'werte' => array_values($s)]], $labels, 'Std', 560, 180) ?></div>
    <div class="bericht-block"><h2>Einsätze: erledigt, verspätet, ausgefallen</h2><?= Report::balken([['name' => 'erledigt', 'werte' => array_values($ei['erledigt'])], ['name' => 'verspätet', 'werte' => array_values($ei['verspaetet'])], ['name' => 'ausgefallen', 'werte' => array_values($ei['ausgefallen'])]], $labels, '', 560, 180) ?></div>
  </div>
  <div class="bericht-block"><h2>Offene Forderungen nach Fälligkeit</h2>
    <div class="kennzahlen" style="margin:0"><div><b><?= geld($op['nicht_faellig'] ?? 0) ?></b><span>noch nicht fällig</span></div><div class="<?= ($op['bis30'] ?? 0) > 0 ? 'gelb' : '' ?>"><b><?= geld($op['bis30'] ?? 0) ?></b><span>bis 30 Tage überfällig</span></div><div class="<?= ($op['bis60'] ?? 0) > 0 ? 'rot' : '' ?>"><b><?= geld($op['bis60'] ?? 0) ?></b><span>31–60 Tage</span></div><div class="<?= ($op['ueber60'] ?? 0) > 0 ? 'rot' : '' ?>"><b><?= geld($op['ueber60'] ?? 0) ?></b><span>über 60 Tage</span></div></div>
    <p class="klein" style="margin-top:10px"><a href="/admin/?s=rechnungen&amp;f=offen">Zu den offenen Rechnungen</a></p></div>

<?php elseif ($t === 'kunden'): $rows = Report::kunden($von, $bis); $ges = array_sum(array_column($rows, 'netto')); ?>
  <?php if ($rows === []): ?><p class="leer">Keine Rechnungen im Zeitraum.</p><?php else: ?>
  <table><thead><tr><th>Kunde</th><th class="r">Rechnungen</th><th class="r">Netto</th><th class="r">Anteil</th><th class="r">Offen</th><th class="r">Ø Zahlung</th><th class="r">Beschwerden</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><a href="/admin/?s=kunden&amp;t=detail&amp;id=<?= (int) $r['id'] ?>"><?= e($r['firmenname']) ?></a></td><td class="r"><?= (int) $r['rechnungen'] ?></td><td class="r"><b><?= geld($r['netto']) ?></b></td><td class="r"><div class="anteil"><i style="width:<?= $ges > 0 ? round($r['netto'] / $ges * 100) : 0 ?>%"></i><?= $ges > 0 ? round($r['netto'] / $ges * 100) : 0 ?> %</div></td><td class="r <?= (float) $r['ueberfaellig'] > 0 ? 'warn-text' : '' ?>"><?= (float) $r['offen'] > 0 ? geld($r['offen']) : '—' ?></td><td class="r"><?= $r['zahlungsdauer'] !== null ? (int) $r['zahlungsdauer'] . ' Tage' : '—' ?></td><td class="r"><?= (int) $r['beschwerden'] ?: '' ?></td></tr><?php endforeach; ?>
    <tr><td><b>Gesamt</b></td><td class="r"><?= array_sum(array_column($rows, 'rechnungen')) ?></td><td class="r"><b><?= geld($ges) ?></b></td><td></td><td class="r"><?= geld(array_sum(array_column($rows, 'offen'))) ?></td><td></td><td></td></tr>
  </tbody></table><?php endif; ?>
  <p class="klein" style="margin-top:10px">Ø Zahlung = Tage zwischen Rechnungsdatum und Zahlungseingang bei bezahlten Rechnungen. Nachweis je Kunde und Monat: in der Kundenakte unter „Leistungsnachweis".</p>

<?php elseif ($t === 'objekte'): $rows = Report::objekte($von, $bis, $istInhaber); ?>
  <?php if ($rows === []): ?><p class="leer">Keine Einsätze im Zeitraum.</p><?php else: ?>
  <table><thead><tr><th>Objekt</th><th class="r">Einsätze</th><th class="r">Std Ist / Soll</th><th class="r">Umsatz</th><?php if ($istInhaber): ?><th class="r">Lohnkosten</th><th class="r">Deckungsbeitrag</th><?php endif; ?><th class="r">Beschw.</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><a href="/admin/?s=objekte&amp;t=detail&amp;id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a><div class="klein"><?= e($r['firmenname']) ?></div></td><td class="r"><?= (int) $r['einsaetze'] ?><?= (int) $r['ausgefallen'] > 0 ? '<div class="klein warn-text">' . (int) $r['ausgefallen'] . ' ausgefallen</div>' : '' ?></td>
      <td class="r"><?= $zahl($r['stunden']) ?> / <?= $zahl($r['soll_stunden']) ?><?= $r['stunden_abweichung'] !== null ? '<div class="klein ' . (abs($r['stunden_abweichung']) > 15 ? 'warn-text' : '') . '">' . ($r['stunden_abweichung'] > 0 ? '+' : '') . $r['stunden_abweichung'] . ' %</div>' : '' ?></td>
      <td class="r"><b><?= geld($r['umsatz']) ?></b></td>
      <?php if ($istInhaber): ?><td class="r"><?= geld($r['kosten']) ?></td><td class="r"><?= $r['deckung'] !== null ? '<b class="' . ($r['deckung'] < 0 ? 'warn-text' : '') . '">' . geld($r['deckung']) . '</b>' . ($r['deckung_prozent'] !== null ? '<div class="klein">' . $r['deckung_prozent'] . ' %</div>' : '') : '—' ?></td><?php endif; ?>
      <td class="r"><?= (int) $r['beschwerden'] ?: '' ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
  <p class="klein" style="margin-top:10px">Std Ist = erfasste Netto-Stunden, Soll = Summe der Soll-Dauern erledigter Einsätze. Mehr als 15 % Abweichung ist markiert.<?= $istInhaber ? ' Lohnkosten = Stunden × Stundenlohn der eingesetzten Kräfte × 1,30 für Dienstgeberabgaben (Pauschalannahme). Deckungsbeitrag = Umsatz der Rechnungspositionen dieses Objekts abzüglich Lohnkosten; Material, Fahrt und Gemeinkosten sind nicht enthalten.' : '' ?></p>

<?php elseif ($t === 'mitarbeiter'): $rows = Report::mitarbeiter($von, $bis); ?>
  <table><thead><tr><th>Mitarbeiter</th><th class="r">Einsätze</th><th class="r">Std Ist</th><th class="r">Std Soll</th><th class="r">Differenz</th><th class="r">Verspätet</th><th class="r">Nachträge</th><th class="r">Urlaub</th><th class="r">Beschw.</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $diff = (float) $r['stunden'] - (float) $r['soll']; ?><tr class="<?= (int) $r['aktiv'] === 0 ? 'aus' : '' ?>"><td><a href="/admin/?s=mitarbeiter&amp;t=detail&amp;id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td><td class="r"><?= (int) $r['einsaetze'] ?></td><td class="r"><b><?= $zahl($r['stunden']) ?></b></td><td class="r"><?= (float) $r['soll'] > 0 ? $zahl($r['soll']) : '—' ?></td><td class="r <?= (float) $r['soll'] > 0 && $diff < -10 ? 'warn-text' : '' ?>"><?= (float) $r['soll'] > 0 ? ($diff >= 0 ? '+' : '') . $zahl($diff) : '—' ?></td><td class="r"><?= (int) $r['verspaetet'] ?: '' ?></td><td class="r"><?= (int) $r['nachtraege'] ?: '' ?></td><td class="r"><?= (float) $r['urlaub'] > 0 ? $zahl($r['urlaub'], 1) : '' ?></td><td class="r"><?= (int) $r['beschwerden'] ?: '' ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <p class="klein" style="margin-top:10px">Std Soll aus den festgehaltenen Monatsabschlüssen. Fakten ohne Bewertung; Krankenstände sind bewusst nicht enthalten.</p>

<?php elseif ($t === 'abwesenheiten'):
    $rows = Report::abwesenheiten($von, $bis);
    $sumTage = array_sum(array_column($rows, 'krank_tage')); $sumArbeit = array_sum(array_column($rows, 'arbeitstage')); $quoteBetrieb = $sumArbeit > 0 ? round($sumTage / $sumArbeit * 100, 1) : 0;
    $aktive = array_filter($rows, static fn($r) => (int) $r['aktiv'] === 1); $proKopf = count($aktive) > 0 ? round($sumTage / count($aktive), 1) : 0;
    $krankMonat = Report::krankJeMonat($monate);
?>
  <div class="kennzahlen">
    <div><b><?= $zahl($sumTage, 0) ?></b><span>Krankenstandstage <?= e($zeitraumText) ?></span></div>
    <div class="<?= $quoteBetrieb > 5 ? 'gelb' : '' ?>"><b><?= $zahl($quoteBetrieb) ?> %</b><span>Krankenquote Betrieb</span></div>
    <div><b><?= $zahl($proKopf) ?></b><span>Tage je aktivem Mitarbeiter</span></div>
    <div><b><?= $zahl(array_sum(array_column($rows, 'urlaub_tage')), 0) ?></b><span>Urlaubstage genommen</span></div>
  </div>
  <div class="bericht-block"><h2>Krankenstandstage je Monat (Betrieb)</h2><?= Report::balken([['name' => 'Kranktage', 'werte' => array_values($krankMonat)]], $labels, 'Tage', 720, 160) ?></div>
  <table><thead><tr><th>Mitarbeiter</th><th class="r">Arbeitstage</th><th class="r">Krank Tage</th><th class="r">Meldungen</th><th class="r">davon 1 Tag</th><th class="r">Mo/Fr-Beginn</th><th class="r">Quote</th><th class="r">Urlaub</th><th class="r">ZA</th><th class="r">Sonst.</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr class="<?= (int) $r['aktiv'] === 0 ? 'aus' : '' ?>"><td><a href="/admin/?s=mitarbeiter&amp;t=detail&amp;id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td><td class="r"><?= (int) $r['arbeitstage'] ?></td><td class="r"><b><?= $zahl($r['krank_tage'], 0) ?></b></td><td class="r"><?= (int) $r['krank_faelle'] ?></td><td class="r"><?= (int) $r['krank_kurz'] ?: '' ?></td><td class="r"><?= (int) $r['krank_mo_fr'] ?: '' ?></td><td class="r"><?= $r['krank_quote'] !== null ? '<span class="' . ($r['krank_quote'] > 8 ? 'warn-text' : '') . '">' . $zahl($r['krank_quote']) . ' %</span>' : '—' ?></td><td class="r"><?= (float) $r['urlaub_tage'] > 0 ? $zahl($r['urlaub_tage']) : '' ?></td><td class="r"><?= (float) $r['za_tage'] > 0 ? $zahl($r['za_tage']) : '' ?></td><td class="r"><?= (float) $r['sonst_tage'] > 0 ? $zahl($r['sonst_tage']) : '' ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <div class="hinweis-recht">
    <b>Einordnung.</b> Krankenquote = Krankenstandstage geteilt durch Arbeitstage laut Anstellung im Zeitraum. In Österreich liegt der Durchschnitt über alle Branchen bei rund 15 Krankenstandstagen je Beschäftigtem und Jahr (etwa 6 %); in der Reinigung ist er erfahrungsgemäß höher. Ab 8 % ist der Wert markiert — das ist ein Anlass für ein Gespräch, keine Bewertung.
    „Mo/Fr-Beginn" zählt Krankmeldungen, die an einem Montag oder Freitag beginnen; „davon 1 Tag" eintägige Krankenstände. Beides sind reine Häufigkeiten.<br>
    <b>Rechtlich.</b> Dauer und Häufigkeit von Krankenständen darf der Arbeitgeber erfassen und auswerten (Entgeltfortzahlung, Personalplanung); der Grund wird nirgends gespeichert (Art. 9 DSGVO). Diese Übersicht ist nur für Inhaber und Büro sichtbar. Sie ist keine automatisierte Bewertung — eine solche wäre bei Bestehen eines Betriebsrats zustimmungspflichtig (§ 96 ArbVG).
  </div>

<?php elseif ($t === 'beschwerden'):
    $rows = Report::beschwerdenJeMitarbeiter($von, $bis); $detailId = (int) ($_GET['mid'] ?? 0);
    $gesamt = array_sum(array_column($rows, 'gesamt')); $einsGesamt = array_sum(array_column($rows, 'einsaetze')); $quoteBetrieb = $einsGesamt > 0 ? round($gesamt / $einsGesamt * 100, 1) : 0;
    $ohne = $db->rawOne("SELECT COUNT(*) n FROM beschwerden WHERE betrieb_id = ? AND mitarbeiter_id IS NULL AND datum BETWEEN ? AND ?", [$db->tenant(), $von, $bis])['n'] ?? 0;
?>
  <div class="kennzahlen">
    <div><b><?= $gesamt + (int) $ohne ?></b><span>Beschwerden <?= e($zeitraumText) ?></span></div>
    <div><b><?= $zahl($quoteBetrieb) ?></b><span>je 100 Einsätze, Betrieb</span></div>
    <div><b><?= (int) $ohne ?></b><span>ohne Zuordnung zu einer Person</span></div>
    <div><b><?= array_sum(array_column($rows, 'offen')) ?></b><span>noch offen</span></div>
  </div>
  <table><thead><tr><th>Mitarbeiter</th><th class="r">Einsätze</th><th class="r">Beschwerden</th><th class="r">je 100</th><th class="r">Qualität</th><th class="r">Vergessen</th><th class="r">Pünktlichkeit</th><th class="r">Verhalten</th><th class="r">Schaden</th><th class="r">Offen</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr class="<?= (int) $r['aktiv'] === 0 ? 'aus' : '' ?>"><td><a href="/admin/?s=berichte&amp;t=beschwerden&amp;mid=<?= (int) $r['id'] ?><?= e($q) ?>"><?= e($r['name']) ?></a></td><td class="r"><?= (int) $r['einsaetze'] ?></td><td class="r"><b><?= (int) $r['gesamt'] ?></b></td><td class="r"><?= $r['je_100'] !== null ? '<span class="' . ($r['je_100'] > $quoteBetrieb * 1.5 && (int) $r['gesamt'] >= 2 ? 'warn-text' : '') . '">' . $zahl($r['je_100']) . '</span>' : '—' ?></td><td class="r"><?= (int) $r['qualitaet'] ?: '' ?></td><td class="r"><?= (int) $r['vergessen'] ?: '' ?></td><td class="r"><?= (int) $r['puenktlichkeit'] ?: '' ?></td><td class="r"><?= (int) $r['verhalten'] ?: '' ?></td><td class="r"><?= (int) $r['schaden'] ?: '' ?></td><td class="r"><?= (int) $r['offen'] ? '<span class="marke-test">' . (int) $r['offen'] . '</span>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if ($detailId > 0): $liste = Report::beschwerdenListe($detailId, $von, $bis); $mname = ''; foreach ($rows as $r) { if ((int) $r['id'] === $detailId) { $mname = $r['name']; } } ?>
    <h2>Beschwerden zu <?= e($mname) ?> — <?= e($zeitraumText) ?></h2>
    <?php if ($liste === []): ?><p class="leer">Keine.</p><?php else: ?><div class="liste"><?php foreach ($liste as $b): ?>
      <div class="eintrag eintrag-2"><div><div class="titel"><?= datumDe($b['datum']) ?> · <?= e(B_KAT_LABEL[$b['kategorie']] ?? $b['kategorie']) ?> · <?= e($b['objekt']) ?><?= $b['firmenname'] ? ' (' . e($b['firmenname']) . ')' : '' ?></div><div class="sub"><?= e($b['beschreibung']) ?><?= $b['massnahmen'] ? '<br><b>Maßnahme:</b> ' . e($b['massnahmen']) : '' ?></div></div><a class="knopf mini zweit" href="/admin/?s=beschwerden&amp;t=detail&amp;id=<?= (int) $b['id'] ?>">Öffnen</a></div>
    <?php endforeach; ?></div><?php endif; ?>
  <?php endif; ?>
  <div class="hinweis-recht"><b>Einordnung.</b> „je 100" = Beschwerden je 100 Einsätze mit Zeiterfassung dieser Person. Markiert, wenn mindestens zwei Beschwerden vorliegen und der Wert das Anderthalbfache des Betriebsdurchschnitts übersteigt. Beschwerden ohne benannte Person zählen nur zur Betriebssumme. Kategorien wie im Beschwerdemodul; die Zuordnung zu einer Person nimmt das Büro vor und kann falsch sein — vor einem Gespräch die Einzelfälle öffnen.</div>

<?php elseif ($t === 'posten'):
    $liste = $db->rawAll("SELECT r.*, k.firmenname, DATEDIFF(CURDATE(), r.faellig_am) tage FROM rechnungen r JOIN kunden k ON k.id = r.kunde_id WHERE r.betrieb_id = ? AND r.status IN ('gestellt','teilbezahlt','mahnung_1','mahnung_2') ORDER BY r.faellig_am", [$db->tenant()]);
?>
  <?php if ($liste === []): ?><p class="leer">Keine offenen Forderungen.</p><?php else: ?>
  <table><thead><tr><th>Rechnung</th><th>Kunde</th><th>Fällig</th><th class="r">Offen</th><th>Stand</th></tr></thead><tbody>
    <?php foreach ($liste as $r): ?><tr><td><a href="/admin/?s=rechnungen&amp;t=detail&amp;id=<?= (int) $r['id'] ?>"><?= e($r['rechnungsnummer']) ?></a></td><td><?= e($r['firmenname']) ?></td><td class="nowrap"><?= datumDe($r['faellig_am']) ?><?= (int) $r['tage'] > 0 ? '<div class="klein warn-text">' . (int) $r['tage'] . ' Tage überfällig</div>' : '' ?></td><td class="r"><b><?= geld((float) $r['brutto'] - (float) ($r['bezahlt_betrag'] ?? 0)) ?></b></td><td><?= $r['status'] === 'mahnung_2' ? '<span class="marke-rot">2. Mahnung</span>' : ($r['status'] === 'mahnung_1' ? '<span class="marke-test">1. Mahnung</span>' : ((int) $r['tage'] > 0 ? '<span class="marke-rot">überfällig</span>' : '<span class="marke-aus">offen</span>')) ?></td></tr><?php endforeach; ?>
    <tr><td colspan="3"><b>Gesamt</b></td><td class="r"><b><?= geld(array_sum(array_map(static fn($r) => (float) $r['brutto'] - (float) ($r['bezahlt_betrag'] ?? 0), $liste))) ?></b></td><td></td></tr>
  </tbody></table><?php endif; ?>
<?php endif; ?>
