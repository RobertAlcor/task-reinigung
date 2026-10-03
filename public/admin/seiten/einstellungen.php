<?php
/**
 * Einstellungen des Betriebs:
 *  betrieb    — Stammdaten, Logo
 *  leistungen — Leistungskatalog mit Standardpreisen
 *  vorlagen   — Benachrichtigungsvorlagen an Kunden und Office
 *  formulare  — Muster als PDF (Kundenhinweis Fotodokumentation, Vereinbarung Privathandy)
 *  benutzer   — Buerobenutzer (nur Inhaber)
 *  vertraege  — AV-Vertrag, AGB, Zustimmungsprotokoll
 * Variablen: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

use App\Auth;
use App\Consent;

$t  = $_GET['t'] ?? 'betrieb';
$pr = new Pruefung();
$istInhaber = $user['rolle'] === 'inhaber';

const KATEGORIEN = ['unterhalt' => 'Unterhaltsreinigung', 'grund' => 'Grundreinigung', 'fenster' => 'Glas / Fenster', 'sonder' => 'Sonderreinigung', 'haushalt' => 'Haushaltsreinigung', 'regie' => 'Regie / Stunden'];
const EREIGNISSE = ['planaenderung' => 'Termin verschoben', 'ausfall' => 'Termin entfällt', 'ersatz' => 'Ersatzkraft kommt', 'erledigt' => 'Reinigung erledigt', 'beschwerde' => 'Neue Beschwerde', 'rechnung' => 'Rechnung versendet'];
const PLATZHALTER = '{objekt}, {kunde}, {datum}, {uhrzeit}, {neuer_termin}, {betrieb}, {rechnungsnummer}, {zeitraum}';

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    $aktion = $_POST['aktion'] ?? '';

    // --- Betrieb ---
    if ($aktion === 'betrieb' && $istInhaber) {
        $daten = [
            'name'    => $pr->text('name', 150, true, 'Firmenname'),
            'uid_nummer' => $pr->text('uid_nummer', 20),
            'adresse' => $pr->text('adresse', 150),
            'plz'     => $pr->text('plz', 10),
            'ort'     => $pr->text('ort', 80),
            'telefon' => $pr->text('telefon', 40),
            'email'   => $pr->email('email'),
            'farbe_primaer' => farbe($pr, 'farbe_primaer'),
        ];
        // Logo
        if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
            $pfad = logoSpeichern($_FILES['logo'], (int) $betrieb['id'], $pr);
            if ($pfad !== null) {
                $daten['logo_pfad'] = $pfad;
            }
        }
        if (!empty($_POST['logo_entfernen'])) {
            $daten['logo_pfad'] = null;
        }
        if ($pr->ok()) {
            $db->raw('UPDATE betriebe SET name = ?, uid_nummer = ?, adresse = ?, plz = ?, ort = ?, telefon = ?, email = ?, farbe_primaer = ?' . (array_key_exists('logo_pfad', $daten) ? ', logo_pfad = ?' : '') . ' WHERE id = ?',
                array_merge([$daten['name'], $daten['uid_nummer'], $daten['adresse'], $daten['plz'], $daten['ort'], $daten['telefon'], $daten['email'], $daten['farbe_primaer']],
                    array_key_exists('logo_pfad', $daten) ? [$daten['logo_pfad']] : [], [(int) $betrieb['id']]));
            // Foto-Regel fuer die App
            $regel = in_array($_POST['foto_regel'] ?? '', ['frei', 'hinweis', 'pflicht'], true) ? $_POST['foto_regel'] : 'hinweis';
            $db->raw("INSERT INTO einstellungen (betrieb_id, schluessel, wert, typ) VALUES (?, 'foto_regel', ?, 'text') ON DUPLICATE KEY UPDATE wert = VALUES(wert)", [(int) $betrieb['id'], $regel]);
            flash('ok', 'Betriebsdaten gespeichert.');
            weiter('/admin/?s=einstellungen&t=betrieb');
        }
        flash('fehler', implode(' ', $pr->fehler));
        weiter('/admin/?s=einstellungen&t=betrieb');
    }

    // --- Leistungskatalog ---
    if ($aktion === 'leistung') {
        $lid = (int) ($_POST['leistung_id'] ?? 0);
        $daten = [
            'name'                   => $pr->text('name', 100, true, 'Bezeichnung'),
            'kategorie'              => $pr->auswahl('kategorie', array_keys(KATEGORIEN), true),
            'standard_stundensatz'   => $pr->decimal('standard_stundensatz', false, 0),
            'standard_dauer_minuten' => $pr->int('standard_dauer_minuten', false, 5, 1440),
            'qualifikation_id'       => $pr->int('qualifikation_id', false, 1),
            'sortierung'             => $pr->int('sortierung', false, 0, 999) ?? 0,
            'aktiv'                  => $pr->haken('aktiv'),
        ];
        if ($pr->ok()) {
            if ($lid > 0) { $db->update('leistungsarten', $lid, $daten); } else { $db->insert('leistungsarten', $daten); }
            flash('ok', 'Leistungsart gespeichert.');
        } else {
            flash('fehler', implode(' ', $pr->fehler));
        }
        weiter('/admin/?s=einstellungen&t=leistungen');
    }

    // --- Qualifikationen ---
    if ($aktion === 'qualifikation') {
        $qid = (int) ($_POST['qualifikation_id'] ?? 0);
        $daten = ['name' => $pr->text('name', 80, true, 'Bezeichnung'), 'beschreibung' => $pr->text('beschreibung', 255), 'sortierung' => $pr->int('sortierung', false, 0, 999) ?? 0, 'aktiv' => $pr->haken('aktiv')];
        if ($pr->ok()) { if ($qid > 0) { $db->update('qualifikationen', $qid, $daten); } else { $db->insert('qualifikationen', $daten); } flash('ok', 'Qualifikation gespeichert.'); } else { flash('fehler', implode(' ', $pr->fehler)); }
        weiter('/admin/?s=einstellungen&t=leistungen#quali');
    }

    // --- Vorlagen ---
    if ($aktion === 'vorlage') {
        $rid = (int) ($_POST['regel_id'] ?? 0);
        if ($db->find('benachrichtigungs_regeln', $rid) !== null) {
            $db->update('benachrichtigungs_regeln', $rid, [
                'kunde_informieren'  => $pr->haken('kunde_informieren'),
                'office_informieren' => $pr->haken('office_informieren'),
                'vorlage_betreff'    => $pr->text('vorlage_betreff', 200, true, 'Betreff'),
                'vorlage_text'       => $pr->text('vorlage_text', 4000, true, 'Text'),
                'aktiv'              => $pr->haken('aktiv'),
            ]);
            flash($pr->ok() ? 'ok' : 'fehler', $pr->ok() ? 'Vorlage gespeichert.' : implode(' ', $pr->fehler));
        }
        weiter('/admin/?s=einstellungen&t=vorlagen');
    }

    // --- Website ---
    if ($aktion === 'website' && $istInhaber) {
        $db->raw('INSERT INTO website_config (betrieb_id) VALUES (?) ON DUPLICATE KEY UPDATE betrieb_id = betrieb_id', [$db->tenant()]);
        $domain = mb_strtolower(trim((string) ($_POST['domain'] ?? ''))); $domain = preg_replace('#^https?://#', '', $domain); $domain = rtrim($domain, '/');
        if ($domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) !== 1) { flash('fehler', 'Domain ungültig (nur Hostname, z. B. reinigung-huber.at).'); weiter('/admin/?s=einstellungen&t=website'); }
        if ($domain !== '' && $db->rawOne('SELECT betrieb_id FROM website_config WHERE domain = ? AND betrieb_id <> ?', [$domain, $db->tenant()]) !== null) { flash('fehler', 'Diese Domain ist bereits einem anderen Betrieb zugeordnet.'); weiter('/admin/?s=einstellungen&t=website'); }
        $texte = [];
        foreach (array_keys(App\Website::standardTexte($betrieb)) as $k) { if (isset($_POST['t_' . $k])) { $texte[$k] = mb_substr(trim((string) $_POST['t_' . $k]), 0, $k === 'faq' || $k === 'ueber_text' ? 6000 : 600); } }
        foreach ($db->select('leistungsarten', 'aktiv = 1') as $l) { if (isset($_POST['lt_' . $l['id']]) && trim((string) $_POST['lt_' . $l['id']]) !== '') { $texte['leistung_' . $l['id'] . '_text'] = mb_substr(trim((string) $_POST['lt_' . $l['id']]), 0, 600); } }
        $sichtbar = array_map('intval', (array) ($_POST['sichtbar'] ?? []));
        $ga = trim((string) ($_POST['google_analytics_id'] ?? '')); if ($ga !== '' && preg_match('/^G-[A-Z0-9]+$/', $ga) !== 1) { $ga = ''; }
        $db->raw('UPDATE website_config SET aktiv = ?, domain = ?, seo_titel = ?, seo_beschreibung = ?, texte = ?, leistungen_sichtbar = ?, oeffnungszeiten = ?, google_analytics_id = ?, farbe_primaer = ? WHERE betrieb_id = ?',
            [$pr->haken('aktiv'), $domain ?: null, $pr->text('seo_titel', 70), $pr->text('seo_beschreibung', 160), json_encode($texte, JSON_UNESCAPED_UNICODE), json_encode($sichtbar), $pr->text('oeffnungszeiten', 255), $ga ?: null, farbe($pr, 'farbe_primaer'), $db->tenant()]);
        flash('ok', 'Website gespeichert.'); weiter('/admin/?s=einstellungen&t=website');
    }
    if ($aktion === 'webfoto' && $istInhaber) {
        $bereich = in_array($_POST['bereich'] ?? '', ['hero', 'leistungen', 'team'], true) ? $_POST['bereich'] : 'hero';
        if (!empty($_FILES['foto']['tmp_name']) && is_uploaded_file($_FILES['foto']['tmp_name'])) {
            $pfad = webfotoSpeichern($_FILES['foto'], (int) $betrieb['id'], $pr);
            if ($pfad !== null) {
                $n = (int) ($db->rawOne('SELECT IFNULL(MAX(sortierung),0)+1 s FROM website_fotos WHERE betrieb_id = ? AND bereich = ?', [$db->tenant(), $bereich])['s'] ?? 1);
                $db->insert('website_fotos', ['bereich' => $bereich, 'pfad' => $pfad, 'alt_text' => $pr->text('alt_text', 150), 'sortierung' => $n]);
                flash('ok', 'Foto hochgeladen.');
            } else { flash('fehler', implode(' ', $pr->fehler)); }
        }
        weiter('/admin/?s=einstellungen&t=website#fotos');
    }
    if ($aktion === 'webfoto_loeschen' && $istInhaber) {
        $f = $db->find('website_fotos', (int) ($_POST['foto_id'] ?? 0));
        if ($f !== null) { @unlink(__DIR__ . '/../../uploads/' . $f['pfad']); $db->delete('website_fotos', (int) $f['id']); flash('ok', 'Foto entfernt.'); }
        weiter('/admin/?s=einstellungen&t=website#fotos');
    }

    // --- Buerobenutzer (nur Inhaber) ---
    if ($istInhaber && $aktion === 'benutzer_neu') {
        $login = $pr->text('benutzername', 80, true, 'Benutzername');
        if ($login !== null && preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $login) !== 1) { $pr->fehler['benutzername'] = 'Nur Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich, 3–40 Zeichen.'; }
        if ($login !== null && $db->rawOne('SELECT id FROM benutzer WHERE benutzername = ? LIMIT 1', [$login]) !== null) { $pr->fehler['benutzername'] = 'Bereits vergeben.'; }
        $pw = (string) ($_POST['passwort'] ?? '');
        if (mb_strlen($pw) < 10) { $pr->fehler['passwort'] = 'Mindestens 10 Zeichen.'; }
        $mail = $pr->email('email');
        if ($pr->ok()) {
            $db->insert('benutzer', ['rolle' => 'office', 'benutzername' => $login, 'email' => $mail, 'passwort_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
            flash('ok', 'Bürobenutzer angelegt.');
        } else {
            flash('fehler', implode(' ', $pr->fehler));
        }
        weiter('/admin/?s=einstellungen&t=benutzer');
    }
    if ($istInhaber && in_array($aktion, ['benutzer_aktiv', 'benutzer_passwort'], true)) {
        $bid = (int) ($_POST['benutzer_id'] ?? 0);
        $b = $db->selectOne('benutzer', "id = ? AND rolle = 'office'", [$bid]);
        if ($b !== null) {
            if ($aktion === 'benutzer_aktiv') {
                $db->update('benutzer', $bid, ['aktiv' => (int) $b['aktiv'] === 1 ? 0 : 1]);
                flash('ok', (int) $b['aktiv'] === 1 ? 'Zugang deaktiviert.' : 'Zugang aktiviert.');
            } else {
                $pw = (string) ($_POST['passwort'] ?? '');
                if (mb_strlen($pw) < 10) { flash('fehler', 'Mindestens 10 Zeichen.'); }
                else { $db->update('benutzer', $bid, ['passwort_hash' => password_hash($pw, PASSWORD_DEFAULT), 'fehlversuche' => 0, 'gesperrt_bis' => null]); flash('ok', 'Passwort gesetzt.'); }
            }
        }
        weiter('/admin/?s=einstellungen&t=benutzer');
    }
}

// =====================================================================
// DATENEXPORT (nur Inhaber)
// =====================================================================
if ($t === 'export' && $istInhaber && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    try {
        $pfad = App\Export::zip();
        $db->raw('INSERT INTO audit_log (betrieb_id, benutzer_id, aktion, tabelle, beschreibung, ip_adresse) VALUES (?, ?, ?, ?, ?, ?)', [$db->tenant(), (int) $user['id'], 'export', 'alle', 'Vollständiger Datenexport', Auth::clientIp()]);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="Datenexport-' . preg_replace('/[^A-Za-z0-9]+/', '-', $betrieb['name']) . '-' . date('Y-m-d') . '.zip"'); header('Content-Length: ' . (string) filesize($pfad));
        readfile($pfad); @unlink($pfad); exit;
    } catch (\Throwable $e) { flash('fehler', $e->getMessage()); weiter('/admin/?s=einstellungen&t=export'); }
}

// =====================================================================
// FORMULARE als PDF
// =====================================================================
if ($t === 'pdf_kundenhinweis') {
    pdfAusgeben(kundenhinweisHtml($betrieb), 'Hinweis-Fotodokumentation-' . $betrieb['name']);
}
if ($t === 'pdf_privathandy') {
    pdfAusgeben(privathandyHtml($betrieb), 'Vereinbarung-App-Nutzung-' . $betrieb['name']);
}

$betrieb = $db->rawOne('SELECT * FROM betriebe WHERE id = ?', [$db->tenant()]);
$tabs = ['betrieb' => 'Betrieb', 'leistungen' => 'Leistungskatalog', 'vorlagen' => 'Benachrichtigungen', 'formulare' => 'Formulare', 'vertraege' => 'Verträge'];
if ($istInhaber) { $tabs['website'] = 'Website'; $tabs['benutzer'] = 'Bürobenutzer'; $tabs['export'] = 'Datenexport'; }
if (!isset($tabs[$t])) { $t = 'betrieb'; }
?>
<h1>Einstellungen</h1>
<nav class="tabs">
  <?php foreach ($tabs as $k => $v): ?><a href="/admin/?s=einstellungen&amp;t=<?= $k ?>" class="<?= $t === $k ? 'aktiv' : '' ?>"><?= e($v) ?></a><?php endforeach; ?>
</nav>

<?php if ($t === 'betrieb'): ?>
  <form method="post" class="formular" enctype="multipart/form-data">
    <input type="hidden" name="aktion" value="betrieb"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <?php if (!$istInhaber): ?><div class="meldung fehler">Betriebsdaten kann nur der Inhaber ändern.</div><?php endif; ?>
    <fieldset <?= $istInhaber ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
    <h2 class="erste">Firma</h2>
    <div class="g"><label for="na">Firmenname</label><input id="na" name="name" value="<?= e($betrieb['name']) ?>" required maxlength="150"></div>
    <div class="zwei">
      <div class="g"><label for="uid">UID-Nummer</label><input id="uid" name="uid_nummer" value="<?= e($betrieb['uid_nummer'] ?? '') ?>" maxlength="20"></div>
      <div class="g"><label for="tel">Telefon</label><input id="tel" name="telefon" type="tel" value="<?= e($betrieb['telefon'] ?? '') ?>" maxlength="40"></div>
    </div>
    <div class="g"><label for="ad">Adresse</label><input id="ad" name="adresse" value="<?= e($betrieb['adresse'] ?? '') ?>" maxlength="150"></div>
    <div class="drei">
      <div class="g"><label for="plz">PLZ</label><input id="plz" name="plz" value="<?= e($betrieb['plz'] ?? '') ?>" maxlength="10"></div>
      <div class="g"><label for="ort">Ort</label><input id="ort" name="ort" value="<?= e($betrieb['ort'] ?? '') ?>" maxlength="80"></div>
      <div class="g"><label for="em">E-Mail</label><input id="em" name="email" type="email" value="<?= e($betrieb['email'] ?? '') ?>" maxlength="150"><div class="tipp">Antwortadresse für Kundenbenachrichtigungen</div></div>
    </div>
    <h2>Erscheinungsbild</h2>
    <div class="zwei">
      <div class="g"><label for="lo">Logo</label><input id="lo" name="logo" type="file" accept="image/png,image/jpeg,image/webp"><div class="tipp">PNG, JPG oder WebP, höchstens 2 MB. Für Rechnungen, Nachweise und Website.</div>
        <?php if ($betrieb['logo_pfad']): ?><div style="margin-top:10px"><img src="/uploads/<?= e($betrieb['logo_pfad']) ?>" alt="Logo" style="max-height:60px;max-width:220px;border:1px solid var(--linie);border-radius:6px;padding:4px;background:#fff"><label class="tag-haken" style="margin-top:8px"><input type="checkbox" name="logo_entfernen" value="1"> Logo entfernen</label></div><?php endif; ?></div>
      <div class="g"><label for="fa">Hausfarbe</label><input id="fa" name="farbe_primaer" type="color" value="<?= e($betrieb['farbe_primaer'] ?? '#0B5D4A') ?>" style="height:44px;padding:4px;width:80px"><div class="tipp">Für Nachweise und Website</div></div>
      <?php $fotoRegel = $db->selectOne('einstellungen', "schluessel = 'foto_regel'")['wert'] ?? 'hinweis'; ?>
      <div class="g"><label for="fr">Fotos in der Mitarbeiter-App</label><select id="fr" name="foto_regel"><option value="frei" <?= $fotoRegel === 'frei' ? 'selected' : '' ?>>Freiwillig</option><option value="hinweis" <?= $fotoRegel === 'hinweis' ? 'selected' : '' ?>>Vorher- und Nachher-Foto empfohlen — Hinweis beim Ausstempeln</option><option value="pflicht" <?= $fotoRegel === 'pflicht' ? 'selected' : '' ?>>Nachher-Foto Pflicht — Ausstempeln erst mit mindestens einem Nachher-Foto</option></select><div class="tipp">Vorher-Fotos beim Ankommen, Nachher-Fotos nach der Reinigung. Der Kunde sieht beide getrennt im Portal.</div></div>
    </div>
    <button type="submit">Speichern</button>
    </fieldset>
  </form>

<?php elseif ($t === 'leistungen'):
    $arten = $db->select('leistungsarten', '', [], 'ORDER BY aktiv DESC, sortierung, name');
    $bearb = null; if (($lid = (int) ($_GET['l'] ?? 0)) > 0) { $bearb = $db->find('leistungsarten', $lid); }
?>
  <div class="zweispalt">
    <section>
      <p class="unter">Der Katalog steuert, was an Objekten eingetragen werden kann. Standardpreise werden vorgeschlagen und je Objekt überschreibbar.</p>
      <table>
        <thead><tr><th>Leistung</th><th>Kategorie</th><th>Qualifikation</th><th class="r">€ / Std</th><th class="r">Dauer</th><th></th></tr></thead>
        <tbody><?php foreach ($arten as $a): ?>
          <tr class="<?= (int) $a['aktiv'] === 0 ? 'aus' : '' ?>"><td><b><?= e($a['name']) ?></b></td><td><?= e(KATEGORIEN[$a['kategorie']] ?? $a['kategorie']) ?></td><td class="klein"><?= !empty($a['qualifikation_id']) ? e($db->find('qualifikationen', (int) $a['qualifikation_id'])['name'] ?? '') : '—' ?></td><td class="r"><?= geld($a['standard_stundensatz']) ?></td><td class="r"><?= $a['standard_dauer_minuten'] ? (int) $a['standard_dauer_minuten'] . ' Min' : '—' ?></td><td class="r"><a class="knopf mini zweit" href="/admin/?s=einstellungen&amp;t=leistungen&amp;l=<?= (int) $a['id'] ?>">Ändern</a></td></tr>
        <?php endforeach; ?></tbody>
      </table>
    </section>
    <section>
      <h2 style="margin-top:0"><?= $bearb ? 'Leistungsart ändern' : 'Leistungsart hinzufügen' ?></h2>
      <form method="post" class="formular">
        <input type="hidden" name="aktion" value="leistung"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="leistung_id" value="<?= (int) ($bearb['id'] ?? 0) ?>">
        <div class="g"><label for="ln">Bezeichnung</label><input id="ln" name="name" value="<?= e($bearb['name'] ?? '') ?>" required maxlength="100"></div>
        <div class="g"><label for="lk">Kategorie</label><select id="lk" name="kategorie"><?php foreach (KATEGORIEN as $k => $v): ?><option value="<?= $k ?>" <?= ($bearb['kategorie'] ?? 'unterhalt') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
        <div class="drei">
          <div class="g"><label for="ls">Stundensatz</label><input id="ls" name="standard_stundensatz" inputmode="decimal" value="<?= e($bearb && $bearb['standard_stundensatz'] !== null ? number_format((float) $bearb['standard_stundensatz'], 2, ',', '') : '') ?>"></div>
          <div class="g"><label for="ld">Dauer Min</label><input id="ld" name="standard_dauer_minuten" type="number" min="5" max="1440" value="<?= e((string) ($bearb['standard_dauer_minuten'] ?? '')) ?>"></div>
          <div class="g"><label for="lso">Reihung</label><input id="lso" name="sortierung" type="number" min="0" max="999" value="<?= e((string) ($bearb['sortierung'] ?? count($arten) + 1)) ?>"></div>
        </div>
        <?php $qualis = $db->select('qualifikationen', 'aktiv = 1', [], 'ORDER BY sortierung'); ?>
        <div class="g"><label for="lq">Benötigte Qualifikation</label><select id="lq" name="qualifikation_id"><option value="">— keine —</option><?php foreach ($qualis as $qq): ?><option value="<?= (int) $qq['id'] ?>" <?= (int) ($bearb['qualifikation_id'] ?? 0) === (int) $qq['id'] ? 'selected' : '' ?>><?= e($qq['name']) ?></option><?php endforeach; ?></select><div class="tipp">Nur Mitarbeiter mit dieser Qualifikation werden im Dienstplan vorgeschlagen.</div></div>
        <label class="tag-haken" style="margin-bottom:14px"><input type="checkbox" name="aktiv" value="1" <?= (int) ($bearb['aktiv'] ?? 1) === 1 ? 'checked' : '' ?>> Aktiv</label>
        <div class="zeile-knoepfe"><button type="submit">Speichern</button><?php if ($bearb): ?><a class="knopf zweit" href="/admin/?s=einstellungen&amp;t=leistungen">Abbrechen</a><?php endif; ?></div>
      </form>
    </section>
  </div>
  <?php $alleQ = $db->select('qualifikationen', '', [], 'ORDER BY aktiv DESC, sortierung'); $bq = null; if (($qid = (int) ($_GET['q'] ?? 0)) > 0) { $bq = $db->find('qualifikationen', $qid); } $anzahlJeQ = []; foreach ($db->rawAll('SELECT qualifikation_id, COUNT(*) n FROM mitarbeiter_qualifikationen WHERE betrieb_id = ? GROUP BY qualifikation_id', [$db->tenant()]) as $r) { $anzahlJeQ[(int) $r['qualifikation_id']] = (int) $r['n']; } ?>
  <h2 id="quali">Qualifikationen</h2>
  <div class="zweispalt">
    <section><p class="unter">Was eine Kraft können muss. Wird beim Mitarbeiter angehakt und bei der Leistungsart als Voraussetzung hinterlegt.</p>
      <table><thead><tr><th>Qualifikation</th><th>Beschreibung</th><th class="r">Mitarbeiter</th><th></th></tr></thead><tbody>
        <?php foreach ($alleQ as $qq): ?><tr class="<?= (int) $qq['aktiv'] === 0 ? 'aus' : '' ?>"><td><b><?= e($qq['name']) ?></b></td><td class="klein"><?= e($qq['beschreibung'] ?? '') ?></td><td class="r"><?= $anzahlJeQ[(int) $qq['id']] ?? 0 ?></td><td class="r"><a class="knopf mini zweit" href="/admin/?s=einstellungen&amp;t=leistungen&amp;q=<?= (int) $qq['id'] ?>#quali">Ändern</a></td></tr><?php endforeach; ?>
      </tbody></table></section>
    <section><form method="post" class="formular"><input type="hidden" name="aktion" value="qualifikation"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="qualifikation_id" value="<?= (int) ($bq['id'] ?? 0) ?>">
      <h2 class="erste"><?= $bq ? 'Qualifikation ändern' : 'Qualifikation hinzufügen' ?></h2>
      <div class="g"><label for="qn">Bezeichnung</label><input id="qn" name="name" required maxlength="80" value="<?= e($bq['name'] ?? '') ?>" placeholder="z. B. Bügeln"></div>
      <div class="g"><label for="qb">Beschreibung</label><input id="qb" name="beschreibung" maxlength="255" value="<?= e($bq['beschreibung'] ?? '') ?>"></div>
      <div class="zwei"><div class="g"><label for="qs">Reihung</label><input id="qs" name="sortierung" type="number" min="0" value="<?= e((string) ($bq['sortierung'] ?? count($alleQ) + 1)) ?>"></div><div class="g"><label class="tag-haken" style="margin-top:28px"><input type="checkbox" name="aktiv" value="1" <?= (int) ($bq['aktiv'] ?? 1) === 1 ? 'checked' : '' ?>> Aktiv</label></div></div>
      <div class="zeile-knoepfe"><button type="submit">Speichern</button><?php if ($bq): ?><a class="knopf zweit" href="/admin/?s=einstellungen&amp;t=leistungen#quali">Abbrechen</a><?php endif; ?></div></form></section>
  </div>

<?php elseif ($t === 'vorlagen'):
    $regeln = $db->select('benachrichtigungs_regeln', '', [], 'ORDER BY FIELD(ereignis,"planaenderung","ausfall","ersatz","erledigt","beschwerde","rechnung")');
?>
  <p class="unter">Was der Kunde erfährt, wenn sich etwas ändert. Abwesenheitsgründe werden nie übermittelt. Platzhalter: <code><?= e(PLATZHALTER) ?></code></p>
  <?php foreach ($regeln as $r): ?>
  <form method="post" class="formular" style="margin-bottom:16px;max-width:820px">
    <input type="hidden" name="aktion" value="vorlage"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="regel_id" value="<?= (int) $r['id'] ?>">
    <div class="kopfzeile" style="margin-bottom:12px"><h3 style="margin:0"><?= e(EREIGNISSE[$r['ereignis']] ?? $r['ereignis']) ?></h3>
      <div class="zeile-knoepfe">
        <label class="tag-haken"><input type="checkbox" name="kunde_informieren" value="1" <?= (int) $r['kunde_informieren'] === 1 ? 'checked' : '' ?>> Kunde per E-Mail</label>
        <label class="tag-haken"><input type="checkbox" name="office_informieren" value="1" <?= (int) $r['office_informieren'] === 1 ? 'checked' : '' ?>> Büro</label>
        <label class="tag-haken"><input type="checkbox" name="aktiv" value="1" <?= (int) $r['aktiv'] === 1 ? 'checked' : '' ?>> Aktiv</label>
      </div></div>
    <div class="g"><label>Betreff</label><input name="vorlage_betreff" value="<?= e($r['vorlage_betreff'] ?? '') ?>" maxlength="200" required></div>
    <div class="g"><label>Text</label><textarea name="vorlage_text" rows="5" required><?= e($r['vorlage_text'] ?? '') ?></textarea></div>
    <button type="submit" class="mini">Speichern</button>
  </form>
  <?php endforeach; ?>

<?php elseif ($t === 'formulare'): ?>
  <p class="unter">Muster, die der Betrieb im Alltag braucht. Sie werden mit den Betriebsdaten befüllt und sind vor Verwendung auf den Einzelfall zu prüfen.</p>
  <div class="liste" style="max-width:820px">
    <div class="eintrag eintrag-2"><div><div class="titel">Hinweis an Kunden zur Fotodokumentation</div><div class="sub">Information nach Art. 13 DSGVO an den Kunden des Betriebs: Fotos der gereinigten Bereiche zum Leistungsnachweis, Rechtsgrundlage, Speicherdauer drei Monate. Dem Reinigungsvertrag beilegen.</div></div><a class="knopf mini zweit" href="/admin/?s=einstellungen&amp;t=pdf_kundenhinweis" target="_blank">PDF</a></div>
    <div class="eintrag eintrag-2"><div><div class="titel">Vereinbarung zur Nutzung der Mitarbeiter-App</div><div class="sub">Nutzung des privaten Handys, Zeiterfassung per QR ohne Ortung, Fotos nur von Räumen, Datensparsamkeit, Rechte des Mitarbeiters. Vom Mitarbeiter zu unterschreiben.</div></div><a class="knopf mini zweit" href="/admin/?s=einstellungen&amp;t=pdf_privathandy" target="_blank">PDF</a></div>
    <div class="eintrag eintrag-2"><div><div class="titel">Datenschutzinformation für Mitarbeiter</div><div class="sub">Je Mitarbeiter personalisiert — in der Mitarbeiterakte unter „Datenschutzinformation".</div></div><a class="knopf mini zweit" href="/admin/?s=mitarbeiter">Zu den Mitarbeitern</a></div>
    <div class="eintrag eintrag-2"><div><div class="titel">QR-Schild je Objekt</div><div class="sub">Zum Aufhängen am Objekt — in der Objektakte.</div></div><a class="knopf mini zweit" href="/admin/?s=objekte">Zu den Objekten</a></div>
  </div>

<?php elseif ($t === 'vertraege'):
    $zust = $db->rawAll('SELECT z.*, b.benutzername FROM betrieb_zustimmungen z JOIN benutzer b ON b.id = z.benutzer_id WHERE z.betrieb_id = ? ORDER BY z.zeitpunkt DESC', [$db->tenant()]);
?>
  <div class="zweispalt">
    <section>
      <details><summary>Vereinbarung zur Auftragsverarbeitung <span class="klein">Version <?= e(Consent::version('av_vertrag')) ?></span></summary><div class="dokument"><?= Consent::html('av_vertrag', (string) $betrieb['name']) ?></div></details>
      <details><summary>Allgemeine Geschäftsbedingungen <span class="klein">Version <?= e(Consent::version('agb')) ?></span></summary><div class="dokument"><?= Consent::html('agb', (string) $betrieb['name']) ?></div></details>
    </section>
    <section>
      <h2 style="margin-top:0">Zustimmungen</h2>
      <?php if ($zust === []): ?><p class="leer">Keine.</p><?php else: ?>
      <table><thead><tr><th>Dokument</th><th>Version</th><th>Wann</th><th>Wer</th></tr></thead><tbody>
        <?php foreach ($zust as $z): ?><tr><td><?= $z['typ'] === 'av_vertrag' ? 'AV-Vertrag' : 'AGB' ?></td><td><?= e($z['version']) ?></td><td class="nowrap"><?= e(date('d.m.Y H:i', strtotime($z['zeitpunkt']))) ?></td><td><?= e($z['benutzername']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </section>
  </div>

<?php elseif ($t === 'website' && $istInhaber):
    $wc = $db->rawOne('SELECT * FROM website_config WHERE betrieb_id = ?', [$db->tenant()]) ?? [];
    $wt = array_merge(App\Website::standardTexte($betrieb), json_decode((string) ($wc['texte'] ?? ''), true) ?: []);
    $sichtbar = json_decode((string) ($wc['leistungen_sichtbar'] ?? ''), true) ?: [];
    $arten = $db->select('leistungsarten', "aktiv = 1 AND kategorie <> 'regie'", [], 'ORDER BY sortierung');
    $wfotos = $db->select('website_fotos', '', [], 'ORDER BY bereich, sortierung');
    $vorschauUrl = '/index.php?betrieb=' . rawurlencode((string) $betrieb['slug']);
?>
  <div class="kopfzeile" style="margin-bottom:10px"><p class="unter" style="margin:0">Eigene Website des Betriebs im Design der Software: Startseite, Leistungen, Über uns, Kontakt, Impressum, Datenschutz. Kontaktanfragen landen unter Kunden → Anfragen.</p><a class="knopf zweit" href="<?= e($vorschauUrl) ?>" target="_blank">Vorschau öffnen</a></div>
  <form method="post" class="formular" style="max-width:860px"><input type="hidden" name="aktion" value="website"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <h2 class="erste">Freischaltung</h2>
    <label class="tag-haken" style="margin-bottom:12px"><input type="checkbox" name="aktiv" value="1" <?= (int) ($wc['aktiv'] ?? 0) === 1 ? 'checked' : '' ?>> Website ist öffentlich erreichbar</label>
    <div class="zwei"><div class="g"><label>Eigene Domain</label><input name="domain" value="<?= e($wc['domain'] ?? '') ?>" placeholder="reinigung-huber.at"><div class="tipp">Die Domain beim Registrar per A-Record auf diesen Server zeigen lassen; Einrichtung als Alias-Domain macht der Anbieter.</div></div>
      <div class="g"><label>Hausfarbe der Website</label><input name="farbe_primaer" type="color" value="<?= e($wc['farbe_primaer'] ?? $betrieb['farbe_primaer'] ?? '#0B5D4A') ?>" style="height:44px;padding:4px;width:80px"></div></div>
    <h2>Suchmaschinen</h2>
    <div class="g"><label>Seitentitel</label><input name="seo_titel" maxlength="70" value="<?= e($wc['seo_titel'] ?? '') ?>" placeholder="Büroreinigung Wien – <?= e($betrieb['name']) ?>"><div class="tipp">Höchstens 70 Zeichen; erscheint als Überschrift in Google.</div></div>
    <div class="g"><label>Beschreibung</label><input name="seo_beschreibung" maxlength="160" value="<?= e($wc['seo_beschreibung'] ?? '') ?>"><div class="tipp">Höchstens 160 Zeichen; der Text unter der Überschrift in Google.</div></div>
    <div class="zwei"><div class="g"><label>Erreichbarkeit</label><input name="oeffnungszeiten" maxlength="255" value="<?= e($wc['oeffnungszeiten'] ?? '') ?>" placeholder="werktags 7–17 Uhr"></div><div class="g"><label>Google Analytics (optional)</label><input name="google_analytics_id" value="<?= e($wc['google_analytics_id'] ?? '') ?>" placeholder="G-XXXXXXX"><div class="tipp">Wenn gesetzt, erscheint ein Zustimmungsbanner.</div></div></div>
    <h2>Startseite</h2>
    <div class="g"><label>Überschrift</label><input name="t_hero_titel" maxlength="120" value="<?= e($wt['hero_titel']) ?>"></div>
    <div class="g"><label>Einleitung</label><textarea name="t_hero_text" rows="2" maxlength="600"><?= e($wt['hero_text']) ?></textarea></div>
    <div class="drei"><?php for ($i = 1; $i <= 3; $i++): ?><div class="g"><label>Kennzahl <?= $i ?></label><input name="t_zahl_<?= $i ?>" maxlength="20" value="<?= e($wt["zahl_{$i}"]) ?>" placeholder="z. B. 64"><input name="t_zahl_<?= $i ?>_text" maxlength="60" value="<?= e($wt["zahl_{$i}_text"]) ?>" style="margin-top:6px" placeholder="Beschriftung"></div><?php endfor; ?></div>
    <div class="tipp" style="margin:-8px 0 14px">Leere Kennzahlen werden nicht angezeigt.</div>
    <div class="zwei"><div class="g"><label>Nachweis-Überschrift</label><input name="t_nachweis_titel" maxlength="120" value="<?= e($wt['nachweis_titel']) ?>"></div><div class="g"><label>Kontakt-Überschrift</label><input name="t_kontakt_titel" maxlength="120" value="<?= e($wt['kontakt_titel']) ?>"></div></div>
    <div class="g"><label>Nachweis-Text</label><textarea name="t_nachweis_text" rows="2" maxlength="600"><?= e($wt['nachweis_text']) ?></textarea></div>
    <h2>Leistungen</h2>
    <p class="tipp" style="margin-bottom:12px">Welche Leistungen erscheinen (keine Auswahl = alle) und mit welchem Text.</p>
    <?php foreach ($arten as $a): ?><div class="g"><label class="tag-haken" style="margin-bottom:6px"><input type="checkbox" name="sichtbar[]" value="<?= (int) $a['id'] ?>" <?= $sichtbar === [] || in_array((int) $a['id'], array_map('intval', $sichtbar), true) ? 'checked' : '' ?>> <?= e($a['name']) ?></label><textarea name="lt_<?= (int) $a['id'] ?>" rows="2" maxlength="600" placeholder="<?= e(App\Website::leistungText($a['kategorie'])) ?>"><?= e($wt['leistung_' . $a['id'] . '_text'] ?? '') ?></textarea></div><?php endforeach; ?>
    <div class="zwei"><div class="g"><label>Überschrift Leistungen</label><input name="t_leistungen_titel" maxlength="120" value="<?= e($wt['leistungen_titel']) ?>"></div><div class="g"><label>Text dazu</label><input name="t_leistungen_text" maxlength="300" value="<?= e($wt['leistungen_text']) ?>"></div></div>
    <h2>Über uns</h2>
    <div class="g"><label>Text</label><textarea name="t_ueber_text" rows="5" maxlength="6000" placeholder="Wer Sie sind, seit wann, was Sie anders machen."><?= e($wt['ueber_text']) ?></textarea></div>
    <div class="zwei"><div class="g"><label>Team-Überschrift</label><input name="t_team_titel" maxlength="120" value="<?= e($wt['team_titel']) ?>"></div><div class="g"><label>Einsatzgebiet (Bezirke, mit Komma)</label><input name="t_bezirke" maxlength="300" value="<?= e($wt['bezirke']) ?>" placeholder="1010, 1030, 1090, Klosterneuburg"></div></div>
    <h2>Häufige Fragen</h2>
    <div class="g"><textarea name="t_faq" rows="6" maxlength="6000" aria-label="Fragen"><?= e($wt['faq']) ?></textarea><div class="tipp">Eine Frage je Zeile: <code>Frage|Antwort</code></div></div>
    <h2>Impressum</h2>
    <p class="tipp" style="margin-bottom:12px">Firma, Adresse, Telefon, E-Mail und UID kommen aus den Betriebsdaten. Hier die übrigen Pflichtangaben nach § 5 ECG und § 14 UGB.</p>
    <div class="drei"><div class="g"><label>Rechtsform</label><input name="t_rechtsform" maxlength="40" value="<?= e($wt['rechtsform']) ?>" placeholder="e.U., GmbH, OG"></div><div class="g"><label>Inhaber / Geschäftsführung</label><input name="t_inhaber" maxlength="120" value="<?= e($wt['inhaber']) ?>"></div><div class="g"><label>Gewerbe</label><input name="t_gewerbe" maxlength="200" value="<?= e($wt['gewerbe']) ?>"></div></div>
    <div class="drei"><div class="g"><label>Firmenbuchnummer</label><input name="t_firmenbuch" maxlength="20" value="<?= e($wt['firmenbuch']) ?>" placeholder="FN 123456a"></div><div class="g"><label>Firmenbuchgericht</label><input name="t_firmenbuchgericht" maxlength="80" value="<?= e($wt['firmenbuchgericht']) ?>" placeholder="Handelsgericht Wien"></div><div class="g"><label>Gewerbebehörde</label><input name="t_behoerde" maxlength="120" value="<?= e($wt['behoerde']) ?>"></div></div>
    <div class="g"><label>Kammermitgliedschaft</label><input name="t_kammer" maxlength="200" value="<?= e($wt['kammer']) ?>"></div>
    <button type="submit">Website speichern</button>
  </form>

  <h2 id="fotos">Fotos</h2>
  <p class="unter">Bereiche: <b>Titelbild</b> (Querformat, groß unter der Einleitung), <b>Leistungen</b> (je ein Bild je Leistung, in Reihenfolge), <b>Team</b> (Porträts mit Namen als Bildtext).</p>
  <div class="zweispalt">
    <section>
      <?php if ($wfotos === []): ?><p class="leer">Noch keine Fotos.</p><?php else: ?><div class="webfoto-gitter"><?php foreach ($wfotos as $f): ?><figure><img src="/uploads/<?= e($f['pfad']) ?>" alt="<?= e($f['alt_text'] ?? '') ?>"><figcaption><?= e(['hero' => 'Titelbild', 'leistungen' => 'Leistungen', 'team' => 'Team'][$f['bereich']] ?? $f['bereich']) ?><?= $f['alt_text'] ? ' · ' . e($f['alt_text']) : '' ?><form method="post" class="inl"><input type="hidden" name="aktion" value="webfoto_loeschen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="foto_id" value="<?= (int) $f['id'] ?>"><button type="submit" class="mini leise" onclick="return confirm('Foto entfernen?')">✕</button></form></figcaption></figure><?php endforeach; ?></div><?php endif; ?>
    </section>
    <section>
      <form method="post" class="formular" enctype="multipart/form-data"><input type="hidden" name="aktion" value="webfoto"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <h2 class="erste">Foto hochladen</h2>
        <div class="g"><label>Bereich</label><select name="bereich"><option value="hero">Titelbild</option><option value="leistungen">Leistungen</option><option value="team">Team</option></select></div>
        <div class="g"><label>Datei</label><input name="foto" type="file" accept="image/jpeg,image/png,image/webp" required><div class="tipp">JPG, PNG oder WebP bis 10 MB; wird auf 1800 px verkleinert und neu kodiert.</div></div>
        <div class="g"><label>Bildtext</label><input name="alt_text" maxlength="150" placeholder="Beim Team: Name und Funktion"></div>
        <button type="submit" class="mini">Hochladen</button></form>
    </section>
  </div>

<?php elseif ($t === 'export' && $istInhaber): ?>
  <div class="formular" style="max-width:640px">
    <h2 class="erste">Alle Daten herunterladen</h2>
    <p style="margin-bottom:12px">Ein ZIP mit sämtlichen Daten des Betriebs als CSV (Excel-tauglich) und allen Rechnungs- und Angebots-PDFs. Für die eigene Sicherung, den Steuerberater oder bei Vertragsende.</p>
    <p class="klein" style="margin-bottom:16px">Enthält entschlüsselte SVNR, IBAN und Alarmcodes — die Datei ist entsprechend zu schützen. Jeder Export wird protokolliert.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit">ZIP erstellen und herunterladen</button></form>
  </div>

<?php elseif ($t === 'benutzer' && $istInhaber):
    $benutzer = $db->select('benutzer', "rolle IN ('inhaber','office')", [], 'ORDER BY rolle, benutzername');
?>
  <div class="zweispalt">
    <section>
      <table><thead><tr><th>Benutzer</th><th>Rolle</th><th>Letzter Login</th><th></th></tr></thead><tbody>
      <?php foreach ($benutzer as $b): ?>
        <tr class="<?= (int) $b['aktiv'] === 0 ? 'aus' : '' ?>">
          <td><b><?= e($b['benutzername']) ?></b><?= $b['email'] ? '<div class="klein">' . e($b['email']) . '</div>' : '' ?></td>
          <td><?= $b['rolle'] === 'inhaber' ? 'Inhaber' : 'Büro' ?></td>
          <td class="klein"><?= $b['letzter_login'] ? e(date('d.m.Y H:i', strtotime($b['letzter_login']))) : 'noch nie' ?></td>
          <td class="r nowrap"><?php if ($b['rolle'] === 'office'): ?>
            <form method="post" class="inl"><input type="hidden" name="aktion" value="benutzer_passwort"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="benutzer_id" value="<?= (int) $b['id'] ?>"><input name="passwort" type="password" placeholder="Neues Passwort" aria-label="Neues Passwort" style="width:160px;min-height:36px;padding:6px 10px" minlength="10"> <button type="submit" class="mini zweit">Setzen</button></form>
            <form method="post" class="inl"><input type="hidden" name="aktion" value="benutzer_aktiv"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="benutzer_id" value="<?= (int) $b['id'] ?>"><button type="submit" class="mini <?= (int) $b['aktiv'] === 1 ? 'leise' : '' ?>"><?= (int) $b['aktiv'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?></button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody></table>
    </section>
    <section>
      <h2 style="margin-top:0">Bürobenutzer anlegen</h2>
      <p class="klein" style="margin-bottom:12px">Bürobenutzer sehen alles außer Löhnen, SVNR, IBAN und Zugangscodes.</p>
      <form method="post" class="formular">
        <input type="hidden" name="aktion" value="benutzer_neu"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <div class="g"><label for="bn">Benutzername</label><input id="bn" name="benutzername" required pattern="[A-Za-z0-9._-]{3,40}" maxlength="40"></div>
        <div class="g"><label for="be">E-Mail</label><input id="be" name="email" type="email" maxlength="150"></div>
        <div class="g"><label for="bp">Startpasswort</label><input id="bp" name="passwort" type="password" required minlength="10" autocomplete="new-password"><div class="tipp">Mindestens 10 Zeichen; der Benutzer kann es unter „Mein Konto" ändern.</div></div>
        <button type="submit">Anlegen</button>
      </form>
    </section>
  </div>
<?php endif; ?>

<?php
// =====================================================================
// Hilfsfunktionen
// =====================================================================
function farbe(Pruefung $pr, string $key): ?string
{
    $v = strtoupper((string) $pr->text($key, 7));
    if ($v === '') { return null; }
    if (preg_match('/^#[0-9A-F]{6}$/', $v) !== 1) { $pr->fehler[$key] = 'Ungültige Farbe.'; return null; }
    return $v;
}

/** Logo pruefen, mit GD neu zeichnen (entfernt eingebettete Inhalte), als PNG speichern. */
function logoSpeichern(array $datei, int $betriebId, Pruefung $pr): ?string
{
    if ((int) $datei['error'] !== UPLOAD_ERR_OK) { $pr->fehler['logo'] = 'Upload fehlgeschlagen.'; return null; }
    if ((int) $datei['size'] > 2 * 1024 * 1024) { $pr->fehler['logo'] = 'Logo größer als 2 MB.'; return null; }
    $info = @getimagesize($datei['tmp_name']);
    if ($info === false || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) { $pr->fehler['logo'] = 'Nur PNG, JPG oder WebP.'; return null; }
    $bild = match ($info['mime']) {
        'image/png'  => @imagecreatefrompng($datei['tmp_name']),
        'image/jpeg' => @imagecreatefromjpeg($datei['tmp_name']),
        default      => @imagecreatefromwebp($datei['tmp_name']),
    };
    if ($bild === false) { $pr->fehler['logo'] = 'Bild konnte nicht gelesen werden.'; return null; }
    // Auf höchstens 600 px Breite verkleinern
    $w = imagesx($bild); $h = imagesy($bild);
    if ($w > 600) { $nh = (int) round($h * 600 / $w); $neu = imagecreatetruecolor(600, $nh); imagealphablending($neu, false); imagesavealpha($neu, true); imagecopyresampled($neu, $bild, 0, 0, 0, 0, 600, $nh, $w, $h); imagedestroy($bild); $bild = $neu; }
    else { imagealphablending($bild, false); imagesavealpha($bild, true); }
    $dir = __DIR__ . '/../../uploads/betriebe/' . $betriebId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) { $pr->fehler['logo'] = 'Ordner nicht anlegbar.'; return null; }
    $name = 'logo-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.png';
    if (!imagepng($bild, $dir . '/' . $name, 6)) { $pr->fehler['logo'] = 'Speichern fehlgeschlagen.'; return null; }
    imagedestroy($bild);
    // Altes Logo entfernen
    foreach (glob($dir . '/logo-*.png') ?: [] as $alt) { if (basename($alt) !== $name) { @unlink($alt); } }
    return 'betriebe/' . $betriebId . '/' . $name;
}

