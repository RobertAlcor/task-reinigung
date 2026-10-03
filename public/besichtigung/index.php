<?php
/**
 * Besichtigungs-App — Wizard fuer Tablet und Handy, Buero-Zugang.
 *
 * GET  /besichtigung/              → App (HTML)
 * GET  /besichtigung/?d=liste      → JSON: Kunden, Leistungssaetze, offene Besichtigungen
 * GET  /besichtigung/?d=lade&id=N  → JSON: eine Besichtigung
 * POST aktion=speichern            → JSON: speichert/aktualisiert, gibt id
 * POST aktion=foto                 → JSON: Foto zu Besichtigung
 * POST aktion=angebot              → JSON: Angebot erzeugen, PDF-URL
 * POST aktion=kunde                → JSON: neuen Kunden (Interessent) anlegen
 */

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\Offer;

$config = require __DIR__ . '/../system/bootstrap.php';
require __DIR__ . '/../admin/_helfer.php';
\App\Response::securityHeaders();

$user = Auth::authenticate();
if ($user === null || !in_array($user['rolle'], ['inhaber', 'office'], true)) {
    if (($_GET['d'] ?? '') !== '' || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        \App\Response::error('Nicht angemeldet.', 401);
    }
    header('Location: /admin/?weiter=besichtigung');
    exit;
}
$db = Database::get();
$betrieb = $db->rawOne('SELECT id, name, farbe_primaer FROM betriebe WHERE id = ?', [$db->tenant()]);

$felder = ['kunde_id', 'ansprechpartner', 'ansprechpartner_telefon', 'objektart', 'nutzung', 'gesamtflaeche', 'anzahl_raeume', 'stockwerke', 'aufzug', 'wc', 'urinale', 'duschen', 'kueche', 'empfang', 'besprechungsraeume', 'serverraum', 'lager',
    'fenster_anzahl', 'fenster_erreichbar', 'bodenbelaege', 'glas_innen', 'glas_aussen', 'verschmutzung_boeden', 'verschmutzung_sanitaer', 'verschmutzung_kueche', 'gesamteindruck', 'red_flags', 'intervall', 'reinigungstage', 'uhrzeit_von', 'uhrzeit_bis',
    'zutritt', 'schluessel_abholung', 'erreichbarkeit_oeffi', 'parkmoeglichkeit', 'material_lagerraum', 'material_stellt_kunde', 'strom_wasser_vorhanden', 'stundensatz', 'rabatt_prozent', 'material_pauschale', 'hygiene_info', 'sicherheit_info', 'erwartung_info', 'naechster_schritt', 'interne_notizen'];
$ganzzahl = ['kunde_id', 'anzahl_raeume', 'stockwerke', 'aufzug', 'wc', 'urinale', 'duschen', 'kueche', 'empfang', 'besprechungsraeume', 'serverraum', 'lager', 'fenster_anzahl', 'glas_innen', 'glas_aussen', 'verschmutzung_boeden', 'verschmutzung_sanitaer', 'verschmutzung_kueche', 'gesamteindruck', 'material_lagerraum', 'material_stellt_kunde', 'strom_wasser_vorhanden'];
$dezimal = ['gesamtflaeche', 'stundensatz', 'rabatt_prozent', 'material_pauschale'];

// ---------------------------------------------------------------------
// Daten (JSON)
// ---------------------------------------------------------------------
if (($_GET['d'] ?? '') === 'liste') {
    $satz = $db->selectOne('leistungsarten', "kategorie = 'unterhalt' AND aktiv = 1");
    \App\Response::ok([
        'kunden' => $db->rawAll('SELECT id, firmenname, kontaktperson, telefon, adresse, plz, ort, status FROM kunden WHERE betrieb_id = ? ORDER BY firmenname', [$db->tenant()]),
        'stundensatz' => (float) ($satz['standard_stundensatz'] ?? 35),
        'offen' => $db->rawAll("SELECT b.id, b.status, b.objektart, b.gesamtflaeche, b.kalk_preis_monat, b.aktualisiert_am, k.firmenname FROM besichtigungen b LEFT JOIN kunden k ON k.id = b.kunde_id WHERE b.betrieb_id = ? ORDER BY b.aktualisiert_am DESC LIMIT 30", [$db->tenant()]),
        'kalk' => Offer::KALK, 'einsaetze' => Offer::EINSAETZE, 'betrieb' => $betrieb, 'benutzer' => $user['benutzername'],
    ]);
}
if (($_GET['d'] ?? '') === 'lade') {
    $b = $db->find('besichtigungen', (int) ($_GET['id'] ?? 0));
    if ($b === null) { \App\Response::error('Nicht gefunden.', 404); }
    $b['fotos'] = $db->select('besichtigung_fotos', 'besichtigung_id = ?', [(int) $b['id']], 'ORDER BY erstellt_am');
    \App\Response::ok($b);
}

