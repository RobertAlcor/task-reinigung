<?php
/**
 * Kunden: Liste, Anlage, Bearbeitung, Detail mit Objekten und Notizen,
 * Website-Anfragen uebernehmen.
 * Variablen: $db, $user, $betrieb, $csrf, $config
 */
declare(strict_types=1);

use App\Auth;

$t   = $_GET['t'] ?? 'liste';        // liste | neu | detail | bearbeiten | anfragen
$id  = (int) ($_GET['id'] ?? 0);
$pr  = new Pruefung();
$satz = null;

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    $aktion = $_POST['aktion'] ?? '';

    if ($aktion === 'speichern') {
        $daten = [
            'firmenname'        => $pr->text('firmenname', 150, true, 'Firmenname'),
            'uid_nummer'        => $pr->text('uid_nummer', 20),
            'adresse'           => $pr->text('adresse', 150),
            'plz'               => $pr->text('plz', 10),
            'ort'               => $pr->text('ort', 80),
            'bezirk'            => $pr->text('bezirk', 40),
            'kontaktperson'     => $pr->text('kontaktperson', 120),
            'telefon'           => $pr->text('telefon', 40),
            'email'             => $pr->email('email'),
            'rechnungs_email'   => $pr->email('rechnungs_email'),
            'rechnungsadresse'  => $pr->text('rechnungsadresse', 255),
            'zahlungsziel_tage' => $pr->int('zahlungsziel_tage', false, 0, 90) ?? 14,
            'quelle'            => $pr->text('quelle', 50),
            'status'            => $pr->auswahl('status', array_keys(KUNDENSTATUS)) ?? 'interessent',
            'notizen'           => $pr->text('notizen', 4000),
        ];
        if ($pr->ok()) {
            if ($id > 0) {
                $db->update('kunden', $id, $daten);
                flash('ok', 'Kunde gespeichert.');
                weiter('/admin/?s=kunden&t=detail&id=' . $id);
            }
            $neu = $db->insert('kunden', $daten);
            // Kam der Kunde aus einer Website-Anfrage?
            $anfrageId = (int) ($_POST['anfrage_id'] ?? 0);
            if ($anfrageId > 0) {
                $db->update('anfragen', $anfrageId, ['kunde_id' => $neu, 'status' => 'kontaktiert']);
            }
            flash('ok', 'Kunde angelegt.');
            weiter('/admin/?s=kunden&t=detail&id=' . $neu);
        }
        $t = $id > 0 ? 'bearbeiten' : 'neu';
    }

    if ($aktion === 'notiz') {
        $text = $pr->text('notiz', 4000, true);
        if ($pr->ok() && $id > 0 && $db->find('kunden', $id) !== null) {
            $db->insert('kunden_notizen', ['kunde_id' => $id, 'benutzer_id' => (int) $user['id'], 'notiz' => $text]);
            flash('ok', 'Notiz gespeichert.');
        }
        weiter('/admin/?s=kunden&t=detail&id=' . $id);
    }

    if (in_array($aktion, ['portal_anlegen', 'portal_passwort', 'portal_aktiv'], true) && $id > 0 && $db->find('kunden', $id) !== null) {
        $konto = $db->selectOne('benutzer', "kunde_id = ? AND rolle = 'kunde'", [$id]);
        $kunde = $db->find('kunden', $id);
        if ($aktion === 'portal_anlegen' && $konto === null) {
            $login = mb_strtolower(trim((string) ($_POST['benutzername'] ?? '')));
            if ($login === '' || preg_match('/^[a-z0-9._@+-]{3,80}$/', $login) !== 1) { flash('fehler', 'Benutzername: 3–80 Zeichen, Buchstaben, Ziffern, Punkt, @, Bindestrich.'); weiter('/admin/?s=kunden&t=detail&id=' . $id); }
            if ($db->rawOne('SELECT id FROM benutzer WHERE benutzername = ? LIMIT 1', [$login]) !== null) { flash('fehler', 'Dieser Benutzername ist bereits vergeben.'); weiter('/admin/?s=kunden&t=detail&id=' . $id); }
            $pw = portalPasswort();
            $db->insert('benutzer', ['rolle' => 'kunde', 'benutzername' => $login, 'email' => $kunde['email'], 'passwort_hash' => password_hash($pw, PASSWORD_DEFAULT), 'kunde_id' => $id]);
            flash('ok', 'Portalzugang angelegt. Benutzername: ' . $login . ' — Startpasswort: ' . $pw . ' (wird nur einmal angezeigt)');
        } elseif ($aktion === 'portal_passwort' && $konto !== null) {
            $pw = portalPasswort();
            $db->update('benutzer', (int) $konto['id'], ['passwort_hash' => password_hash($pw, PASSWORD_DEFAULT), 'fehlversuche' => 0, 'gesperrt_bis' => null]);
            flash('ok', 'Neues Passwort: ' . $pw . ' (wird nur einmal angezeigt)');
        } elseif ($aktion === 'portal_aktiv' && $konto !== null) {
            $db->update('benutzer', (int) $konto['id'], ['aktiv' => (int) $konto['aktiv'] === 1 ? 0 : 1]);
            flash('ok', (int) $konto['aktiv'] === 1 ? 'Portalzugang deaktiviert.' : 'Portalzugang aktiviert.');
        }
        weiter('/admin/?s=kunden&t=detail&id=' . $id);
    }

    if ($aktion === 'anfrage_status') {
        $aid = (int) ($_POST['anfrage_id'] ?? 0);
        $st  = $pr->auswahl('status', ['neu', 'kontaktiert', 'erledigt', 'spam'], true);
        if ($pr->ok() && $aid > 0) {
            $db->update('anfragen', $aid, ['status' => $st]);
        }
        weiter('/admin/?s=kunden&t=anfragen');
    }
}

