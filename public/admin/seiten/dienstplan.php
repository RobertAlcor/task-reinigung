<?php
/**
 * Dienstplan: Wochenansicht, Einsatz oeffnen/aendern, Ersatz einteilen,
 * Einsatz anlegen, Plan aus Objekt-Leistungen aktualisieren.
 * Variablen: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

use App\Auth;
use App\Schedule;

$pr = new Pruefung();
$einsatzId = (int) ($_GET['einsatz'] ?? $_POST['einsatz_id'] ?? 0);
$t = $_GET['t'] ?? ($einsatzId > 0 ? 'einsatz' : 'woche');

// Woche bestimmen
$wocheStart = $_GET['w'] ?? '';
$montag = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $wocheStart) === 1)
    ? (new DateTimeImmutable($wocheStart))->modify('monday this week')
    : (new DateTimeImmutable('today'))->modify('monday this week');
$sonntag = $montag->modify('+6 days');

$STATUS = ['geplant' => 'geplant', 'laeuft' => 'läuft', 'erledigt' => 'erledigt', 'ausgefallen' => 'ausgefallen', 'verschoben' => 'verschoben', 'storniert' => 'storniert'];

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    $aktion = $_POST['aktion'] ?? '';
    $zurueck = '/admin/?s=dienstplan&w=' . $montag->format('Y-m-d');

    if ($aktion === 'generieren') {
        $n = Schedule::generate();
        flash('ok', $n === 0 ? 'Plan ist aktuell, keine neuen Einsätze.' : "{$n} Einsätze angelegt für die nächsten " . Schedule::VORLAUF_TAGE . ' Tage.');
        weiter($zurueck);
    }

    if ($aktion === 'anlegen') {
        $objektId = $pr->int('objekt_id', true, 1);
        $la = $pr->int('leistungsart_id', true, 1);
        $datum = $pr->datum('datum', true);
        $von = $pr->zeit('uhrzeit_von', true); $bis = $pr->zeit('uhrzeit_bis', true);
        if ($von && $bis && $bis <= $von) { $pr->fehler['uhrzeit_bis'] = 'Ende nach Beginn.'; }
        if ($objektId !== null && $db->find('objekte', $objektId) === null) { $pr->fehler['objekt_id'] = 'Objekt unbekannt.'; }
        $team = array_map('intval', (array) ($_POST['team'] ?? []));
        if ($pr->ok()) {
            foreach ($team as $mid) {
                $k = Schedule::conflict($mid, $datum, $von, $bis);
                if ($k !== null) {
                    $m = $db->find('mitarbeiter', $mid);
                    flash('fehler', ($m ? $m['vorname'] . ' ' . $m['nachname'] : 'Mitarbeiter') . ' ist zu dieser Zeit ' . $k . '.');
                    weiter('/admin/?s=dienstplan&t=neu&w=' . $montag->format('Y-m-d'));
                }
            }
            $eid = $db->transaction(static function () use ($db, $objektId, $la, $datum, $von, $bis, $pr, $team): int {
                $eid = $db->insert('einsaetze', [
                    'objekt_id' => $objektId, 'leistungsart_id' => $la, 'datum' => $datum, 'uhrzeit_von' => $von, 'uhrzeit_bis' => $bis,
                    'dauer_soll_minuten' => $pr->int('dauer_soll_minuten', false, 5, 1440) ?? 60,
                    'notizen_office' => $pr->text('notizen_office', 2000), 'status' => 'geplant',
                ]);
                foreach ($team as $mid) {
                    if ($db->find('mitarbeiter', $mid) !== null) {
                        $db->insert('einsatz_mitarbeiter', ['einsatz_id' => $eid, 'mitarbeiter_id' => $mid]);
                    }
                }
                return $eid;
            });
            flash('ok', 'Einsatz angelegt.');
            weiter('/admin/?s=dienstplan&einsatz=' . $eid);
        }
        $t = 'neu';
    }

    if ($aktion === 'anfragen' && $einsatzId > 0) {
        $mid = (int) ($_POST['mitarbeiter_id'] ?? 0); $ma = $db->find('mitarbeiter', $mid); $ein = $db->rawOne('SELECT e.*, o.name objekt FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id WHERE e.id = ? AND e.betrieb_id = ?', [$einsatzId, $db->tenant()]);
        if ($ma !== null && $ein !== null) {
            $offen = $db->rawOne("SELECT id FROM einsatz_anfragen WHERE einsatz_id = ? AND mitarbeiter_id = ? AND status = 'offen'", [$einsatzId, $mid]);
            if ($offen === null) {
                $text = trim((string) ($_POST['nachricht'] ?? ''));
                $db->insert('einsatz_anfragen', ['einsatz_id' => $einsatzId, 'mitarbeiter_id' => $mid, 'nachricht' => mb_substr($text, 0, 500) ?: null, 'gesendet_von' => (int) $user['id']]);
                $db->insert('app_nachrichten', ['mitarbeiter_id' => $mid, 'typ' => 'anfrage', 'titel' => 'Kannst du einspringen? ' . datumDe($ein['datum']) . ' ' . zeitKurz($ein['uhrzeit_von']) . '–' . zeitKurz($ein['uhrzeit_bis']),
                    'nachricht' => $ein['objekt'] . ($text !== '' ? "\n" . $text : '') . "\nBitte in der App unter „Anfragen“ antworten."]);
                try { \App\Push::mitarbeiter($mid); $db->raw('UPDATE einsatz_anfragen SET push_am = NOW() WHERE einsatz_id = ? AND mitarbeiter_id = ? AND push_am IS NULL', [$einsatzId, $mid]); } catch (\Throwable) {}
                flash('ok', 'Anfrage an ' . $ma['vorname'] . ' ' . $ma['nachname'] . ' gesendet. Die Antwort erscheint hier und auf der Startseite.');
            }
        }
        weiter('/admin/?s=dienstplan&t=einsatz&einsatz=' . $einsatzId);
    }
    if ($aktion === 'anfrage_zurueck' && $einsatzId > 0) {
        $db->raw("UPDATE einsatz_anfragen SET status = 'zurueckgezogen' WHERE id = ? AND betrieb_id = ? AND status = 'offen'", [(int) ($_POST['anfrage_id'] ?? 0), $db->tenant()]);
        flash('ok', 'Anfrage zurückgezogen.'); weiter('/admin/?s=dienstplan&t=einsatz&einsatz=' . $einsatzId);
    }

    if ($aktion === 'aendern' && $einsatzId > 0) {
        $e = $db->find('einsaetze', $einsatzId);
        if ($e === null) { flash('fehler', 'Einsatz nicht gefunden.'); weiter($zurueck); }
        $datum = $pr->datum('datum', true);
        $von = $pr->zeit('uhrzeit_von', true); $bis = $pr->zeit('uhrzeit_bis', true);
        if ($von && $bis && $bis <= $von) { $pr->fehler['uhrzeit_bis'] = 'Ende nach Beginn.'; }
        $status = $pr->auswahl('status', array_keys($STATUS), true);
        $team = array_map('intval', (array) ($_POST['team'] ?? []));
        $informieren = !empty($_POST['kunde_informieren']);
        if ($pr->ok()) {
            $verschoben = $datum !== $e['datum'] || $von !== $e['uhrzeit_von'];
            foreach ($team as $mid) {
                $k = Schedule::conflict($mid, $datum, $von, $bis, $einsatzId);
                if ($k !== null && !in_array($status, ['storniert', 'ausgefallen'], true)) {
                    $m = $db->find('mitarbeiter', $mid);
                    flash('fehler', ($m ? $m['vorname'] . ' ' . $m['nachname'] : 'Mitarbeiter') . ' ist zu dieser Zeit ' . $k . '.');
                    weiter('/admin/?s=dienstplan&einsatz=' . $einsatzId);
                }
            }
            $db->transaction(static function () use ($db, $einsatzId, $datum, $von, $bis, $status, $pr, $team, $e): void {
                $db->update('einsaetze', $einsatzId, [
                    'datum' => $datum, 'uhrzeit_von' => $von, 'uhrzeit_bis' => $bis, 'status' => $status,
                    'dauer_soll_minuten' => $pr->int('dauer_soll_minuten', false, 5, 1440) ?? (int) $e['dauer_soll_minuten'],
                    'notizen_office' => $pr->text('notizen_office', 2000),
                ]);
                // Team neu setzen; wer nicht Stammteam ist, gilt als Ersatz
                $stamm = $e['objekt_leistung_id']
                    ? array_map('intval', array_column($db->rawAll('SELECT mitarbeiter_id FROM objekt_leistung_mitarbeiter WHERE objekt_leistung_id = ?', [(int) $e['objekt_leistung_id']]), 'mitarbeiter_id'))
                    : [];
                $db->raw('DELETE FROM einsatz_mitarbeiter WHERE einsatz_id = ? AND betrieb_id = ?', [$einsatzId, $db->tenant()]);
                foreach ($team as $mid) {
                    if ($db->find('mitarbeiter', $mid) !== null) {
                        $db->insert('einsatz_mitarbeiter', ['einsatz_id' => $einsatzId, 'mitarbeiter_id' => $mid, 'ist_ersatz' => in_array($mid, $stamm, true) ? 0 : 1]);
                    }
                }
            });
            $meld = 'Einsatz gespeichert.';
            if ($informieren) {
                $ereignis = in_array($status, ['ausgefallen', 'storniert'], true) ? 'ausfall' : ($verschoben ? 'planaenderung' : 'ersatz');
                $ok = Schedule::notify($einsatzId, $ereignis, ['neuer_termin' => date('d.m.Y', strtotime($datum)) . ' ' . substr($von, 0, 5)]);
                $meld .= $ok ? ' Kunde wird benachrichtigt.' : ' Keine Benachrichtigung: Regel deaktiviert oder keine E-Mail beim Objekt/Kunden.';
            }
            flash('ok', $meld);
            weiter('/admin/?s=dienstplan&w=' . (new DateTimeImmutable($datum))->modify('monday this week')->format('Y-m-d'));
        }
        $t = 'einsatz';
    }
}

// =====================================================================
// EINSATZ (Detail / Bearbeiten / Ersatz)
// =====================================================================
if ($t === 'foto') {
    $f = $db->find('einsatz_fotos', (int) ($_GET['id'] ?? 0));
    if ($f === null || (int) $f['geloescht'] === 1) { http_response_code(404); exit('Nicht gefunden.'); }
    App\Photo::serve((int) $f['id']);
}
if ($t === 'einsatz'):
    $e = $db->rawOne(
        'SELECT e.*, o.name AS objekt_name, o.adresse, o.plz, o.ort, o.email AS objekt_email, k.firmenname, k.email AS kunde_email, l.name AS leistung,
                s.firmenname AS sub_name
           FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id JOIN kunden k ON k.id = o.kunde_id JOIN leistungsarten l ON l.id = e.leistungsart_id
      LEFT JOIN subunternehmer s ON s.id = e.subunternehmer_id
          WHERE e.id = ? AND e.betrieb_id = ?', [$einsatzId, $db->tenant()]);
    if ($e === null) { flash('fehler', 'Einsatz nicht gefunden.'); weiter('/admin/?s=dienstplan'); }
    $team = $db->rawAll('SELECT m.id, m.vorname, m.nachname, m.telefon, em.ist_ersatz FROM einsatz_mitarbeiter em JOIN mitarbeiter m ON m.id = em.mitarbeiter_id WHERE em.einsatz_id = ? ORDER BY m.nachname', [$einsatzId]);
    $teamIds = array_map('intval', array_column($team, 'id'));
    $alle = $db->select('mitarbeiter', 'aktiv = 1', [], 'ORDER BY nachname, vorname');
    $probleme = [];
    foreach ($team as $m) {
        $k = Schedule::conflict((int) $m['id'], (string) $e['datum'], (string) $e['uhrzeit_von'], (string) $e['uhrzeit_bis'], $einsatzId);
        if ($k !== null) { $probleme[(int) $m['id']] = $k; }
    }
    $vorschlag = Schedule::suggest($einsatzId);
    $zeiten = $db->rawAll('SELECT z.*, m.vorname, m.nachname FROM zeiterfassung z JOIN mitarbeiter m ON m.id = z.mitarbeiter_id WHERE z.einsatz_id = ?', [$einsatzId]);
    $f = $pr->fehler;
    $wk = (new DateTimeImmutable((string) $e['datum']))->modify('monday this week')->format('Y-m-d');
?>
<p class="zurueck"><a href="/admin/?s=dienstplan&amp;w=<?= $wk ?>">← Dienstplan</a></p>
<div class="kopfzeile">
  <h1><?= e($e['objekt_name']) ?> <?= statusMarkeEinsatz($e['status']) ?></h1>
  <span class="klein"><?= e(datumDe($e['datum'])) ?> · <?= e(zeitKurz($e['uhrzeit_von'])) ?>–<?= e(zeitKurz($e['uhrzeit_bis'])) ?> · <?= e($e['leistung']) ?></span>
</div>
<?php if ($probleme !== []): ?><div class="meldung fehler">Zuteilung mit Problem: <?php foreach ($team as $m) { if (isset($probleme[(int) $m['id']])) echo e($m['vorname'] . ' ' . $m['nachname']) . ' ist ' . e($probleme[(int) $m['id']]) . '. '; } ?>Bitte Ersatz einteilen.</div><?php endif; ?>

<div class="zweispalt">
  <section>
    <form method="post" class="formular">
      <input type="hidden" name="aktion" value="aendern"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="einsatz_id" value="<?= $einsatzId ?>">
      <h2 class="erste">Termin</h2>
      <div class="drei">
        <div class="g"><label for="d">Datum</label><input id="d" name="datum" type="date" value="<?= e(wert('datum', $e)) ?>" required><?= feldFehler($f, 'datum') ?></div>
        <div class="g"><label for="v">Von</label><input id="v" name="uhrzeit_von" type="time" value="<?= e(zeitKurz(wert('uhrzeit_von', $e))) ?>" required></div>
        <div class="g"><label for="b">Bis</label><input id="b" name="uhrzeit_bis" type="time" value="<?= e(zeitKurz(wert('uhrzeit_bis', $e))) ?>" required><?= feldFehler($f, 'uhrzeit_bis') ?></div>
      </div>
      <div class="zwei">
        <div class="g"><label for="st">Status</label><select id="st" name="status"><?php foreach ($STATUS as $k => $v): ?><option value="<?= $k ?>" <?= wert('status', $e) === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
        <div class="g"><label for="du">Soll-Dauer Minuten</label><input id="du" name="dauer_soll_minuten" type="number" min="5" max="1440" value="<?= e(wert('dauer_soll_minuten', $e)) ?>"></div>
      </div>

      <h2>Team</h2>
      <?php if ($e['sub_name']): ?><p class="tipp" style="margin-bottom:10px">Subunternehmer: <b><?= e($e['sub_name']) ?></b></p><?php endif; ?>
      <div class="team-wahl">
        <?php foreach ($alle as $m): $mid = (int) $m['id']; $drin = in_array($mid, $teamIds, true); ?>
          <label class="tag-haken <?= isset($probleme[$mid]) ? 'problem' : '' ?>"><input type="checkbox" name="team[]" value="<?= $mid ?>" <?= $drin ? 'checked' : '' ?>> <?= e($m['vorname'] . ' ' . $m['nachname']) ?><?= isset($probleme[$mid]) ? ' <span class="klein">(' . e($probleme[$mid]) . ')</span>' : '' ?></label>
        <?php endforeach; ?>
      </div>
      <div class="tipp">Wer nicht zum Stammteam gehört, wird als Ersatz markiert. Konflikte werden beim Speichern geprüft.</div>

      <h2>Notiz fürs Team</h2>
      <div class="g"><textarea name="notizen_office" maxlength="2000" aria-label="Notiz"><?= e(wert('notizen_office', $e)) ?></textarea></div>

      <label class="tag-haken" style="margin-bottom:16px"><input type="checkbox" name="kunde_informieren" value="1"> Kunden per E-Mail informieren<?= !$e['objekt_email'] && !$e['kunde_email'] ? ' <span class="klein">(keine E-Mail hinterlegt)</span>' : '' ?></label>
      <div class="zeile-knoepfe"><button type="submit">Speichern</button><a class="knopf zweit" href="/admin/?s=dienstplan&amp;w=<?= $wk ?>">Abbrechen</a></div>
    </form>
  </section>

  <section>
    <h2 style="margin-top:0">Ersatz einteilen</h2>
    <?php if ($vorschlag === []): ?><p class="leer">Niemand frei — alle aktiven Mitarbeiter sind zugeteilt, abwesend oder belegt.</p>
    <?php else: ?>
    <?php $anfragen = $db->rawAll("SELECT a.*, CONCAT(m.vorname,' ',m.nachname) name FROM einsatz_anfragen a JOIN mitarbeiter m ON m.id = a.mitarbeiter_id WHERE a.einsatz_id = ? AND a.betrieb_id = ? ORDER BY a.gesendet_am DESC", [$einsatzId, $db->tenant()]); ?>
    <?php if ($anfragen !== []): ?><div class="liste" style="margin-bottom:14px"><?php foreach ($anfragen as $an): ?>
      <div class="eintrag eintrag-2"><div><div class="titel"><?= e($an['name']) ?> <?= $an['status'] === 'zugesagt' ? '<span class="marke-ok">hat zugesagt</span>' : ($an['status'] === 'abgesagt' ? '<span class="marke-rot">hat abgesagt</span>' : ($an['status'] === 'offen' ? '<span class="marke-test">Anfrage offen</span>' : '<span class="marke-aus">' . e($an['status']) . '</span>')) ?></div><div class="sub">gefragt <?= e(date('d.m. H:i', strtotime($an['gesendet_am']))) ?><?= $an['beantwortet_am'] ? ' · Antwort ' . e(date('d.m. H:i', strtotime($an['beantwortet_am']))) : '' ?><?= $an['antwort'] ? ' · „' . e($an['antwort']) . '“' : '' ?></div></div>
        <?php if ($an['status'] === 'offen'): ?><form method="post" class="inl"><input type="hidden" name="aktion" value="anfrage_zurueck"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="anfrage_id" value="<?= (int) $an['id'] ?>"><button type="submit" class="mini leise">Zurückziehen</button></form><?php endif; ?></div>
    <?php endforeach; ?></div><?php endif; ?>
    <p class="klein" style="margin-bottom:10px">Sortiert: qualifiziert, kennt das Objekt, laut Plan verfügbar, geringste Wochenauslastung. „Einteilen" setzt das Häkchen links; „Anfragen" schickt die Frage in die App, bei Zusage wird automatisch zugeteilt.</p>
    <div class="liste">
      <?php foreach (array_slice($vorschlag, 0, 10) as $v): ?>
        <div class="eintrag eintrag-2 <?= !$v['qualifiziert'] ? 'aus' : '' ?>">
          <div><div class="titel"><?= e($v['name']) ?> <span class="klein"><?= e(rtrim(rtrim(number_format($v['wochen_std'], 1, ',', ''), '0'), ',')) ?> / <?= e(rtrim(rtrim(number_format($v['wochen_soll'], 1, ',', ''), '0'), ',')) ?> Std diese Woche</span></div>
            <div class="sub"><?= !$v['qualifiziert'] ? '<span class="warn-text">ohne Qualifikation „' . e($v['qualifikation']) . '“</span> · ' : '' ?><?= $v['kennt_objekt'] ? 'kennt das Objekt · ' : '' ?><?= $v['verfuegbar'] ? 'laut Plan verfügbar' : ($v['ohne_plan'] ? 'keine Verfügbarkeit hinterlegt' : 'außerhalb der üblichen Zeiten') ?><?= $v['telefon'] ? ' · <a href="tel:' . e(preg_replace('/\s+/', '', $v['telefon'])) . '">' . e($v['telefon']) . '</a>' : '' ?></div></div>
          <div class="zeile-knoepfe" style="flex-wrap:nowrap">
            <?php if ($v['anfrage_offen']): ?><span class="marke-test">gefragt</span><?php else: ?><form method="post" class="inl"><input type="hidden" name="aktion" value="anfragen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="mitarbeiter_id" value="<?= (int) $v['id'] ?>"><button type="submit" class="mini zweit">Anfragen</button></form><?php endif; ?>
            <button type="button" class="mini zweit" onclick="var c=document.querySelector('input[name=\'team[]\'][value=\'<?= (int) $v['id'] ?>\']');if(c){c.checked=true;c.closest('label').scrollIntoView({block:'center'})}">Einteilen</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2>Objekt</h2>
    <dl class="fakten">
      <dt>Kunde</dt><dd><?= e($e['firmenname']) ?></dd>
      <dt>Adresse</dt><dd><?= e($e['adresse']) ?>, <?= e($e['plz']) ?> <?= e($e['ort']) ?></dd>
      <dt>Benachrichtigt</dt><dd><?= $e['kunde_informiert_am'] ? e(date('d.m.Y H:i', strtotime($e['kunde_informiert_am']))) : 'noch nicht' ?></dd>
      <dt>Objekt</dt><dd><a href="/admin/?s=objekte&amp;t=detail&amp;id=<?= (int) $e['objekt_id'] ?>">Öffnen</a></dd>
    </dl>

    <?php $efotos = $db->select('einsatz_fotos', 'einsatz_id = ? AND geloescht = 0', [$einsatzId], 'ORDER BY typ, aufgenommen_am'); ?>
    <h2>Fotos <span class="klein"><?= count($efotos) ?></span></h2>
    <?php if ($efotos === []): ?><p class="leer" style="padding:8px 0">Keine Fotos. Der Mitarbeiter nimmt sie in der App auf: Vorher beim Ankommen, Nachher nach der Reinigung.</p><?php else: ?>
      <div class="fotoblock-buero">
      <?php foreach (['vorher' => 'Vorher', 'nachher' => 'Nachher', 'schaden' => 'Schaden', 'sonstiges' => 'Sonstiges'] as $ft => $fl): $fg = array_values(array_filter($efotos, static fn($f) => $f['typ'] === $ft)); if ($fg === []) { continue; } ?>
        <div class="fotospalte"><h3><?= $fl ?> <span class="klein"><?= count($fg) ?></span></h3><div class="fotogitter"><?php foreach ($fg as $f): $wer = $db->find('mitarbeiter', (int) $f['mitarbeiter_id']); ?><figure><a href="/admin/?s=dienstplan&amp;t=foto&amp;id=<?= (int) $f['id'] ?>" target="_blank"><img src="/admin/?s=dienstplan&amp;t=foto&amp;id=<?= (int) $f['id'] ?>" alt="<?= e($fl) ?>" loading="lazy"></a><figcaption><?= e(date('H:i', strtotime($f['aufgenommen_am']))) ?> · <?= e($wer ? $wer['vorname'] . ' ' . mb_substr($wer['nachname'], 0, 1) . '.' : '') ?></figcaption></figure><?php endforeach; ?></div></div>
      <?php endforeach; ?>
      </div>
      <p class="klein">Löschung drei Monate nach dem Einsatz. Der Kunde sieht dieselben Fotos im Portal.</p>
    <?php endif; ?>

    <?php if ($zeiten !== []): ?>
    <h2>Zeiterfassung</h2>
    <table><thead><tr><th>Mitarbeiter</th><th>Kommen</th><th>Gehen</th><th class="r">Netto</th></tr></thead><tbody>
    <?php foreach ($zeiten as $z): ?><tr><td><?= e($z['vorname'] . ' ' . $z['nachname']) ?></td><td><?= e(date('H:i', strtotime($z['checkin_zeit']))) ?> <span class="klein"><?= e($z['checkin_methode']) ?></span></td><td><?= $z['checkout_zeit'] ? e(date('H:i', strtotime($z['checkout_zeit']))) : '—' ?></td><td class="r"><?= $z['minuten_netto'] !== null ? (int) $z['minuten_netto'] . ' Min' : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>
</div>

<?php
// =====================================================================
// NEUER EINSATZ
// =====================================================================
elseif ($t === 'neu'):
    $objekte = $db->rawAll('SELECT o.id, o.name, k.firmenname FROM objekte o JOIN kunden k ON k.id = o.kunde_id WHERE o.betrieb_id = ? AND o.aktiv = 1 ORDER BY k.firmenname, o.name', [$db->tenant()]);
    $leistungsarten = $db->select('leistungsarten', 'aktiv = 1', [], 'ORDER BY sortierung');
    $alle = $db->select('mitarbeiter', 'aktiv = 1', [], 'ORDER BY nachname, vorname');
    $f = $pr->fehler;
?>
<p class="zurueck"><a href="/admin/?s=dienstplan&amp;w=<?= $montag->format('Y-m-d') ?>">← Dienstplan</a></p>
<h1>Einsatz anlegen</h1>
<p class="unter">Für außerplanmäßige Termine. Regelmäßige Einsätze entstehen automatisch aus den Leistungen der Objekte.</p>
<form method="post" class="formular">
  <input type="hidden" name="aktion" value="anlegen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <div class="zwei">
    <div class="g"><label for="o">Objekt</label><select id="o" name="objekt_id" required><option value="">— wählen —</option><?php foreach ($objekte as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) wert('objekt_id') === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['firmenname'] . ' – ' . $o['name']) ?></option><?php endforeach; ?></select><?= feldFehler($f, 'objekt_id') ?></div>
    <div class="g"><label for="l">Leistung</label><select id="l" name="leistungsart_id"><?php foreach ($leistungsarten as $la): ?><option value="<?= (int) $la['id'] ?>" <?= (int) wert('leistungsart_id') === (int) $la['id'] ? 'selected' : '' ?>><?= e($la['name']) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="drei">
    <div class="g"><label for="d">Datum</label><input id="d" name="datum" type="date" value="<?= e(wert('datum', null, date('Y-m-d'))) ?>" required><?= feldFehler($f, 'datum') ?></div>
    <div class="g"><label for="v">Von</label><input id="v" name="uhrzeit_von" type="time" value="<?= e(zeitKurz(wert('uhrzeit_von', null, '08:00'))) ?>" required></div>
    <div class="g"><label for="b">Bis</label><input id="b" name="uhrzeit_bis" type="time" value="<?= e(zeitKurz(wert('uhrzeit_bis', null, '10:00'))) ?>" required><?= feldFehler($f, 'uhrzeit_bis') ?></div>
  </div>
  <div class="g" style="max-width:220px"><label for="du">Soll-Dauer Minuten</label><input id="du" name="dauer_soll_minuten" type="number" min="5" max="1440" value="<?= e(wert('dauer_soll_minuten', null, '120')) ?>"></div>
  <div class="g"><label>Team</label><div class="team-wahl"><?php foreach ($alle as $m): ?><label class="tag-haken"><input type="checkbox" name="team[]" value="<?= (int) $m['id'] ?>"> <?= e($m['vorname'] . ' ' . $m['nachname']) ?></label><?php endforeach; ?></div></div>
  <div class="g"><label for="n">Notiz fürs Team</label><textarea id="n" name="notizen_office" maxlength="2000"><?= e(wert('notizen_office')) ?></textarea></div>
  <div class="zeile-knoepfe"><button type="submit">Anlegen</button><a class="knopf zweit" href="/admin/?s=dienstplan">Abbrechen</a></div>
</form>

<?php
// =====================================================================
// WOCHE
// =====================================================================
else:
    $filterMa = (int) ($_GET['m'] ?? 0);
    $filterObj = (int) ($_GET['o'] ?? 0);
    $params = [$db->tenant(), $montag->format('Y-m-d'), $sonntag->format('Y-m-d')];
    $where = '';
    if ($filterObj > 0) { $where .= ' AND e.objekt_id = ?'; $params[] = $filterObj; }
    if ($filterMa > 0) { $where .= ' AND EXISTS (SELECT 1 FROM einsatz_mitarbeiter x WHERE x.einsatz_id = e.id AND x.mitarbeiter_id = ?)'; $params[] = $filterMa; }
    $einsaetze = $db->rawAll(
        "SELECT e.id, e.datum, e.uhrzeit_von, e.uhrzeit_bis, e.status, e.kunde_informiert_am, o.name AS objekt, o.plz, l.name AS leistung, s.firmenname AS sub,
                (SELECT GROUP_CONCAT(CONCAT(m.vorname,' ',LEFT(m.nachname,1),'.',IF(em.ist_ersatz=1,'*','')) ORDER BY m.nachname SEPARATOR ', ')
                   FROM einsatz_mitarbeiter em JOIN mitarbeiter m ON m.id = em.mitarbeiter_id WHERE em.einsatz_id = e.id) AS team,
                (SELECT COUNT(*) FROM einsatz_mitarbeiter em WHERE em.einsatz_id = e.id) AS anzahl,
                (SELECT COUNT(*) FROM einsatz_mitarbeiter em JOIN abwesenheiten a ON a.mitarbeiter_id = em.mitarbeiter_id AND a.status = 'genehmigt' AND e.datum BETWEEN a.datum_von AND a.datum_bis WHERE em.einsatz_id = e.id) AS abwesend
           FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id JOIN leistungsarten l ON l.id = e.leistungsart_id LEFT JOIN subunternehmer s ON s.id = e.subunternehmer_id
          WHERE e.betrieb_id = ? AND e.datum BETWEEN ? AND ? AND e.status <> 'storniert'{$where}
          ORDER BY e.datum, e.uhrzeit_von, o.name", $params);
    $proTag = [];
    foreach ($einsaetze as $x) { $proTag[$x['datum']][] = $x; }
    $mitarbeiterListe = $db->select('mitarbeiter', 'aktiv = 1', [], 'ORDER BY nachname');
    $objektListe = $db->select('objekte', 'aktiv = 1', [], 'ORDER BY name');
    $heute = date('Y-m-d');
    $letzteErzeugung = $db->rawOne('SELECT MAX(datum) m FROM einsaetze WHERE betrieb_id = ? AND objekt_leistung_id IS NOT NULL', [$db->tenant()])['m'] ?? null;
    $planVeraltet = $letzteErzeugung === null || $letzteErzeugung < date('Y-m-d', strtotime('+14 days'));
?>
<div class="kopfzeile">
  <h1>Dienstplan</h1>
  <div class="zeile-knoepfe">
    <form method="post" class="inl"><input type="hidden" name="aktion" value="generieren"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="<?= $planVeraltet ? '' : 'zweit' ?>">Plan aktualisieren</button></form>
    <a class="knopf zweit" href="/admin/?s=dienstplan&amp;t=neu&amp;w=<?= $montag->format('Y-m-d') ?>">Einsatz anlegen</a>
  </div>
</div>
<?php if ($planVeraltet): ?><div class="meldung fehler">Der Plan reicht <?= $letzteErzeugung ? 'nur bis ' . e(datumDe($letzteErzeugung)) : 'noch nicht in die Zukunft' ?>. „Plan aktualisieren" erzeugt die Einsätze aus den Leistungen der Objekte. Mit eingerichtetem Cronjob passiert das täglich von selbst.</div><?php endif; ?>

<div class="wochen-nav">
  <a class="knopf zweit mini" href="/admin/?s=dienstplan&amp;w=<?= $montag->modify('-7 days')->format('Y-m-d') ?>&amp;m=<?= $filterMa ?>&amp;o=<?= $filterObj ?>">← Vorige</a>
  <a class="knopf zweit mini" href="/admin/?s=dienstplan&amp;m=<?= $filterMa ?>&amp;o=<?= $filterObj ?>">Heute</a>
  <a class="knopf zweit mini" href="/admin/?s=dienstplan&amp;w=<?= $montag->modify('+7 days')->format('Y-m-d') ?>&amp;m=<?= $filterMa ?>&amp;o=<?= $filterObj ?>">Nächste →</a>
  <b>KW <?= $montag->format('W') ?> · <?= $montag->format('d.m.') ?> – <?= $sonntag->format('d.m.Y') ?></b>
  <form method="get" class="inl filter" style="margin:0 0 0 auto">
    <input type="hidden" name="s" value="dienstplan"><input type="hidden" name="w" value="<?= $montag->format('Y-m-d') ?>">
    <select name="m" aria-label="Mitarbeiter" onchange="this.form.submit()"><option value="0">Alle Mitarbeiter</option><?php foreach ($mitarbeiterListe as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $filterMa === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nachname'] . ' ' . $m['vorname']) ?></option><?php endforeach; ?></select>
    <select name="o" aria-label="Objekt" onchange="this.form.submit()"><option value="0">Alle Objekte</option><?php foreach ($objektListe as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $filterObj === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select>
  </form>
</div>

<div class="woche-raster">
  <?php for ($i = 0; $i < 7; $i++): $d = $montag->modify("+{$i} days"); $ds = $d->format('Y-m-d'); $liste = $proTag[$ds] ?? []; ?>
  <div class="tag-spalte <?= $ds === $heute ? 'heute' : '' ?> <?= $i >= 5 ? 'we' : '' ?>">
    <div class="tag-kopf"><b><?= WOCHENTAGE[$i + 1] ?></b> <?= $d->format('d.m.') ?><?= $liste !== [] ? ' <span class="klein">· ' . count($liste) . '</span>' : '' ?></div>
    <?php if ($liste === []): ?><div class="tag-leer">—</div><?php endif; ?>
    <?php foreach ($liste as $x): $klasse = (int) $x['abwesend'] > 0 ? 'k-ersatz' : ((int) $x['anzahl'] === 0 && !$x['sub'] ? 'k-leer' : 'k-' . $x['status']); ?>
      <a class="karte <?= $klasse ?>" href="/admin/?s=dienstplan&amp;einsatz=<?= (int) $x['id'] ?>">
        <div class="k-zeit"><?= e(zeitKurz($x['uhrzeit_von'])) ?>–<?= e(zeitKurz($x['uhrzeit_bis'])) ?></div>
        <div class="k-obj"><?= e($x['objekt']) ?></div>
        <div class="k-team"><?= e($x['sub'] ?? $x['team'] ?? 'niemand') ?></div>
        <?php if ((int) $x['abwesend'] > 0): ?><div class="k-hinweis">Ersatz nötig</div><?php elseif ((int) $x['anzahl'] === 0 && !$x['sub']): ?><div class="k-hinweis">unbesetzt</div><?php elseif ($x['status'] !== 'geplant'): ?><div class="k-hinweis"><?= e($STATUS[$x['status']] ?? $x['status']) ?></div><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endfor; ?>
</div>
<p class="klein" style="margin-top:12px">* = Ersatzkraft (nicht Stammteam). Farben: rot = Ersatz nötig, gelb = unbesetzt, grün = erledigt.</p>
<?php endif; ?>

<?php
function statusMarkeEinsatz(string $s): string
{
    return match ($s) {
        'erledigt'    => '<span class="marke-ok">erledigt</span>',
        'laeuft'      => '<span class="marke-test">läuft</span>',
        'ausgefallen', 'storniert' => '<span class="marke-rot">' . e($s) . '</span>',
        'verschoben'  => '<span class="marke-aus">verschoben</span>',
        default       => '<span class="marke-aus">geplant</span>',
    };
}