// ---------------------------------------------------------------------
// Aktionen (JSON)
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        Auth::checkCsrf($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $aktion = $_POST['aktion'] ?? '';

        if ($aktion === 'speichern') {
            $eingabe = json_decode((string) ($_POST['daten'] ?? '{}'), true) ?: [];
            $id = (int) ($_POST['id'] ?? 0);
            $daten = [];
            foreach ($felder as $f) {
                if (!array_key_exists($f, $eingabe)) { continue; }
                $v = $eingabe[$f];
                if ($v === '' || $v === null) { $daten[$f] = null; continue; }
                if (in_array($f, $ganzzahl, true)) { $daten[$f] = (int) $v; }
                elseif (in_array($f, $dezimal, true)) { $daten[$f] = round((float) str_replace(',', '.', (string) $v), 2); }
                elseif (in_array($f, ['uhrzeit_von', 'uhrzeit_bis'], true)) { $daten[$f] = preg_match('/^\d{2}:\d{2}/', (string) $v) ? substr((string) $v, 0, 5) . ':00' : null; }
                else { $daten[$f] = mb_substr(trim((string) $v), 0, $f === 'red_flags' || str_ends_with($f, '_info') || $f === 'interne_notizen' ? 4000 : 255); }
            }
            if (isset($daten['kunde_id']) && $daten['kunde_id'] !== null && $db->find('kunden', (int) $daten['kunde_id']) === null) { $daten['kunde_id'] = null; }
            if (!isset($daten['gesamteindruck'])) { $daten['gesamteindruck'] = 3; }
            $daten['benutzer_id'] = (int) $user['id'];
            if ($id > 0) {
                if ($db->find('besichtigungen', $id) === null) { throw new RuntimeException('Besichtigung nicht gefunden.'); }
                $db->update('besichtigungen', $id, $daten);
            } else {
                $daten['status'] = 'entwurf';
                $id = $db->insert('besichtigungen', $daten);
            }
            $b = $db->find('besichtigungen', $id);
            \App\Response::ok(['id' => $id, 'kalk' => Offer::kalkulieren($b)]);
        }

        if ($aktion === 'foto') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($db->find('besichtigungen', $id) === null) { throw new RuntimeException('Besichtigung nicht gefunden.'); }
            $f = $_FILES['foto'] ?? null;
            if ($f === null || (int) $f['error'] !== UPLOAD_ERR_OK) { throw new RuntimeException('Kein Foto empfangen.'); }
            $info = @getimagesize($f['tmp_name']);
            if ($info === false || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) { throw new RuntimeException('Nur JPEG, PNG oder WebP.'); }
            $src = match ($info['mime']) { 'image/jpeg' => @imagecreatefromjpeg($f['tmp_name']), 'image/png' => @imagecreatefrompng($f['tmp_name']), default => @imagecreatefromwebp($f['tmp_name']) };
            if ($src === false) { throw new RuntimeException('Foto nicht lesbar.'); }
            $w = imagesx($src); $h = imagesy($src); $s = min(1.0, 1600 / max($w, $h));
            if ($s < 1) { $dst = imagecreatetruecolor((int) round($w * $s), (int) round($h * $s)); imagecopyresampled($dst, $src, 0, 0, 0, 0, (int) round($w * $s), (int) round($h * $s), $w, $h); imagedestroy($src); $src = $dst; }
            $dir = __DIR__ . '/../system/fotos/besichtigung/' . $db->tenant() . '/' . $id; if (!is_dir($dir)) { mkdir($dir, 0750, true); }
            $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.jpg'; imagejpeg($src, $dir . '/' . $name, 82); imagedestroy($src);
            $fid = $db->insert('besichtigung_fotos', ['besichtigung_id' => $id, 'pfad' => 'besichtigung/' . $db->tenant() . '/' . $id . '/' . $name, 'dateiname' => mb_substr((string) ($_POST['notiz'] ?? ''), 0, 150)]);
            \App\Response::ok(['id' => $fid]);
        }

        if ($aktion === 'kunde') {
            $name = trim((string) ($_POST['firmenname'] ?? ''));
            if ($name === '') { throw new RuntimeException('Firmenname fehlt.'); }
            $kid = $db->insert('kunden', ['firmenname' => mb_substr($name, 0, 150), 'kontaktperson' => mb_substr(trim((string) ($_POST['kontaktperson'] ?? '')), 0, 120) ?: null, 'telefon' => mb_substr(trim((string) ($_POST['telefon'] ?? '')), 0, 40) ?: null,
                'email' => filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null, 'adresse' => mb_substr(trim((string) ($_POST['adresse'] ?? '')), 0, 150) ?: null, 'plz' => mb_substr(trim((string) ($_POST['plz'] ?? '')), 0, 10) ?: null, 'ort' => mb_substr(trim((string) ($_POST['ort'] ?? '')), 0, 80) ?: 'Wien', 'status' => 'interessent', 'quelle' => 'Besichtigung']);
            \App\Response::ok(['id' => $kid, 'firmenname' => $name]);
        }

        if ($aktion === 'angebot') {
            $id = (int) ($_POST['id'] ?? 0);
            $aid = Offer::ausBesichtigung($id);
            Offer::pdf($aid);
            $a = $db->find('angebote', $aid);
            \App\Response::ok(['angebot_id' => $aid, 'nummer' => $a['angebotsnummer'], 'brutto' => (float) $a['brutto'], 'pdf' => '/admin/?s=angebote&t=pdf&id=' . $aid]);
        }
        throw new RuntimeException('Unbekannte Aktion.');
    } catch (\Throwable $e) {
        \App\Response::error($e->getMessage(), 422);
    }
}