/** Website-Foto: pruefen, mit GD neu kodieren (EXIF weg), auf 1800 px, als JPEG. */
function webfotoSpeichern(array $datei, int $betriebId, Pruefung $pr): ?string
{
    if ((int) $datei['error'] !== UPLOAD_ERR_OK) { $pr->fehler['foto'] = 'Upload fehlgeschlagen.'; return null; }
    if ((int) $datei['size'] > 10 * 1024 * 1024) { $pr->fehler['foto'] = 'Größer als 10 MB.'; return null; }
    $info = @getimagesize($datei['tmp_name']);
    if ($info === false || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) { $pr->fehler['foto'] = 'Nur JPG, PNG oder WebP.'; return null; }
    $bild = match ($info['mime']) { 'image/png' => @imagecreatefrompng($datei['tmp_name']), 'image/jpeg' => @imagecreatefromjpeg($datei['tmp_name']), default => @imagecreatefromwebp($datei['tmp_name']) };
    if ($bild === false) { $pr->fehler['foto'] = 'Bild nicht lesbar.'; return null; }
    if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) { $o = (int) (@exif_read_data($datei['tmp_name'])['Orientation'] ?? 1); $deg = match ($o) { 3 => 180, 6 => -90, 8 => 90, default => 0 }; if ($deg) { $r = imagerotate($bild, $deg, 0); if ($r !== false) { imagedestroy($bild); $bild = $r; } } }
    $w = imagesx($bild); $h = imagesy($bild); $s = min(1.0, 1800 / max($w, $h));
    if ($s < 1) { $nw = (int) round($w * $s); $nh = (int) round($h * $s); $neu = imagecreatetruecolor($nw, $nh); imagecopyresampled($neu, $bild, 0, 0, 0, 0, $nw, $nh, $w, $h); imagedestroy($bild); $bild = $neu; }
    $dir = __DIR__ . '/../../uploads/betriebe/' . $betriebId . '/web';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) { $pr->fehler['foto'] = 'Ordner nicht anlegbar.'; return null; }
    $name = 'web-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.jpg';
    if (!imagejpeg($bild, $dir . '/' . $name, 84)) { $pr->fehler['foto'] = 'Speichern fehlgeschlagen.'; return null; }
    imagedestroy($bild);
    return 'betriebe/' . $betriebId . '/web/' . $name;
}