// Leistungsnachweis als PDF (Buero: mit vollen Namen)
if ($t === 'nachweis' && $id > 0) {
    $monat = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m', strtotime('first day of last month'));
    $html = App\Report::monatsnachweisHtml($id, $monat, true);
    if ($html === null) { flash('fehler', 'Kunde nicht gefunden.'); weiter('/admin/?s=kunden'); }
    pdfAusgeben($html, 'Leistungsnachweis-' . $monat);
}

// ---------------------------------------------------------------------
// Daten laden
// ---------------------------------------------------------------------
if (in_array($t, ['detail', 'bearbeiten'], true)) {
    $satz = $db->find('kunden', $id);
    if ($satz === null) {
        flash('fehler', 'Kunde nicht gefunden.');
        weiter('/admin/?s=kunden');
    }
}
// Neuanlage aus Anfrage: Felder vorbelegen
$anfrage = null;
if ($t === 'neu' && ($aid = (int) ($_GET['anfrage'] ?? 0)) > 0) {
    $anfrage = $db->find('anfragen', $aid);
    if ($anfrage !== null && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $satz = ['firmenname' => $anfrage['firma'] ?: $anfrage['name'], 'kontaktperson' => $anfrage['name'],
                 'email' => $anfrage['email'], 'telefon' => $anfrage['telefon'], 'quelle' => 'Website',
                 'notizen' => $anfrage['nachricht']];
    }
}

// =====================================================================
// LISTE
// =====================================================================
if ($t === 'liste'):
    $suche  = trim((string) ($_GET['q'] ?? ''));
    $filter = $_GET['f'] ?? 'aktiv';
    $where = []; $params = [];
    if ($suche !== '') { $where[] = '(firmenname LIKE ? OR kontaktperson LIKE ? OR ort LIKE ?)'; $params = ["%{$suche}%", "%{$suche}%", "%{$suche}%"]; }
    if (in_array($filter, ['aktiv', 'interessent', 'inaktiv'], true)) { $where[] = 'status = ?'; $params[] = $filter; }
    $kunden = $db->select('kunden', implode(' AND ', $where), $params, 'ORDER BY firmenname LIMIT 300');
    $objAnz = [];
    foreach ($db->rawAll('SELECT kunde_id, COUNT(*) n FROM objekte WHERE betrieb_id = ? AND aktiv = 1 GROUP BY kunde_id', [$db->tenant()]) as $r) {
        $objAnz[(int) $r['kunde_id']] = (int) $r['n'];
    }
    $neueAnfragen = $db->count('anfragen', "status = 'neu'");