// Foto ausliefern
if (($_GET['d'] ?? '') === 'foto') {
    $f = $db->find('besichtigung_fotos', (int) ($_GET['id'] ?? 0));
    $p = $f ? __DIR__ . '/../system/fotos/' . $f['pfad'] : '';
    if ($f === null || !is_file($p) || str_contains($f['pfad'], '..')) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=3600'); readfile($p); exit;
}

$csrf = Auth::csrfToken();
?><!DOCTYPE html>
<html lang="de-AT">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= e($betrieb['farbe_primaer'] ?: '#0B5D4A') ?>">
<title>Besichtigung — <?= e($betrieb['name']) ?></title>
<link rel="stylesheet" href="/app/app.css">
<link rel="stylesheet" href="/besichtigung/besichtigung.css">
<?php if ($betrieb['farbe_primaer']): ?><style>:root{--haus:<?= e($betrieb['farbe_primaer']) ?>}</style><?php endif; ?>


<style>
/* ===== Anmeldeseiten: ein Design fuer alle Bereiche ===== */
.anmelden{max-width:420px!important;margin:64px auto!important;background:#fff!important;border:1px solid var(--linie,#DDE3DE)!important;border-radius:18px!important;padding:36px 38px 34px!important;box-shadow:0 30px 60px -30px rgba(11,32,27,.25)!important;font-family:'DM Sans',system-ui,sans-serif}
.anmelden .marke-login{display:flex;align-items:center;gap:10px;margin-bottom:22px;font-size:15px;color:#5C6B64}
.anmelden .marke-login b{position:relative;font-size:22px;letter-spacing:-.02em;color:#13201B;padding-right:16px}
.anmelden .marke-login b svg{position:absolute;right:-1px;top:-5px;width:15px;height:15px}
.anmelden .marke-login span{padding-left:10px;border-left:1px solid var(--linie,#DDE3DE)}
.anmelden h1{font-size:26px!important;letter-spacing:-.02em;margin:0 0 6px!important}
.anmelden>p:first-of-type,.anmelden .unter{color:#5C6B64;font-size:14.5px;margin:0 0 20px}
.anmelden label{display:block;font-weight:600;font-size:13.5px;margin:0 0 6px;color:#13201B}
.anmelden input:not([type=hidden]):not([type=checkbox]),.anmelden .pw-wrap{width:100%!important;box-sizing:border-box}
.anmelden input:not([type=hidden]):not([type=checkbox]){height:48px;padding:0 14px!important;border:1.5px solid var(--linie,#DDE3DE)!important;border-radius:10px!important;font-size:15.5px!important;margin:0 0 16px!important;background:#fff!important;transition:border-color .15s,box-shadow .15s}
.anmelden input:focus{border-color:#0B5D4A!important;box-shadow:0 0 0 3px rgba(11,93,74,.14)!important;outline:none}
.anmelden .pw-wrap{position:relative;display:block;margin:0 0 16px}
.anmelden .pw-wrap input{margin:0!important;padding-right:50px!important}
.anmelden .pw-wrap .pw-auge{position:absolute!important;right:4px!important;left:auto!important;top:4px!important;bottom:4px!important;width:40px!important;height:40px!important;min-height:0!important;line-height:1!important;font-size:0!important;margin:0!important;padding:0!important;display:flex;align-items:center;justify-content:center;background:transparent!important;border:0!important;border-radius:8px;color:#5C6B64;cursor:pointer;box-shadow:none!important}
.anmelden .pw-wrap .pw-auge:hover,.anmelden .pw-wrap .pw-auge.aktiv{color:#0B5D4A;background:rgba(11,93,74,.08)!important}
.anmelden .pw-wrap .pw-auge svg{width:20px;height:20px;pointer-events:none}
.anmelden button[type=submit]:not(.pw-auge){width:100%;height:50px;margin-top:4px;background:#0B5D4A;color:#fff;border:0;border-radius:10px;font-size:16px;font-weight:700;cursor:pointer;transition:background .15s,transform .1s}
.anmelden button[type=submit]:not(.pw-auge):hover{background:#094c3c}.anmelden button[type=submit]:not(.pw-auge):active{transform:translateY(1px)}
.anmelden button.zweit{background:#fff!important;color:#13201B!important;border:1.5px solid var(--linie,#DDE3DE)!important}
.anmelden .meldung{border-radius:10px;padding:12px 14px;font-size:14px;margin-bottom:18px;line-height:1.5}
.anmelden .meldung.fehler{background:#FBE9E7;border:1px solid #E6B8B3;color:#8A2418}.anmelden .meldung.ok{background:#E6EFE9;border:1px solid #C6DACF;color:#0B5D4A}
.anmelden .links{margin-top:18px;text-align:center;font-size:14px;color:#5C6B64;line-height:2}
.anmelden .links a{color:#0B5D4A;font-weight:600;text-decoration:none}.anmelden .links a:hover{text-decoration:underline}
.anmelden .links .trenn{display:block;height:1px;background:var(--linie,#DDE3DE);margin:12px 0}
@media(max-width:520px){.anmelden{margin:20px 14px!important;padding:28px 22px!important;border-radius:14px!important}}
</style>
<script>
document.addEventListener("DOMContentLoaded",function(){
  document.querySelectorAll("input[type=password]").forEach(function(inp){
    if(inp.closest(".pw-wrap"))return;
    var wrap=document.createElement("div");wrap.className="pw-wrap";
    inp.parentNode.insertBefore(wrap,inp);wrap.appendChild(inp);
    var btn=document.createElement("button");btn.type="button";btn.className="pw-auge";btn.setAttribute("aria-label","Passwort anzeigen");btn.tabIndex=-1;
    btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
    wrap.appendChild(btn);
    btn.addEventListener("click",function(){var t=inp.type==="password"?"text":"password";inp.type=t;btn.classList.toggle("aktiv",t==="text");btn.setAttribute("aria-label",t==="text"?"Passwort verbergen":"Passwort anzeigen")});
  });
  // Kein-Login-Kontext (z. B. Konto-Seite): Auge auch dort rechts, ueber Wrapper-Breite
  document.querySelectorAll(".pw-wrap").forEach(function(w){if(!w.closest(".anmelden")){w.style.position="relative";w.style.display="block";var i=w.querySelector("input");if(i){i.style.paddingRight="48px";i.style.boxSizing="border-box";i.style.width="100%"}var b=w.querySelector(".pw-auge");if(b){Object.assign(b.style,{position:"absolute",right:"3px",left:"auto",top:"3px",bottom:"3px",width:"40px",height:"auto",margin:"0",padding:"0",background:"transparent",border:"0",display:"flex",alignItems:"center",justifyContent:"center",cursor:"pointer",color:"#5C6B64",boxShadow:"none"})}}});
});
</script>
</head>
<body data-csrf="<?= e($csrf) ?>">
<div id="app"><div class="laden"><div class="kreis"></div><p>Wird geladen …</p></div></div>
<script src="/besichtigung/besichtigung.js" defer></script>
</body>
</html>
