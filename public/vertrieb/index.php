<?php
/**
 * Vertriebsseite des Anbieters — wird von public/index.php eingebunden,
 * wenn der Host keiner Kundenwebsite zugeordnet ist.
 * Seiten: start, impressum, datenschutz (ueber ?p=). Demo-Anfrage per POST.
 * Produktname und Anbieterdaten kommen aus den Anbieter-Einstellungen.
 */

declare(strict_types=1);

use App\Database;
use App\Invoice;

$db = Database::get();
$s  = Invoice::settings();
$produkt = trim((string) ($db->rawOne("SELECT wert FROM anbieter_einstellungen WHERE schluessel = 'produktname'")['wert'] ?? '')) ?: 'Takt';
$tarife  = $db->rawAll('SELECT * FROM anbieter_tarife WHERE aktiv = 1 ORDER BY sortierung');
$websitePreis = 49.00; $einrichtung = 290.00;
$e = static fn(?string $x): string => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
$g = static fn(float $x): string => number_format($x, 0, ',', '.');
$seite = in_array($seite ?? 'start', ['impressum', 'datenschutz', 'registrieren'], true) ? $seite : 'start';
$reg = null;
$meldung = null;

// Demo-Anfrage
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['name'])) {
    try {
        if (trim((string) ($_POST['website'] ?? '')) !== '') { throw new \RuntimeException(''); }
        if ((int) ($_POST['t'] ?? 0) > time() - 3) { throw new \RuntimeException('Bitte erneut absenden.'); }
        $name = mb_substr(trim((string) $_POST['name']), 0, 120); $email = trim((string) ($_POST['email'] ?? ''));
        if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) { throw new \RuntimeException('Bitte Name und eine gültige E-Mail-Adresse angeben.'); }
        $ip = \App\Auth::clientIp();
        if ((int) ($db->rawOne('SELECT COUNT(*) n FROM anbieter_anfragen WHERE ip_adresse = ? AND erstellt_am > DATE_SUB(NOW(), INTERVAL 10 MINUTE)', [$ip])['n'] ?? 0) >= 3) { throw new \RuntimeException('Zu viele Anfragen. Bitte rufen Sie uns an.'); }
        $db->raw('INSERT INTO anbieter_anfragen (name, firma, email, telefon, mitarbeiter, nachricht, ip_adresse) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$name, mb_substr(trim((string) ($_POST['firma'] ?? '')), 0, 150) ?: null, $email, mb_substr(trim((string) ($_POST['telefon'] ?? '')), 0, 40) ?: null, mb_substr(trim((string) ($_POST['mitarbeiter'] ?? '')), 0, 20) ?: null, mb_substr(trim((string) ($_POST['nachricht'] ?? '')), 0, 4000) ?: null, $ip]);
        if (!empty($s['email']) && \App\Mailer::configured()) {
            try { \App\Mailer::send((string) $s['email'], (string) $s['firma'], 'Demo-Anfrage ' . $produkt . ': ' . ($_POST['firma'] ?: $name), "Name: {$name}\nFirma: " . ($_POST['firma'] ?? '') . "\nE-Mail: {$email}\nTelefon: " . ($_POST['telefon'] ?? '') . "\nMitarbeiter: " . ($_POST['mitarbeiter'] ?? '') . "\n\n" . ($_POST['nachricht'] ?? ''), $produkt, $email); } catch (\Throwable) {}
        }
        $meldung = ['typ' => 'ok', 'text' => 'Danke! Wir melden uns innerhalb eines Werktags mit einem Terminvorschlag.'];
    } catch (\RuntimeException $ex) {
        $meldung = $ex->getMessage() === '' ? ['typ' => 'ok', 'text' => 'Danke, Ihre Anfrage ist eingegangen.'] : ['typ' => 'fehler', 'text' => $ex->getMessage()];
    }
}