function kundenhinweisHtml(array $b): string
{
    $e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $firma = $e($b['name']); $adr = trim($e($b['adresse'] ?? '') . ', ' . $e($b['plz'] ?? '') . ' ' . $e($b['ort'] ?? ''), ', ');
    $kontakt = implode(' · ', array_filter([$e($b['telefon'] ?? ''), $e($b['email'] ?? '')]));
    return '<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8">' . pdfStil() . '</head><body>
      <div class="kopf">' . $firma . ' · ' . $adr . '</div>
      <h1>Information zur Fotodokumentation der Reinigungsleistung</h1>
      <p class="klein">Information nach Art. 13 DSGVO — Beilage zum Reinigungsvertrag</p>
      <h2>Wer ist verantwortlich?</h2>
      <p>' . $firma . ', ' . $adr . ($kontakt ? ', ' . $kontakt : '') . ' (im Folgenden „wir").</p>
      <h2>Was wird dokumentiert?</h2>
      <p>Unsere Mitarbeiter fotografieren die gereinigten Bereiche Ihrer Räumlichkeiten vor und nach der Reinigung. Zusätzlich wird der Beginn und das Ende jedes Einsatzes am Objekt erfasst. Aus beidem entsteht ein Leistungsnachweis, den Sie über ein Kundenportal einsehen können.</p>
      <p>Fotografiert werden ausschließlich Räume, Böden, Sanitäranlagen und Oberflächen. Unsere Mitarbeiter sind angewiesen, keine Personen, Bildschirme, Dokumente oder persönlichen Gegenstände aufzunehmen. Sollten dennoch personenbezogene Inhalte erfasst werden, werden diese Aufnahmen auf Ihren Hinweis unverzüglich gelöscht.</p>
      <h2>Zweck und Rechtsgrundlage</h2>
      <p>Zweck ist der Nachweis der vertragsgemäßen Leistungserbringung und die Qualitätssicherung. Rechtsgrundlage ist die Erfüllung des Reinigungsvertrags (Art. 6 Abs. 1 lit. b DSGVO) sowie unser berechtigtes Interesse an der Beweissicherung (Art. 6 Abs. 1 lit. f DSGVO).</p>
      <h2>Speicherdauer</h2>
      <p>Die Fotografien werden drei Monate nach dem Einsatz automatisch gelöscht. Zeitangaben zu den Einsätzen bewahren wir im Rahmen der gesetzlichen Aufbewahrungspflichten auf.</p>
      <h2>Wer erhält die Daten?</h2>
      <p>Die Daten werden in einer Verwaltungssoftware verarbeitet, die ein Dienstleister in unserem Auftrag auf Servern in Österreich betreibt (Auftragsverarbeitung nach Art. 28 DSGVO). Eine Weitergabe an Dritte findet nicht statt.</p>
      <h2>Ihre Rechte</h2>
      <p>Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung und Widerspruch aus Gründen, die sich aus Ihrer besonderen Situation ergeben. Wenden Sie sich dazu an die oben genannten Kontaktdaten. Sie haben außerdem das Recht auf Beschwerde bei der Datenschutzbehörde, Barichgasse 40–42, 1030 Wien.</p>
      <h2>Bitte informieren Sie Ihre Mitarbeiter</h2>
      <p>Da die Reinigung in Ihren Räumen stattfindet, ersuchen wir Sie, Ihre Mitarbeiter in geeigneter Form über die Fotodokumentation zu informieren, etwa durch Aushang oder Rundschreiben.</p>
      <div class="unterschrift"><div>Ort, Datum</div><div class="abstand"></div><div>Zur Kenntnis genommen (Kunde)</div></div>
    </body></html>';
}

function privathandyHtml(array $b): string
{
    $e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $firma = $e($b['name']); $adr = trim($e($b['adresse'] ?? '') . ', ' . $e($b['plz'] ?? '') . ' ' . $e($b['ort'] ?? ''), ', ');
    return '<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8">' . pdfStil() . '</head><body>
      <div class="kopf">' . $firma . ' · ' . $adr . '</div>
      <h1>Vereinbarung zur Nutzung der Mitarbeiter-App</h1>
      <p>zwischen ' . $firma . ' (Arbeitgeber) und</p>
      <p>Name: <span class="feld">&nbsp;</span> &nbsp; Personalnummer: <span class="feld" style="min-width:30mm">&nbsp;</span></p>
      <h2>1. Zweck der App</h2>
      <p>Der Arbeitgeber stellt eine Web-App bereit, über die Mitarbeiter ihren Einsatzplan einsehen, Beginn und Ende jedes Einsatzes am Objekt erfassen, Fotos der gereinigten Bereiche aufnehmen und Abwesenheiten beantragen. Die Zeiterfassung dient der Erfüllung der Aufzeichnungspflicht nach § 26 Arbeitszeitgesetz.</p>
      <h2>2. Nutzung des eigenen Mobiltelefons</h2>
      <p>Die App kann auf dem privaten Mobiltelefon des Mitarbeiters verwendet werden. Der Mitarbeiter erklärt sich damit einverstanden. Die Nutzung ist freiwillig; auf Wunsch stellt der Arbeitgeber ein Dienstgerät zur Verfügung oder ermöglicht die Zeiterfassung auf anderem Weg.</p>
      <p>Die App ist eine Website und benötigt keine Installation aus einem App-Store. Sie greift nicht auf Kontakte, Nachrichten, Fotos oder andere Inhalte des Telefons zu. Der Kamerazugriff wird ausschließlich beim Scannen des QR-Codes und beim Aufnehmen von Einsatzfotos angefragt und kann jederzeit in den Telefoneinstellungen entzogen werden.</p>
      <h2>3. Keine Ortung</h2>
      <p>Die App verwendet keine Standortdaten. Die Anwesenheit am Objekt wird ausschließlich durch das Scannen des am Objekt angebrachten QR-Codes festgestellt. Eine Ortung des Mitarbeiters, auch außerhalb der Arbeitszeit, findet nicht statt und ist technisch nicht vorgesehen.</p>
      <h2>4. Fotos</h2>
      <p>Fotos dienen dem Leistungsnachweis gegenüber dem Kunden. Aufzunehmen sind ausschließlich Räume, Böden, Sanitäranlagen und Oberflächen — keine Personen, Bildschirme, Dokumente oder persönlichen Gegenstände. Die Fotos werden auf dem Telefon nicht dauerhaft gespeichert, sondern beim Abgleich an den Server übertragen und dort nach drei Monaten automatisch gelöscht.</p>
      <h2>5. Welche Daten entstehen</h2>
      <ul>
        <li>Zeitpunkt von Check-in und Check-out je Einsatz sowie Art der Erfassung (QR, NFC, manueller Nachtrag durch das Büro)</li>
        <li>Eingereichte Abwesenheitsanträge und deren Bearbeitung</li>
        <li>Anmeldezeitpunkte in der App und die dabei verwendete Gerätebezeichnung</li>
      </ul>
      <p>Diese Daten werden ausschließlich für Dienstplanung, Arbeitszeitaufzeichnung, Lohnverrechnung und Leistungsnachweis verwendet. Eine Auswertung zur Leistungs- oder Verhaltenskontrolle über diesen Zweck hinaus findet nicht statt. Die Daten werden in einer Verwaltungssoftware verarbeitet, die ein Dienstleister im Auftrag des Arbeitgebers auf Servern in Österreich betreibt.</p>
      <h2>6. Kosten</h2>
      <p>Für die Nutzung entstehen dem Mitarbeiter keine Kosten außer dem üblichen Datenverbrauch des eigenen Mobilfunkvertrags, der durch die App gering ist (Fotos werden vor der Übertragung verkleinert). Der Arbeitgeber ersetzt dem Mitarbeiter pauschal <span class="feld" style="min-width:20mm">&nbsp;</span> € monatlich für die Gerätenutzung, sofern vereinbart.</p>
      <h2>7. Rechte des Mitarbeiters</h2>
      <p>Der Mitarbeiter kann seine erfassten Zeiten jederzeit in der App einsehen und erhält monatlich eine Aufstellung zur Unterschrift. Er hat das Recht auf Auskunft, Berichtigung und Löschung im gesetzlichen Rahmen. Diese Vereinbarung kann der Mitarbeiter mit einer Frist von einem Monat schriftlich widerrufen; der Arbeitgeber stellt dann eine andere Form der Zeiterfassung bereit.</p>
      <h2>8. Pflichten des Mitarbeiters</h2>
      <p>Die PIN ist geheim zu halten und nicht an andere weiterzugeben. Bei Verlust des Telefons oder Verdacht auf Missbrauch ist das Büro unverzüglich zu informieren, damit der Zugang gesperrt werden kann. Ein- und Ausstempeln für andere Personen ist unzulässig.</p>
      <div class="unterschrift"><div>Ort, Datum · Mitarbeiter</div><div class="abstand"></div><div>Ort, Datum · Arbeitgeber</div></div>
    </body></html>';
}
