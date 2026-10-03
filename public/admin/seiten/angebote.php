<?php
/**
 * Angebote und Besichtigungen: Liste, PDF, Status, Uebernahme als Objekt.
 * Variablen: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

use App\Auth;
use App\Offer;

$t  = $_GET['t'] ?? 'liste';
$id = (int) ($_GET['id'] ?? 0);

if ($t === 'pdf' && $id > 0) {
    $pfad = Offer::pdf($id);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . basename($pfad) . '"'); readfile($pfad); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    $id = (int) ($_POST['angebot_id'] ?? $id);
    try {
        $aktion = $_POST['aktion'] ?? '';
        if ($aktion === 'status') { Offer::status($id, (string) $_POST['status']); flash('ok', 'Status gesetzt.'); }
        if ($aktion === 'uebernehmen') { $oid = Offer::uebernehmen($id); flash('ok', 'Objekt mit Leistung angelegt. Bitte Adresse und Stammteam prüfen.'); weiter('/admin/?s=objekte&t=detail&id=' . $oid); }
        if ($aktion === 'senden') {
            $a = $db->find('angebote', $id); $k = $a ? $db->find('kunden', (int) $a['kunde_id']) : null;
            if (!$k || !$k['email']) { throw new RuntimeException('Beim Kunden ist keine E-Mail hinterlegt.'); }
            $pfad = Offer::pdf($id);
            \App\Mailer::send((string) $k['email'], (string) $k['firmenname'], 'Angebot ' . $a['angebotsnummer'] . ' – ' . $betrieb['name'],
                "Guten Tag" . ($k['kontaktperson'] ? ' ' . $k['kontaktperson'] : '') . ",\n\nanbei unser Angebot " . $a['angebotsnummer'] . " für die Reinigung Ihrer Räumlichkeiten, gültig bis " . datumDe($a['gueltig_bis']) . ".\n\nBei Fragen sind wir gerne erreichbar.\n\nMit freundlichen Grüßen\n" . $betrieb['name'],
                (string) $betrieb['name'], $betrieb['email'] ?: null, [$pfad => basename($pfad)]);
            Offer::status($id, 'gesendet'); flash('ok', 'Angebot versendet.');
        }
    } catch (\Throwable $e) { flash('fehler', $e->getMessage()); }
    weiter('/admin/?s=angebote');
}

$angebote = $db->rawAll("SELECT a.*, k.firmenname, k.email AS k_email FROM angebote a JOIN kunden k ON k.id = a.kunde_id WHERE a.betrieb_id = ? ORDER BY a.datum DESC, a.id DESC LIMIT 100", [$db->tenant()]);
$besichtigungen = $db->rawAll("SELECT b.*, k.firmenname FROM besichtigungen b LEFT JOIN kunden k ON k.id = b.kunde_id WHERE b.betrieb_id = ? AND b.status <> 'angebot_erstellt' ORDER BY b.aktualisiert_am DESC LIMIT 30", [$db->tenant()]);
$st = ['entwurf' => 'Entwurf', 'gesendet' => 'gesendet', 'angenommen' => 'angenommen', 'abgelehnt' => 'abgelehnt', 'abgelaufen' => 'abgelaufen'];
?>
<div class="kopfzeile"><h1>Angebote</h1><a class="knopf" href="/besichtigung/">Besichtigung starten</a></div>
<p class="unter">Angebote entstehen aus der Besichtigung am Tablet. Ein angenommenes Angebot wird mit einem Klick zu Objekt und Leistung — der Dienstplan läuft dann automatisch.</p>
<?php if ($angebote === []): ?><p class="leer">Noch keine Angebote.</p><?php else: ?>
<table><thead><tr><th>Nummer</th><th>Kunde</th><th>Datum</th><th class="r">Monatlich netto</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($angebote as $a): ?>
    <tr class="<?= in_array($a['status'], ['abgelehnt', 'abgelaufen'], true) ? 'aus' : '' ?>"><td><b><?= e($a['angebotsnummer']) ?></b></td><td><?= e($a['firmenname']) ?></td><td class="nowrap"><?= datumDe($a['datum']) ?><div class="klein">gültig bis <?= datumDe($a['gueltig_bis']) ?></div></td><td class="r"><?= geld($a['netto']) ?></td>
    <td><?= $a['status'] === 'angenommen' ? '<span class="marke-ok">angenommen</span>' : ($a['status'] === 'gesendet' ? '<span class="marke-test">gesendet</span>' : '<span class="marke-aus">' . e($st[$a['status']] ?? $a['status']) . '</span>') ?></td>
    <td class="r nowrap"><a class="knopf mini zweit" href="/admin/?s=angebote&amp;t=pdf&amp;id=<?= (int) $a['id'] ?>" target="_blank">PDF</a>
      <?php if ($a['status'] === 'entwurf' || $a['status'] === 'gesendet'): ?>
        <?php if (\App\Mailer::configured() && $a['k_email']): ?><form method="post" class="inl"><input type="hidden" name="aktion" value="senden"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="angebot_id" value="<?= (int) $a['id'] ?>"><button type="submit" class="mini zweit">Senden</button></form><?php endif; ?>
        <form method="post" class="inl"><input type="hidden" name="aktion" value="uebernehmen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="angebot_id" value="<?= (int) $a['id'] ?>"><button type="submit" class="mini" onclick="return confirm('Angebot als angenommen markieren und Objekt mit Leistung anlegen?')">Angenommen → Objekt</button></form>
        <form method="post" class="inl"><input type="hidden" name="aktion" value="status"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="angebot_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="status" value="abgelehnt"><button type="submit" class="mini leise">Abgelehnt</button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
<?php if ($besichtigungen !== []): ?>
<h2>Besichtigungen ohne Angebot</h2>
<table><thead><tr><th>Kunde</th><th>Objekt</th><th class="r">Fläche</th><th class="r">Kalkuliert</th><th>Stand</th></tr></thead><tbody>
  <?php foreach ($besichtigungen as $b): $k = Offer::kalkulieren($b); ?><tr><td><?= e($b['firmenname'] ?? '— kein Kunde —') ?></td><td><?= e($b['objektart'] ?? '') ?><?= $b['red_flags'] ? '<div class="klein warn-text">Bedenken vermerkt</div>' : '' ?></td><td class="r"><?= $b['gesamtflaeche'] ? e(Offer::zahl((float) $b['gesamtflaeche'])) . ' m²' : '—' ?></td><td class="r"><?= $k['netto_monat'] > 0 ? geld($k['netto_monat']) : '—' ?></td><td class="klein"><?= e(date('d.m.Y', strtotime($b['aktualisiert_am']))) ?> · <a href="/besichtigung/">in der App öffnen</a></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