// Selbstregistrierung: Betrieb sofort anlegen, 30 Tage Test
if ($seite === 'registrieren' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['firma'])) {
    try {
        if (trim((string) ($_POST['website'] ?? '')) !== '') { throw new \RuntimeException(''); }
        if ((int) ($_POST['t'] ?? 0) > time() - 4) { throw new \RuntimeException('Bitte erneut absenden.'); }
        $firma = mb_substr(trim((string) $_POST['firma']), 0, 150); $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? ''))); $tel = mb_substr(trim((string) ($_POST['telefon'] ?? '')), 0, 40); $pw = (string) ($_POST['passwort'] ?? '');
        $tarif = in_array($_POST['tarif'] ?? '', ['basis', 'plus', 'pro'], true) ? $_POST['tarif'] : 'basis';
        if ($firma === '' || $name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) { throw new \RuntimeException('Bitte Firma, Name und eine gültige E-Mail-Adresse angeben.'); }
        if (mb_strlen($pw) < 10) { throw new \RuntimeException('Das Passwort braucht mindestens 10 Zeichen.'); }
        if (empty($_POST['zustimmung'])) { throw new \RuntimeException('Bitte AGB und Datenschutzerklärung bestätigen.'); }
        $ip = \App\Auth::clientIp();
        if ((int) ($db->rawOne("SELECT COUNT(*) n FROM betriebe WHERE erstellt_am > DATE_SUB(NOW(), INTERVAL 1 DAY) AND status = 'test'")['n'] ?? 0) >= 20) { throw new \RuntimeException('Zurzeit sind keine Testzugänge frei. Bitte Demo vereinbaren.'); }
        if ($db->rawOne('SELECT id FROM benutzer WHERE benutzername = ? LIMIT 1', [$email]) !== null) { throw new \RuntimeException('Zu dieser E-Mail-Adresse gibt es schon einen Zugang. Bitte anmelden oder Passwort zurücksetzen.'); }
        if ((int) ($db->rawOne("SELECT COUNT(*) n FROM anbieter_anfragen WHERE ip_adresse = ? AND status = 'testphase' AND erstellt_am > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$ip])['n'] ?? 0) >= 3) { throw new \RuntimeException('Zu viele Testzugänge von dieser Adresse. Bitte in einer Stunde erneut oder Demo vereinbaren.'); }
        $r = \App\Tenant::create(['name' => $firma, 'inhaber_login' => $email, 'email' => $email, 'tarif' => $tarif, 'test_tage' => 30, 'passwort' => $pw]);
        $db->raw('UPDATE betriebe SET telefon = ? WHERE id = ?', [$tel ?: null, (int) $r['betrieb_id']]);
        $db->raw('INSERT INTO anbieter_anfragen (name, firma, email, telefon, mitarbeiter, nachricht, status, ip_adresse) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$name, $firma, $email, $tel ?: null, null, 'Selbstregistrierung Testzugang, Tarif ' . $tarif . ', Betrieb-ID ' . $r['betrieb_id'], 'testphase', $ip]);
        $u = rtrim($config['app']['url'], '/');
        if (\App\Mailer::configured()) {
            try { \App\Mailer::send($email, $name, 'Ihr Testzugang zu ' . $produkt, "Guten Tag " . $name . ",\n\nIhr Testzugang ist eingerichtet — 30 Tage kostenlos, endet automatisch.\n\nBüro: " . $u . "/admin/\nBenutzername: " . $email . "\nPasswort: das von Ihnen gewählte\n\nMitarbeiter-App: " . $u . "/app/\nKundenportal: " . $u . "/portal/\n\nErste Schritte: Einstellungen → Betriebsdaten, dann Mitarbeiter, Kunden, Objekte anlegen — oder gleich eine Besichtigung starten.\n\nBei Fragen antworten Sie einfach auf diese Mail.\n\n" . ($s['firma'] ?? '') . "\n" . ($s['telefon'] ?? ''), $produkt, $s['email'] ?? null); } catch (\Throwable) {}
            if (!empty($s['email'])) { try { \App\Mailer::send((string) $s['email'], (string) $s['firma'], 'Neuer Testzugang: ' . $firma, "Firma: {$firma}\nName: {$name}\nE-Mail: {$email}\nTelefon: {$tel}\nTarif: {$tarif}\n\nIm Anbieter-Dashboard unter Betriebe.", $produkt, $email); } catch (\Throwable) {} }
        }
        $reg = ['ok' => true, 'email' => $email, 'url' => $u . '/admin/'];
    } catch (\RuntimeException $ex) {
        $reg = $ex->getMessage() === '' ? ['ok' => true, 'email' => (string) ($_POST['email'] ?? ''), 'url' => rtrim($config['app']['url'], '/') . '/admin/'] : ['ok' => false, 'fehler' => $ex->getMessage()];
    }
}

$titel = match ($seite) { 'impressum' => 'Impressum – ' . $produkt, 'datenschutz' => 'Datenschutz – ' . $produkt, 'registrieren' => '30 Tage kostenlos testen – ' . $produkt, default => $produkt . ' – Software für Reinigungsbetriebe in Österreich' };
$beschr = 'Dienstplan, Zeiterfassung per QR-Code, Mitarbeiter-App, Kundenportal mit Fotonachweis und Rechnungen — rechtssicher nach AZG, AVRAG und DSGVO. Ab ' . $g((float) ($tarife[0]['preis_monat'] ?? 89)) . ' € im Monat für den ganzen Betrieb, 30 Tage kostenlos testen.';
$jsonld = json_encode(['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => $produkt, 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'description' => $beschr,
    'offers' => array_map(static fn($t) => ['@type' => 'Offer', 'name' => $t['name'], 'price' => (float) $t['preis_monat'], 'priceCurrency' => 'EUR', 'priceSpecification' => ['@type' => 'UnitPriceSpecification', 'price' => (float) $t['preis_monat'], 'priceCurrency' => 'EUR', 'unitText' => 'MONTH']], $tarife),
    'provider' => ['@type' => 'Organization', 'name' => $s['firma'] ?? '', 'url' => $s['web'] ?? '']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
?><!DOCTYPE html>
<html lang="de-AT">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($titel) ?></title><meta name="description" content="<?= $e($beschr) ?>">
<meta property="og:title" content="<?= $e($titel) ?>"><meta property="og:description" content="<?= $e($beschr) ?>"><meta property="og:image" content="<?= $e(rtrim($config['app']['url'], '/')) ?>/vertrieb/img/og.jpg"><meta property="og:type" content="website"><meta name="twitter:card" content="summary_large_image">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,700,800|dm-sans:400,500,600"><link rel="stylesheet" href="/assets/css/web.css"><link rel="stylesheet" href="/vertrieb/vertrieb.css">
<script>document.documentElement.classList.add("js")</script><script type="application/ld+json"><?= $jsonld ?></script>


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
<body>
<div class="fortschritt" id="fortschritt" aria-hidden="true"></div>
<header class="kopf" id="kopf"><a class="marke" href="/"><span class="marke-text logo-blatt"><?= $e($produkt) ?><svg class="blatt" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/><path d="M5 6c4 4 8 8 11 12" stroke="#0B5D4A" stroke-width="1.4" stroke-linecap="round" fill="none" opacity=".8"/></svg></span></a>
  <nav class="nav" aria-label="Hauptnavigation"><a href="/#module">Module</a><a href="/#recht">Rechtssicher</a><a href="/#preise">Preise</a><a href="/admin/" title="Für bestehende Kunden">Kunden-Login</a></nav><a class="knopf" href="/registrieren">Kostenlos testen</a></header>
<main>
<?php if ($seite === 'start'): ?>
<section class="hero v-hero">
  <span class="blob blob-1" aria-hidden="true"></span><span class="blob blob-2" aria-hidden="true"></span>
  <div class="hero-text"><span class="ueberzeile">Digital. Einfach. Sauber. · Software für Reinigungsbetriebe</span><h1>Ihr Büro weiß morgens, was gestern Abend passiert ist.</h1>
    <p>Dienstplan, Zeiterfassung per QR-Code am Objekt, Mitarbeiter-App, Kundenportal mit Fotonachweis und Monatsrechnung — in einer Software, gebaut für Betriebe mit 5 bis 50 Reinigungskräften.</p>
    <div class="hero-tat"><a class="knopf" href="/registrieren">30 Tage kostenlos testen</a><a class="knopf-2" href="#demo">Demo vereinbaren</a></div>
    <p class="klein" style="margin-top:14px">Keine Installation, kein App-Store, keine Einrichtungsgebühr. Daten in Österreich.</p></div>
  <div class="hero-bild"><img id="hero-img" src="/vertrieb/img/dienstplan.webp" alt="Dienstplan im Büro: Wochenansicht mit Einsätzen und Team" width="1400" height="984"></div>
</section>

<section class="abschnitt">
  <h2>Drei Probleme, die jeder Reinigungsbetrieb kennt.</h2>
  <div class="drei-karten auf">
    <div class="karte-p"><b>„Wer war gestern in der Kanzlei?"</b><p>Zettel, WhatsApp, Anruf beim Vorarbeiter. Mit <?= $e($produkt) ?> stempelt jede Kraft am QR-Schild im Objekt ein und aus. Das Büro sieht es sofort, der Kunde am nächsten Morgen.</p></div>
    <div class="karte-p"><b>„Die Arbeitszeitaufzeichnung wollte die Finanzpolizei sehen."</b><p>§ 26 AZG verlangt sie lückenlos. Hier entsteht sie automatisch aus Check-in und Check-out, mit Monatsauswertung zur Unterschrift und Export fürs Lohnbüro.</p></div>
    <div class="karte-p"><b>„Der Kunde hat sich beschwert, wir haben nichts in der Hand."</b><p>Vorher-Nachher-Fotos, Zeiten und Notizen je Einsatz. Der Kunde sieht sie im Portal, bevor er anruft — und meldet dort, wenn doch etwas fehlt.</p></div>
  </div>
</section>

<section class="abschnitt" id="module">
  <h2>Was drin ist.</h2><p class="vor">Fünf Bausteine, ein Login, ein Preis. Alles greift ineinander: Aus der Besichtigung wird das Angebot, aus dem Angebot das Objekt, aus dem Objekt der Dienstplan, aus dem Dienstplan die Rechnung.</p>
  <div class="modul auf"><div class="modul-text"><span class="takt">Büro</span><h3>Kunden, Objekte, Dienstplan, Zeiten, Rechnungen.</h3><p>Der Dienstplan schreibt sich acht Wochen voraus aus den Leistungen je Objekt. Fällt jemand aus, schlägt das System Ersatz vor und informiert den Kunden — ohne den Grund zu nennen. Am Monatsende ein Klick: Rechnungen für alle Kunden aus Pauschalen, Einsätzen, Stunden und Regieleistungen.</p></div><img src="/vertrieb/img/zeiten.webp" alt="Zeiterfassung: Monatsstand Soll und Ist je Mitarbeiter" loading="lazy" width="1400" height="984"></div>
  <div class="modul umgekehrt auf"><div class="modul-text"><span class="takt">Mitarbeiter-App</span><h3>Ein Link am Handy. Kein App-Store.</h3><p>Einsatzplan, Adresse, Zugang, Hinweise. Ankommen: Code scannen. Fertig: Code scannen. Fotos vom Ergebnis, Krankmeldung ohne Grund, Urlaub beantragen, eigene Stunden einsehen. Funktioniert auch im Keller ohne Empfang — die App überträgt später nach.</p></div><div class="handys"><img src="/vertrieb/img/app-plan.webp" alt="Mitarbeiter-App: Einsatzplan" loading="lazy" width="600" height="1298"><img src="/vertrieb/img/app-einsatz.webp" alt="Mitarbeiter-App: Einsatz mit Check-in und Fotos" loading="lazy" width="600" height="1298"></div></div>
  <div class="modul auf"><div class="modul-text"><span class="takt">Kundenportal</span><h3>Der Nachweis, bevor der Kunde fragt.</h3><p>Ihr Kunde sieht Termine, Zeiten, Fotos und seine Rechnungen. Beschwerden und Lob kommen strukturiert ins Büro statt als Anruf am Freitagabend. Das ist das Argument, mit dem Sie Aufträge gegen billigere Mitbewerber gewinnen.</p></div><img src="/vertrieb/img/portal.webp" alt="Kundenportal: Nachweis eines Einsatzes mit Zeiten und Fotos" loading="lazy" width="1400" height="984"></div>
  <div class="modul umgekehrt auf"><div class="modul-text"><span class="takt">Besichtigung und Angebot</span><h3>Angebot noch vor Ort.</h3><p>Sechs Schritte am Tablet: Räume zählen, Sanitär, Zustand, Rhythmus. Die Kalkulation läuft mit. Am Ende steht das Angebot als PDF — mit fortlaufender Nummer, Leistungsumfang und Bedingungen. Wird es angenommen, ist das Objekt mit einem Klick angelegt.</p></div><div class="handys eins"><img src="/vertrieb/img/besichtigung.webp" alt="Besichtigungs-App: Kalkulation" loading="lazy" width="700" height="1079"></div></div>
  <div class="modul auf"><div class="modul-text"><span class="takt">Rechnungen</span><h3>Festschreiben. Senden. Buchen.</h3><p>Rechnungen nach § 11 UStG, unveränderbar nach Ausstellung, Korrektur nur per Gutschrift, Aufbewahrung nach § 132 BAO. Teilzahlungen, Mahnstufen, Versand per Mail mit PDF. Kleinunternehmerregelung mit einem Haken.</p></div><img src="/vertrieb/img/rechnung.webp" alt="Rechnung im Büro: Positionen, Zahlung, Storno" loading="lazy" width="1400" height="984"></div>
  <p class="zusatz"><b>Dazu buchbar: Ihre Website.</b> Startseite, Leistungen, Über uns, Kontakt, Impressum und Datenschutz im selben Design, unter Ihrer Domain. Wir richten sie ein und kümmern uns um Domain und Technik; Texte und Fotos pflegen Sie selbst im Büro. <?= $g($websitePreis) ?> € im Monat.</p>
</section>

<section class="abschnitt" id="start"><h2>So läuft der Start.</h2>
  <div class="schritte auf"><div><b>1</b><h3>Testzugang in zwei Minuten</h3><p>Firma, Name, E-Mail, Passwort — und Sie sind im Büro. Ohne Kreditkarte, endet automatisch.</p></div><div><b>2</b><h3>Demo, wenn Sie wollen</h3><p>30 Minuten online. Wir zeigen Ihnen Ihren Betrieb in <?= $e($produkt) ?> und tragen ab Pro Ihre Daten ein.</p></div><div><b>3</b><h3>QR-Schilder aufhängen</h3><p>Ein A5-Ausdruck je Objekt. Ihre Leute scannen ab morgen.</p></div><div><b>4</b><h3>Erster Monatsabschluss</h3><p>Stundenblätter, Rechnungen, Nachweise — aus einem System.</p></div></div>
</section>

<section class="recht" id="recht">
  <div class="recht-inner"><div><h2>Gebaut für österreichisches Recht — nicht angepasst.</h2><p>Die meisten Programme in diesem Markt kommen aus Deutschland oder den USA. <?= $e($produkt) ?> ist von Anfang an auf die Vorschriften ausgelegt, die für Sie gelten.</p></div>
  <div class="recht-liste auf">
    <div><b>§ 26 AZG</b><span>Arbeitszeitaufzeichnung entsteht automatisch, Monatsblatt zur Unterschrift, sieben Jahre aufbewahrt.</span></div>
    <div><b>§ 10 AVRAG</b><span>Keine Ortung. Anwesenheit nur über den QR-Code am Objekt. Vereinbarung für das Privathandy liegt bei.</span></div>
    <div><b>Art. 9 DSGVO</b><span>Krankmeldung ohne Grund. Kein Feld dafür, nirgends.</span></div>
    <div><b>Art. 28 DSGVO</b><span>Auftragsverarbeitungsvertrag mit technischen Maßnahmen, beim ersten Login bestätigt, Server in Österreich.</span></div>
    <div><b>§ 96 ArbVG</b><span>Faktenübersicht statt Punktesystem: Einsätze, Verspätungen, Beschwerden — die Bewertung bleibt beim Menschen.</span></div>
    <div><b>§ 11 UStG, § 132 BAO</b><span>Rechnungen mit allen Pflichtangaben, fortlaufend nummeriert, nach Ausstellung unveränderbar.</span></div>
  </div></div>
</section>

<section class="gruender auf" id="ueber">
  <div class="gruender-inner">
    <div class="gruender-bild"><img src="/vertrieb/img/robert.webp" srcset="/vertrieb/img/robert-klein.webp 340w, /vertrieb/img/robert.webp 680w" sizes="(max-width: 900px) 60vw, 340px" alt="Robert Alchimowicz, Entwickler von <?= $e($produkt) ?>" width="680" height="1252" loading="lazy"></div>
    <div class="gruender-text">
      <span class="takt">Wer dahinter steht</span>
      <h2>Kein Konzern. Ein Ansprechpartner, der die Branche kennt.</h2>
      <p><?= $e($produkt) ?> ist in Wien entstanden — aus der Praxis, nicht am Reißbrett. Ich kenne Reinigungsbetriebe von innen: die Zettel, die WhatsApp-Gruppen, die Anrufe am Freitagabend. Deshalb macht die Software genau das, was ein Betrieb mit fünf bis fünfzig Kräften jeden Tag braucht, und nichts, was nur gut aussieht.</p>
      <p>Wenn Sie anrufen, bin ich dran. Wenn etwas fehlt, baue ich es. Wenn ein Gesetz sich ändert, ist es in der Software, bevor die Finanzpolizei davor steht.</p>
      <p class="gruender-name"><b><?= $e(($s['inhaber'] ?? '') ?: 'Robert Alchimowicz') ?></b><br><span><?= $e(($s['firma'] ?? '') ?: 'Alcor Group') ?>, Wien</span></p>
      <div class="hero-tat"><a class="knopf" href="/registrieren">30 Tage kostenlos testen</a><a class="knopf-2" href="#demo">Mit mir reden</a></div>
    </div>
  </div>
</section>

<section class="abschnitt" id="preise">
  <h2>Preise.</h2><p class="vor">Ein Preis für den ganzen Betrieb — keine Kosten pro Nutzer, pro Kunde oder pro Objekt. Monatlich kündbar nach der Mindestlaufzeit von zwölf Monaten. Alle Preise netto.</p>
  <div class="tarife auf">
    <?php $tagline = ['Für den Start — ein Team, ein Chef, alles im Griff.', 'Für wachsende Betriebe mit mehreren Teams und Objekten.', 'Für große Betriebe — wir richten alles für Sie ein.'];
          $proKraft = static fn(array $t): string => number_format((float) $t['preis_monat'] / max(1, (int) $t['max_mitarbeiter']), 2, ',', '.');
          foreach ($tarife as $i => $t): ?><div class="tarif <?= $i === 1 ? 'hervor' : '' ?>" style="--i:<?= $i ?>"><?= $i === 1 ? '<span class="empfohlen">Meistgewählt</span>' : '' ?>
      <b><?= $e($t['name']) ?></b><p class="tagline"><?= $e($tagline[$i] ?? '') ?></p>
      <div class="preis"><?= $g((float) $t['preis_monat']) ?> €<small>/ Monat</small></div>
      <div class="ma">bis <?= (int) $t['max_mitarbeiter'] >= 999 ? 'unbegrenzt viele' : (int) $t['max_mitarbeiter'] ?> Reinigungskräfte <span class="prokraft">· ab <?= $proKraft($t) ?> € je Kraft</span></div>
      <ul>
        <li><i></i>Büro mit allen Modulen</li><li><i></i>Mitarbeiter-App in 7 Sprachen</li><li><i></i>Kundenportal mit Fotonachweis</li><li><i></i>Besichtigung und Angebote</li><li><i></i>Rechnungen, Mahnungen, Export</li><li><i></i>Berichte und Deckungsbeitrag</li>
        <li><i></i>Support per E-Mail<?= $i >= 1 ? ' <b>und Telefon</b>' : '' ?></li>
        <li class="<?= $i === 2 ? 'plus' : '' ?>"><i></i><?= $i === 2 ? '<b>Einrichtung Ihrer Daten inklusive</b>' : 'Einrichtung durch uns optional, ' . $g($einrichtung) . ' € einmalig' ?></li>
      </ul>
      <a class="knopf <?= $i === 1 ? '' : 'zweit' ?>" href="/registrieren?tarif=<?= e($t['code']) ?>">30 Tage kostenlos testen</a>
      <p class="tarif-fuss">Keine Kreditkarte · endet automatisch</p></div><?php endforeach; ?>
  </div>
  <div class="preis-zusatz">
    <div><b>Website-Modul</b><span>Eigene Website unter Ihrer Domain, gepflegt aus dem Büro. <?= $g($websitePreis) ?> € / Monat.</span></div>
    <div><b>Jährlich zahlen</b><span>Ein Monat geschenkt — Sie zahlen 11 statt 12.</span></div>
    <div><b>Mehr als 50 Kräfte</b><span>Preis auf Anfrage, gleiche Software.</span></div>
    <div><b>Alle Preise netto</b><span>Ein Preis für den ganzen Betrieb, keine Kosten je Nutzer, Kunde oder Objekt.</span></div>
  </div>
</section>


<section class="abschnitt auf"><h2>Häufige Fragen.</h2><div class="fragen">
  <details class="frage" name="faq"><summary><h3>Brauchen meine Mitarbeiter ein Diensthandy?</h3></summary><p>Nein. Die App ist eine Website und läuft auf jedem Handy. Für die Nutzung des Privathandys liegt eine Vereinbarung bei, die Freiwilligkeit, Kostenersatz und Datensparsamkeit regelt.</p></details>
  <details class="frage" name="faq"><summary><h3>Was passiert mit meinen Daten, wenn ich kündige?</h3></summary><p>Sie exportieren jederzeit alles als ZIP: alle Tabellen als Excel-Datei plus alle PDFs. Nach Vertragsende löschen wir Ihre Daten binnen 30 Tagen, wie im AV-Vertrag festgelegt.</p></details>
  <details class="frage" name="faq"><summary><h3>Wo liegen die Daten?</h3></summary><p>Auf Servern in Wien. Der Betrieb der Software ist Auftragsverarbeitung nach Art. 28 DSGVO; den Vertrag bestätigen Sie beim ersten Login.</p></details>
  <details class="frage" name="faq"><summary><h3>Kann ich Subunternehmer einbinden?</h3></summary><p>Ja. Sie übertragen einzelne Objekte; die Einsätze laufen im selben Dienstplan, und Sie bekommen die Abrechnungsbasis zum Abgleich mit deren Rechnung.</p></details>
  <details class="frage" name="faq"><summary><h3>Muss ich etwas installieren?</h3></summary><p>Nein. Büro, App und Portal laufen im Browser. Updates spielen wir ein, ohne dass Sie etwas tun.</p></details>
  <details class="frage" name="faq"><summary><h3>Funktioniert die App auch ohne Empfang, etwa im Keller?</h3></summary><p>Ja. Ein- und Ausstempeln, Fotos und Notizen werden am Handy zwischengespeichert und übertragen sich von selbst, sobald wieder Verbindung besteht. Die Uhrzeit ist die des Stempelns, nicht die der Übertragung.</p></details>
  <details class="frage" name="faq"><summary><h3>Wie sicher sind Personaldaten wie Sozialversicherungsnummer und IBAN?</h3></summary><p>Verschlüsselt gespeichert und nur für den Inhaber sichtbar, nicht für Bürobenutzer. Für Inhaber und Anbieter gibt es Zwei-Faktor-Anmeldung. Nach fünf Fehlversuchen wird ein Zugang gesperrt.</p></details>
  <details class="frage" name="faq"><summary><h3>Wie lange dauert es, bis ich arbeiten kann?</h3></summary><p>Der Testzugang steht in zwei Minuten. Kunden, Objekte und Mitarbeiter tragen Sie selbst ein — oder Sie starten mit einer Besichtigung, dann entsteht das Objekt aus dem Angebot. Ab Pro übernehmen wir die Einrichtung Ihrer Daten.</p></details>
  <details class="frage" name="faq"><summary><h3>Was, wenn ein Mitarbeiter ausfällt?</h3></summary><p>Der Dienstplan schlägt Ersatz vor — nach Qualifikation, Verfügbarkeit und Wochenauslastung. Mit einem Klick fragen Sie jemanden per App an; die Zusage teilt automatisch zu und informiert den Kunden, ohne den Grund zu nennen.</p></details>
  <details class="frage" name="faq"><summary><h3>Wie erreiche ich Sie, wenn etwas nicht klappt?</h3></summary><p>Im Büro gibt es einen Hilfe-Assistenten, der die häufigsten Fragen sofort beantwortet. Kommt er nicht weiter, zeigt er Ihnen unsere E-Mail-Adresse und für dringende Fälle die Telefonnummer. Ab Plus auch telefonisch.</p></details>
</div></section>

<section class="abschluss auf"><div class="abschluss-inner">
  <h2>Bereit loszulegen?</h2>
  <p>Starten Sie jetzt — ohne Termin, ohne Kreditkarte.</p>
  <a class="knopf gross" href="/registrieren">30 Tage kostenlos testen</a>
  <p class="klein">Oder lieber erst persönlich ansehen? <a href="#demo">Demo vereinbaren <span class="pfeil-runter">↓</span></a></p>
</div></section>

<section class="kontakt auf" id="demo"><div class="kontakt-inner">
  <div><h2>Demo vereinbaren.</h2><p class="vor" style="margin-bottom:0">30 Minuten, online, kostenlos. Wir antworten innerhalb eines Werktags.</p><div class="info"><b><?= $e($s['firma'] ?? '') ?></b><?= !empty($s['inhaber']) ? '<br>' . $e($s['inhaber']) : '' ?><?= !empty($s['telefon']) ? '<br><a href="tel:' . $e(preg_replace('/\s+/', '', (string) $s['telefon'])) . '">' . $e($s['telefon']) . '</a>' : '' ?><?= !empty($s['email']) ? '<br><a href="mailto:' . $e($s['email']) . '">' . $e($s['email']) . '</a>' : '' ?></div></div>
  <form class="formular" method="post" action="/#demo"><?= $meldung ? '<div class="meldung ' . $e($meldung['typ']) . '">' . $e($meldung['text']) . '</div>' : '' ?><input type="hidden" name="t" value="<?= time() ?>"><div class="hp"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <div class="zeile2"><div class="feldg"><label for="d-n">Name</label><input id="d-n" name="name" required autocomplete="name"></div><div class="feldg"><label for="d-f">Betrieb</label><input id="d-f" name="firma" autocomplete="organization"></div></div>
    <div class="zeile2"><div class="feldg"><label for="d-e">E-Mail</label><input id="d-e" name="email" type="email" required autocomplete="email"></div><div class="feldg"><label for="d-t">Telefon</label><input id="d-t" name="telefon" type="tel" autocomplete="tel"></div></div>
    <div class="feldg"><label for="d-m">Wie viele Reinigungskräfte?</label><select id="d-m" name="mitarbeiter"><option>1–5</option><option>6–10</option><option>11–25</option><option>26–50</option><option>mehr als 50</option></select></div>
    <div class="feldg"><label for="d-x">Was beschäftigt Sie gerade?</label><textarea id="d-x" name="nachricht" placeholder="Zeiterfassung, Dienstplan, Nachweise für Kunden …"></textarea></div>
    <button class="knopf" type="submit">Demo anfragen</button><p class="hinweis">Ihre Angaben verwenden wir nur zur Beantwortung dieser Anfrage. <a href="/datenschutz">Datenschutzerklärung</a>.</p></form>
</div></section>

<?php elseif ($seite === 'registrieren'): $tarifVor = in_array($_GET['tarif'] ?? '', ['basis', 'plus', 'pro'], true) ? $_GET['tarif'] : 'basis'; ?>
<section class="abschnitt seite-kopf"><h1>30 Tage kostenlos testen.</h1><p class="vor">Alle Module, keine Kreditkarte, keine Kündigung nötig — der Test endet von selbst. Danach entscheiden Sie.</p></section>
<section class="kontakt" style="padding-top:0"><div class="kontakt-inner">
  <div><h2>Was Sie bekommen.</h2><ul class="haken-liste"><li>Büro mit Dienstplan, Zeiterfassung, Rechnungen, Berichten</li><li>Mitarbeiter-App in sieben Sprachen</li><li>Kundenportal mit Fotonachweis</li><li>Besichtigung mit Angebot am Tablet</li><li>AV-Vertrag und Rechtstexte für Österreich</li></ul>
    <p class="klein" style="margin-top:14px">Die Zugangsdaten kommen sofort per E-Mail. Sie können den Betrieb mit echten Daten befüllen — nach dem Test bleibt alles, wenn Sie weitermachen.</p></div>
  <?php if ($reg && $reg['ok']): ?>
    <div class="formular"><div class="meldung ok"><b>Ihr Testzugang steht.</b><br>Benutzername: <?= $e($reg['email']) ?><br>Passwort: das eben gewählte.</div>
      <a class="knopf" href="<?= $e($reg['url']) ?>" style="display:block;text-align:center">Jetzt ins Büro</a>
      <p class="hinweis">Beim ersten Login bestätigen Sie Auftragsverarbeitungsvertrag und AGB, dann ist der Betrieb einsatzbereit. Eine Mail mit allen Adressen ist unterwegs.</p></div>
  <?php else: ?>
    <form class="formular" method="post" action="/registrieren"><?= $reg && !$reg['ok'] ? '<div class="meldung fehler">' . $e($reg['fehler']) . '</div>' : '' ?><input type="hidden" name="t" value="<?= time() ?>"><div class="hp"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="feldg"><label for="r-f">Betrieb</label><input id="r-f" name="firma" required autocomplete="organization" value="<?= $e($_POST['firma'] ?? '') ?>"></div>
      <div class="zeile2"><div class="feldg"><label for="r-n">Ihr Name</label><input id="r-n" name="name" required autocomplete="name" value="<?= $e($_POST['name'] ?? '') ?>"></div><div class="feldg"><label for="r-t">Telefon</label><input id="r-t" name="telefon" type="tel" autocomplete="tel" value="<?= $e($_POST['telefon'] ?? '') ?>"></div></div>
      <div class="feldg"><label for="r-e">E-Mail (wird Ihr Benutzername)</label><input id="r-e" name="email" type="email" required autocomplete="email" value="<?= $e($_POST['email'] ?? '') ?>"></div>
      <div class="feldg"><label for="r-p">Passwort (mindestens 10 Zeichen)</label><input id="r-p" name="passwort" type="password" required minlength="10" autocomplete="new-password"></div>
      <div class="feldg"><label for="r-ta">Tarif zum Testen</label><select id="r-ta" name="tarif"><?php foreach ($tarife as $t): ?><option value="<?= $e($t['code']) ?>" <?= $t['code'] === $tarifVor ? 'selected' : '' ?>><?= $e($t['name']) ?> — bis <?= (int) $t['max_mitarbeiter'] ?> Kräfte, danach <?= $g((float) $t['preis_monat']) ?> €/Monat</option><?php endforeach; ?></select></div>
      <label class="haken-feld"><input type="checkbox" name="zustimmung" value="1" required><span>Ich habe die <a href="/datenschutz" target="_blank">Datenschutzerklärung</a> gelesen und akzeptiere, dass der Testzugang nach 30 Tagen automatisch endet. AGB und Auftragsverarbeitungsvertrag bestätige ich beim ersten Login.</span></label>
      <button class="knopf" type="submit">Testzugang anlegen</button></form>
  <?php endif; ?>
</div></section>

<?php elseif ($seite === 'impressum'): ?>
<section class="abschnitt seite-kopf"><h1>Impressum</h1><p class="klein">Angaben gemäß § 5 ECG, § 14 UGB und § 25 MedienG</p></section>
<section class="abschnitt rechtstext" style="padding-top:0"><dl class="fakten">
  <dt>Unternehmen</dt><dd><?= $e($s['firma'] ?? '') ?><?= !empty($s['inhaber']) ? ', Inh. ' . $e($s['inhaber']) : '' ?></dd>
  <dt>Anschrift</dt><dd><?= $e(trim(($s['adresse'] ?? '') . ', ' . ($s['plz'] ?? '') . ' ' . ($s['ort'] ?? ''), ', ')) ?></dd>
  <?php if (!empty($s['telefon'])): ?><dt>Telefon</dt><dd><?= $e($s['telefon']) ?></dd><?php endif; ?><?php if (!empty($s['email'])): ?><dt>E-Mail</dt><dd><?= $e($s['email']) ?></dd><?php endif; ?><?php if (!empty($s['web'])): ?><dt>Web</dt><dd><?= $e($s['web']) ?></dd><?php endif; ?>
  <dt>Unternehmensgegenstand</dt><dd>Webdesign und Softwareentwicklung; Bereitstellung der Software <?= $e($produkt) ?></dd>
  <?php if (!empty($s['uid'])): ?><dt>UID-Nummer</dt><dd><?= $e($s['uid']) ?></dd><?php endif; ?>
  <?php if (!empty($s['gewerbe'])): ?><dt>Gewerbe</dt><dd><?= $e($s['gewerbe']) ?></dd><?php endif; ?><?php if (!empty($s['behoerde'])): ?><dt>Gewerbebehörde</dt><dd><?= $e($s['behoerde']) ?></dd><?php endif; ?><?php if (!empty($s['kammer'])): ?><dt>Mitglied bei</dt><dd><?= $e($s['kammer']) ?></dd><?php endif; ?>
  <dt>Anwendbare Rechtsvorschriften</dt><dd>Gewerbeordnung 1994, abrufbar unter <a href="https://www.ris.bka.gv.at" rel="noopener">ris.bka.gv.at</a></dd>
  <dt>Medieninhaber</dt><dd><?= $e($s['firma'] ?? '') ?>, <?= $e($s['ort'] ?? '') ?> — Blattlinie: Information über die Software <?= $e($produkt) ?></dd>
  <dt>Online-Streitbeilegung</dt><dd>Plattform der EU: <a href="https://ec.europa.eu/consumers/odr" rel="noopener">ec.europa.eu/consumers/odr</a>. Unser Angebot richtet sich an Unternehmer.</dd>
</dl></section>

<?php else: ?>
<section class="abschnitt seite-kopf"><h1>Datenschutzerklärung</h1></section>
<section class="abschnitt rechtstext" style="padding-top:0">
  <h2>Verantwortlicher</h2><p><?= $e($s['firma'] ?? '') ?>, <?= $e(trim(($s['adresse'] ?? '') . ', ' . ($s['plz'] ?? '') . ' ' . ($s['ort'] ?? ''), ', ')) ?><?= !empty($s['email']) ? ', ' . $e($s['email']) : '' ?>.</p>
  <h2>Hosting und Zugriffsdaten</h2><p>Diese Website wird bei der easyname GmbH, Fernkorngasse 10/3/501, 1100 Wien, gehostet. Beim Aufruf werden technisch notwendige Daten (IP-Adresse, Zeitpunkt, aufgerufene Seite, Browser) in Server-Protokollen gespeichert und nach 14 Tagen gelöscht (Art. 6 Abs. 1 lit. f DSGVO).</p>
  <h2>Demo-Anfrage</h2><p>Angaben aus dem Formular verarbeiten wir zur Beantwortung Ihrer Anfrage und zur Vertragsanbahnung (Art. 6 Abs. 1 lit. b DSGVO). Anfragen ohne Vertragsabschluss löschen wir nach zwölf Monaten.</p>
  <h2>Kundenkonten</h2><p>Für Kunden der Software gilt zusätzlich der Auftragsverarbeitungsvertrag nach Art. 28 DSGVO, der beim ersten Login angezeigt und bestätigt wird. Die darin beschriebenen technischen und organisatorischen Maßnahmen gelten für alle in der Software verarbeiteten Daten.</p>
  <h2>Schriften und Cookies</h2><p>Schriften kommen von Bunny Fonts (BunnyWay d.o.o., Slowenien) ohne Speicherung personenbezogener Daten. Diese Website setzt keine Cookies. Nach der Anmeldung in der Software wird ein technisch notwendiges Sitzungs-Cookie gesetzt.</p>
  <h2>Ihre Rechte</h2><p>Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit, Widerspruch — über die oben genannten Kontaktdaten. Beschwerde bei der Datenschutzbehörde, Barichgasse 40–42, 1030 Wien.</p>
  <p class="klein">Stand <?= date('m/Y') ?></p>
</section>
<?php endif; ?>
</main>
<footer class="fuss"><div class="fuss-innen"><div><div class="marke-text logo-blatt" style="margin-bottom:10px"><?= $e($produkt) ?><svg class="blatt" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></div><p>Software für Reinigungsbetriebe.</p><p><?= $e($s['firma'] ?? '') ?><?= !empty($s['ort']) ? ', ' . $e($s['ort']) : '' ?></p></div>
  <div><b>Produkt</b><a href="/#module">Module</a><a href="/#recht">Rechtssicherheit</a><a href="/#preise">Preise</a><a href="/#demo">Demo</a></div>
  <div><b>Zugang</b><a href="/admin/">Büro</a><a href="/app/">Mitarbeiter-App</a><a href="/portal/">Kundenportal</a><a href="/impressum">Impressum</a><a href="/datenschutz">Datenschutz</a></div></div>
  <div class="fuss-unten"><span>© <?= date('Y') ?> <?= $e($s['firma'] ?? '') ?></span><span>handprogrammiert von <a href="https://webdesign-alcor.at" rel="noopener">Webdesign ALCOR</a></span></div></footer>
<script>
document.querySelectorAll(".auf").forEach(function(el){new IntersectionObserver(function(es,o){es.forEach(function(x){if(x.isIntersecting){x.target.classList.add("da");o.unobserve(x.target)}})},{threshold:.08}).observe(el)});

// Scroll-Fortschritt, Kopfzeilen-Schatten, sanfte Parallax auf dem Hero-Bild.
// Reines Zusatzverhalten — bei "reduzierte Bewegung" nur der Fortschrittsbalken (kein Ruckeln, keine Verschiebung).
(function(){
  var reduziert = matchMedia("(prefers-reduced-motion: reduce)").matches;
  var kopf = document.getElementById("kopf"), balken = document.getElementById("fortschritt"), heroImg = document.getElementById("hero-img");
  var heroSektion = document.querySelector(".v-hero"), ticking = false;
  function aktualisieren(){
    var oben = window.scrollY || document.documentElement.scrollTop;
    var hoehe = document.documentElement.scrollHeight - document.documentElement.clientHeight;
    if (balken) balken.style.width = (hoehe > 0 ? Math.min(100, oben / hoehe * 100) : 0) + "%";
    if (kopf) kopf.classList.toggle("kopf--tief", oben > 8);
    if (!reduziert && heroImg && heroSektion){
      var r = heroSektion.getBoundingClientRect();
      if (r.bottom > 0 && r.top < window.innerHeight) heroImg.style.transform = "translateY(" + Math.max(-26, Math.min(26, oben * 0.06)) + "px)";
    }
    ticking = false;
  }
  aktualisieren();
  window.addEventListener("scroll", function(){ if (!ticking){ requestAnimationFrame(aktualisieren); ticking = true; } }, {passive:true});
})();
</script>
</body></html>