?>
<div class="kopfzeile">
  <h1>Kunden</h1>
  <div class="zeile-knoepfe">
    <?php if ($neueAnfragen > 0): ?><a class="knopf zweit" href="/admin/?s=kunden&amp;t=anfragen"><?= $neueAnfragen ?> neue Anfrage<?= $neueAnfragen === 1 ? '' : 'n' ?></a><?php endif; ?>
    <a class="knopf" href="/admin/?s=kunden&amp;t=neu">Kunde anlegen</a>
  </div>
</div>
<form method="get" class="filter">
  <input type="hidden" name="s" value="kunden">
  <input type="search" name="q" value="<?= e($suche) ?>" placeholder="Firma, Ansprechperson, Ort" aria-label="Suche">
  <select name="f" aria-label="Status">
    <option value="aktiv" <?= $filter === 'aktiv' ? 'selected' : '' ?>>Aktive</option>
    <option value="interessent" <?= $filter === 'interessent' ? 'selected' : '' ?>>Interessenten</option>
    <option value="inaktiv" <?= $filter === 'inaktiv' ? 'selected' : '' ?>>Inaktive</option>
    <option value="alle" <?= $filter === 'alle' ? 'selected' : '' ?>>Alle</option>
  </select>
  <button type="submit" class="zweit">Suchen</button>
</form>
<?php if ($kunden === []): ?><p class="leer">Keine Kunden gefunden.</p><?php else: ?>
<table>
  <thead><tr><th>Firma</th><th>Ansprechperson</th><th>Ort</th><th class="r">Objekte</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($kunden as $k): ?>
    <tr class="<?= $k['status'] === 'inaktiv' ? 'aus' : '' ?>">
      <td><a href="/admin/?s=kunden&amp;t=detail&amp;id=<?= (int) $k['id'] ?>"><?= e($k['firmenname']) ?></a></td>
      <td><?= e($k['kontaktperson'] ?? '') ?><?= $k['telefon'] ? '<div class="klein">' . e($k['telefon']) . '</div>' : '' ?></td>
      <td><?= e(trim(($k['plz'] ?? '') . ' ' . ($k['ort'] ?? ''))) ?></td>
      <td class="r"><?= $objAnz[(int) $k['id']] ?? 0 ?></td>
      <td><?= statusMarkeKunde($k['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php
// =====================================================================
// ANFRAGEN
// =====================================================================
elseif ($t === 'anfragen'):
    $anfragen = $db->select('anfragen', "status <> 'spam'", [], 'ORDER BY status = \'neu\' DESC, erstellt_am DESC LIMIT 100');
?>
<div class="kopfzeile"><h1>Anfragen von der Website</h1><a class="knopf zweit" href="/admin/?s=kunden">Zu den Kunden</a></div>
<?php if ($anfragen === []): ?><p class="leer">Keine Anfragen.</p><?php else: ?>
<div class="liste">
<?php foreach ($anfragen as $a): ?>
  <div class="eintrag eintrag-anfrage">
    <div class="zeit"><?= e(date('d.m.', strtotime($a['erstellt_am']))) ?></div>
    <div>
      <div class="titel"><?= e($a['firma'] ?: $a['name']) ?> <?= $a['status'] === 'neu' ? '<span class="marke-test">neu</span>' : '<span class="marke-aus">' . e($a['status']) . '</span>' ?></div>
      <div class="sub"><?= e($a['name']) ?> · <?= e($a['email']) ?><?= $a['telefon'] ? ' · ' . e($a['telefon']) : '' ?><?= $a['leistung'] ? ' · ' . e($a['leistung']) : '' ?></div>
      <?php if ($a['nachricht']): ?><div class="nachricht"><?= nl2br(e($a['nachricht'])) ?></div><?php endif; ?>
    </div>
    <div class="zeile-knoepfe" style="flex-direction:column;align-items:stretch">
      <?php if ($a['kunde_id']): ?><a class="knopf mini zweit" href="/admin/?s=kunden&amp;t=detail&amp;id=<?= (int) $a['kunde_id'] ?>">Kunde öffnen</a>
      <?php else: ?><a class="knopf mini" href="/admin/?s=kunden&amp;t=neu&amp;anfrage=<?= (int) $a['id'] ?>">Als Kunde anlegen</a><?php endif; ?>
      <form method="post" class="inl"><input type="hidden" name="aktion" value="anfrage_status"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="anfrage_id" value="<?= (int) $a['id'] ?>">
        <select name="status" onchange="this.form.submit()" aria-label="Status">
          <?php foreach (['neu' => 'neu', 'kontaktiert' => 'kontaktiert', 'erledigt' => 'erledigt', 'spam' => 'Spam'] as $k => $v): ?><option value="<?= $k ?>" <?= $a['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select></form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// =====================================================================
// DETAIL
// =====================================================================
elseif ($t === 'detail'):
    $objekte = $db->select('objekte', 'kunde_id = ?', [$id], 'ORDER BY aktiv DESC, name');
    $notizen = $db->rawAll(
        'SELECT n.*, b.benutzername FROM kunden_notizen n LEFT JOIN benutzer b ON b.id = n.benutzer_id
          WHERE n.kunde_id = ? AND n.betrieb_id = ? ORDER BY n.erstellt_am DESC LIMIT 50', [$id, $db->tenant()]);
?>
<p class="zurueck"><a href="/admin/?s=kunden">← Kunden</a></p>
<div class="kopfzeile">
  <h1><?= e($satz['firmenname']) ?> <?= statusMarkeKunde($satz['status']) ?></h1>
  <div class="zeile-knoepfe">
    <form method="get" class="inl zeile-knoepfe"><input type="hidden" name="s" value="kunden"><input type="hidden" name="t" value="nachweis"><input type="hidden" name="id" value="<?= $id ?>"><input type="month" name="m" value="<?= e(date('Y-m', strtotime('first day of last month'))) ?>" aria-label="Monat" style="min-height:44px"><button type="submit" class="zweit" formtarget="_blank">Leistungsnachweis</button></form>
    <a class="knopf zweit" href="/admin/?s=kunden&amp;t=bearbeiten&amp;id=<?= $id ?>">Bearbeiten</a>
    <a class="knopf" href="/admin/?s=objekte&amp;t=neu&amp;kunde=<?= $id ?>">Objekt anlegen</a>
  </div>
</div>

<div class="zweispalt">
  <section>
    <dl class="fakten">
      <dt>Adresse</dt><dd><?= e(trim(($satz['adresse'] ?? '') . ', ' . ($satz['plz'] ?? '') . ' ' . ($satz['ort'] ?? ''), ', ')) ?: '—' ?></dd>
      <dt>Ansprechperson</dt><dd><?= e($satz['kontaktperson'] ?? '—') ?></dd>
      <dt>Telefon</dt><dd><?= $satz['telefon'] ? '<a href="tel:' . e(preg_replace('/\s+/', '', $satz['telefon'])) . '">' . e($satz['telefon']) . '</a>' : '—' ?></dd>
      <dt>E-Mail</dt><dd><?= $satz['email'] ? '<a href="mailto:' . e($satz['email']) . '">' . e($satz['email']) . '</a>' : '—' ?></dd>
      <dt>Rechnung an</dt><dd><?= e($satz['rechnungs_email'] ?? $satz['email'] ?? '—') ?><?= $satz['rechnungsadresse'] ? '<div class="klein">' . e($satz['rechnungsadresse']) . '</div>' : '' ?></dd>
      <dt>Zahlungsziel</dt><dd><?= (int) $satz['zahlungsziel_tage'] ?> Tage</dd>
      <dt>UID</dt><dd><?= e($satz['uid_nummer'] ?? '—') ?></dd>
      <dt>Quelle</dt><dd><?= e($satz['quelle'] ?? '—') ?></dd>
      <?php if ($satz['notizen']): ?><dt>Notizen</dt><dd><?= nl2br(e($satz['notizen'])) ?></dd><?php endif; ?>
    </dl>

    <h2>Objekte</h2>
    <?php if ($objekte === []): ?><p class="leer">Noch kein Objekt. <a href="/admin/?s=objekte&amp;t=neu&amp;kunde=<?= $id ?>">Objekt anlegen</a></p>
    <?php else: ?>
    <div class="liste">
      <?php foreach ($objekte as $o): ?>
        <div class="eintrag eintrag-2">
          <div><div class="titel"><a href="/admin/?s=objekte&amp;t=detail&amp;id=<?= (int) $o['id'] ?>"><?= e($o['name']) ?></a><?= (int) $o['aktiv'] === 0 ? ' <span class="marke-aus">inaktiv</span>' : '' ?></div><div class="sub"><?= e($o['adresse']) ?>, <?= e($o['plz']) ?> <?= e($o['ort']) ?></div></div>
          <a class="knopf mini zweit" href="/admin/?s=objekte&amp;t=detail&amp;id=<?= (int) $o['id'] ?>">Öffnen</a>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <section>
    <?php $portal = $db->selectOne('benutzer', "kunde_id = ? AND rolle = 'kunde'", [$id]); ?>
    <h2 style="margin-top:0">Kundenportal</h2>
    <div class="qr-box" style="margin-bottom:22px">
      <?php if ($portal === null): ?>
        <p>Der Kunde sieht im Portal Nachweise mit Fotos und Zeiten, seine Rechnungen, und kann Beschwerden und Feedback melden.</p>
        <form method="post" class="zeile-knoepfe"><input type="hidden" name="aktion" value="portal_anlegen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input name="benutzername" value="<?= e($satz['email'] ?? '') ?>" placeholder="Benutzername (z. B. E-Mail)" aria-label="Benutzername" style="flex:1;min-width:200px" required>
          <button type="submit" class="mini">Zugang anlegen</button></form>
      <?php else: ?>
        <p>Zugang <b><?= e($portal['benutzername']) ?></b><?= (int) $portal['aktiv'] === 0 ? ' <span class="marke-aus">deaktiviert</span>' : '' ?><br><span class="klein"><?= $portal['letzter_login'] ? 'Zuletzt angemeldet ' . e(date('d.m.Y', strtotime($portal['letzter_login']))) : 'Noch nie angemeldet' ?> · Adresse: <?= e(rtrim($config['app']['url'], '/')) ?>/portal/</span></p>
        <div class="zeile-knoepfe">
          <form method="post" class="inl" onsubmit="return confirm('Neues Passwort erzeugen?')"><input type="hidden" name="aktion" value="portal_passwort"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="mini zweit">Passwort neu</button></form>
          <form method="post" class="inl"><input type="hidden" name="aktion" value="portal_aktiv"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="mini <?= (int) $portal['aktiv'] === 1 ? 'leise' : '' ?>"><?= (int) $portal['aktiv'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?></button></form>
        </div>
      <?php endif; ?>
    </div>
    <h2>Verlauf</h2>
    <form method="post" class="notizform">
      <input type="hidden" name="aktion" value="notiz"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <textarea name="notiz" rows="3" placeholder="Telefonat, Absprache, Besonderheit …" required aria-label="Neue Notiz"></textarea>
      <button type="submit" class="mini">Notiz speichern</button>
    </form>
    <?php if ($notizen === []): ?><p class="leer">Noch keine Einträge.</p><?php endif; ?>
    <?php foreach ($notizen as $n): ?>
      <div class="notiz"><div class="meta"><?= e(date('d.m.Y H:i', strtotime($n['erstellt_am']))) ?> · <?= e($n['benutzername'] ?? '') ?></div><?= nl2br(e($n['notiz'])) ?></div>
    <?php endforeach; ?>
  </section>
</div>

<?php
// =====================================================================
// FORMULAR (neu / bearbeiten)
// =====================================================================
else:
    $f = $pr->fehler;
?>
<p class="zurueck"><a href="<?= $id > 0 ? '/admin/?s=kunden&t=detail&id=' . $id : '/admin/?s=kunden' ?>">← Zurück</a></p>
<h1><?= $id > 0 ? 'Kunde bearbeiten' : 'Kunde anlegen' ?></h1>
<?php if ($anfrage): ?><div class="meldung ok">Aus Website-Anfrage vom <?= e(date('d.m.Y', strtotime($anfrage['erstellt_am']))) ?> vorbelegt.</div><?php endif; ?>
<form method="post" class="formular">
  <input type="hidden" name="aktion" value="speichern"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <?php if ($anfrage): ?><input type="hidden" name="anfrage_id" value="<?= (int) $anfrage['id'] ?>"><?php endif; ?>
  <h2 class="erste">Firma</h2>
  <div class="g"><label for="fn">Firmenname</label><input id="fn" name="firmenname" value="<?= e(wert('firmenname', $satz)) ?>" required maxlength="150"><?= feldFehler($f, 'firmenname') ?></div>
  <div class="zwei">
    <div class="g"><label for="st">Status</label><select id="st" name="status"><?php foreach (KUNDENSTATUS as $k => $v): ?><option value="<?= $k ?>" <?= wert('status', $satz, 'interessent') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="g"><label for="uid">UID-Nummer</label><input id="uid" name="uid_nummer" value="<?= e(wert('uid_nummer', $satz)) ?>" maxlength="20" placeholder="ATU12345678"></div>
  </div>
  <div class="g"><label for="ad">Straße und Hausnummer</label><input id="ad" name="adresse" value="<?= e(wert('adresse', $satz)) ?>" maxlength="150"></div>
  <div class="drei">
    <div class="g"><label for="plz">PLZ</label><input id="plz" name="plz" value="<?= e(wert('plz', $satz)) ?>" maxlength="10" inputmode="numeric"></div>
    <div class="g"><label for="ort">Ort</label><input id="ort" name="ort" value="<?= e(wert('ort', $satz, 'Wien')) ?>" maxlength="80"></div>
    <div class="g"><label for="bz">Bezirk</label><input id="bz" name="bezirk" value="<?= e(wert('bezirk', $satz)) ?>" maxlength="40"></div>
  </div>
  <h2>Kontakt</h2>
  <div class="g"><label for="kp">Ansprechperson</label><input id="kp" name="kontaktperson" value="<?= e(wert('kontaktperson', $satz)) ?>" maxlength="120"></div>
  <div class="zwei">
    <div class="g"><label for="tel">Telefon</label><input id="tel" name="telefon" type="tel" value="<?= e(wert('telefon', $satz)) ?>" maxlength="40"></div>
    <div class="g"><label for="em">E-Mail</label><input id="em" name="email" type="email" value="<?= e(wert('email', $satz)) ?>" maxlength="150"><?= feldFehler($f, 'email') ?></div>
  </div>
  <h2>Rechnung</h2>
  <div class="zwei">
    <div class="g"><label for="rem">Rechnungs-E-Mail</label><input id="rem" name="rechnungs_email" type="email" value="<?= e(wert('rechnungs_email', $satz)) ?>" maxlength="150"><div class="tipp">Leer = an die Kontakt-E-Mail</div><?= feldFehler($f, 'rechnungs_email') ?></div>
    <div class="g"><label for="zz">Zahlungsziel in Tagen</label><input id="zz" name="zahlungsziel_tage" type="number" min="0" max="90" value="<?= e(wert('zahlungsziel_tage', $satz, '14')) ?>"><?= feldFehler($f, 'zahlungsziel_tage') ?></div>
  </div>
  <div class="g"><label for="ra">Abweichende Rechnungsadresse</label><input id="ra" name="rechnungsadresse" value="<?= e(wert('rechnungsadresse', $satz)) ?>" maxlength="255" placeholder="Nur wenn nicht identisch mit der Firmenadresse"></div>
  <h2>Sonstiges</h2>
  <div class="g"><label for="qu">Quelle</label><input id="qu" name="quelle" value="<?= e(wert('quelle', $satz)) ?>" maxlength="50" placeholder="Empfehlung, Website, Anruf …"></div>
  <div class="g"><label for="no">Notizen</label><textarea id="no" name="notizen" maxlength="4000"><?= e(wert('notizen', $satz)) ?></textarea></div>
  <div class="zeile-knoepfe">
    <button type="submit">Speichern</button>
    <a class="knopf zweit" href="<?= $id > 0 ? '/admin/?s=kunden&t=detail&id=' . $id : '/admin/?s=kunden' ?>">Abbrechen</a>
  </div>
</form>
<?php endif; ?>

<?php
function statusMarkeKunde(string $s): string
{
    return match ($s) {
        'aktiv'   => '<span class="marke-ok">aktiv</span>',
        'inaktiv' => '<span class="marke-aus">inaktiv</span>',
        default   => '<span class="marke-test">Interessent</span>',
    };
}

function portalPasswort(): string
{
    $w = ['Fenster', 'Boden', 'Glas', 'Stein', 'Wiese', 'Garten', 'Berg', 'Fluss', 'Sonne', 'Wolke', 'Tisch', 'Lampe', 'Regal', 'Wagen', 'Brücke', 'Turm'];
    return $w[random_int(0, 15)] . '-' . $w[random_int(0, 15)] . '-' . random_int(100, 999);
}
