<?php
/**
 * Buero-Dashboard eines Betriebs.
 *
 * Ein Einstiegspunkt, Seiten liegen in seiten/*.php. Jede Seite bekommt
 * $db (mit gesetztem Mandanten), $user, $csrf und $config. Vor der
 * ersten Nutzung muss der Inhaber AV-Vertrag und AGB bestaetigen.
 */

declare(strict_types=1);

use App\Auth;
use App\AuthException;
use App\Consent;
use App\Database;

$config = require __DIR__ . '/../system/bootstrap.php';
require __DIR__ . '/_helfer.php';
\App\Response::securityHeaders();

$db      = Database::get();
$meldung = null;
$aktion  = $_POST['aktion'] ?? null;
$seite   = preg_replace('/[^a-z_]/', '', (string) ($_GET['s'] ?? 'start')) ?: 'start';

// ---------------------------------------------------------------------
// Abmelden
// ---------------------------------------------------------------------
if ($aktion === 'logout') {
    Auth::logout();
    header('Location: /admin/');
    exit;
}

// ---------------------------------------------------------------------
// Direktzugang des Anbieter-Superusers (2 Minuten, einmalig)
// ---------------------------------------------------------------------
if (isset($_GET['direkt']) && preg_match('/^[a-f0-9]{40}$/', (string) $_GET['direkt']) === 1) {
    $dz = $db->rawOne("SELECT * FROM anbieter_direktzugang WHERE token_hash = ? AND ziel = 'buero' AND verwendet_am IS NULL AND laeuft_ab > NOW()", [hash('sha256', (string) $_GET['direkt'])]);
    if ($dz !== null) {
        $db->raw('UPDATE anbieter_direktzugang SET verwendet_am = NOW() WHERE id = ?', [(int) $dz['id']]);
        $inh = $db->rawOne('SELECT * FROM benutzer WHERE id = ? AND aktiv = 1', [(int) $dz['benutzer_id']]);
        if ($inh !== null) { Auth::loginOhnePasswort($inh); Auth::startSession(); $_SESSION['anbieter_ansicht'] = (string) ($dz['erstellt_von'] ?? 'Anbieter'); }
    }
    header('Location: /admin/'); exit;
}

// ---------------------------------------------------------------------
// Schnellzugang per QR/Link (nur Testbetriebe) — ersetzt Benutzername/Passwort,
// Zwei-Faktor bleibt bestehen falls eingerichtet. Danach normale Weiterverarbeitung.
// ---------------------------------------------------------------------
if (isset($_GET['zugang']) && preg_match('/^[a-f0-9]{40}$/', (string) $_GET['zugang']) === 1) {
    $tok = (string) $_GET['zugang'];
    $z = $db->rawOne('SELECT z.*, b.status AS betrieb_status FROM anbieter_schnellzugang z JOIN betriebe b ON b.id = z.betrieb_id WHERE z.token_hash = ? AND z.laeuft_ab > NOW()', [hash('sha256', $tok)]);
    if ($z !== null && $z['betrieb_status'] === 'test') {
        $inh = $db->rawOne("SELECT * FROM benutzer WHERE betrieb_id = ? AND rolle = 'inhaber' AND aktiv = 1 ORDER BY id LIMIT 1", [(int) $z['betrieb_id']]);
        if ($inh !== null) {
            $db->raw('UPDATE anbieter_schnellzugang SET nutzungen = nutzungen + 1, letzte_nutzung = NOW() WHERE id = ?', [(int) $z['id']]);
            $db->raw("INSERT INTO audit_log (betrieb_id, benutzer_id, aktion, beschreibung, ip_adresse) VALUES (?, ?, 'login', 'Schnellzugang (QR/Link) verwendet', ?)", [(int) $z['betrieb_id'], (int) $inh['id'], Auth::clientIp()]);
            Auth::loginOhnePasswort($inh);
        }
    }
    header('Location: /admin/'); exit;   // Token nie in der Adressleiste stehen lassen
}

// ---------------------------------------------------------------------
// Anmelden
// ---------------------------------------------------------------------
$user = Auth::authenticate();
if ($user !== null && !in_array($user['rolle'], ['inhaber', 'office'], true)) {
    Auth::logout();
    $user = null;
    $meldung = ['typ' => 'fehler', 'text' => 'Dieser Zugang ist nicht für den Bürobereich freigeschaltet.'];
}

if ($user === null) {
    if ($aktion === 'login') {
        try {
            $u = Auth::loginWithPassword((string) ($_POST['benutzername'] ?? ''), (string) ($_POST['passwort'] ?? ''));
            if (!in_array($u['rolle'], ['inhaber', 'office'], true)) {
                Auth::logout();
                throw new AuthException('Dieser Zugang ist nicht für den Bürobereich freigeschaltet.');
            }
            if (!empty($u['zweiter_faktor'])) {
                Auth::startSession(); $_SESSION['zf_weiter'] = (string) ($_POST['weiter'] ?? '');
                layoutZweiterFaktor(null); exit;
            }
            header('Location: ' . (($_POST['weiter'] ?? '') === 'besichtigung' ? '/besichtigung/' : '/admin/'));
            exit;
        } catch (AuthException $e) {
            $meldung = ['typ' => 'fehler', 'text' => $e->getMessage()];
        }
    }
    if ($aktion === 'zweiter_faktor') {
        try {
            Auth::completeSecondFactor((string) ($_POST['code'] ?? ''));
            Auth::startSession(); $weiter = (string) ($_SESSION['zf_weiter'] ?? ''); unset($_SESSION['zf_weiter']);
            header('Location: ' . ($weiter === 'besichtigung' ? '/besichtigung/' : '/admin/'));
            exit;
        } catch (AuthException $e) {
            if (Auth::pendingSecondFactor() !== null) { layoutZweiterFaktor(['typ' => 'fehler', 'text' => $e->getMessage()]); exit; }
            $meldung = ['typ' => 'fehler', 'text' => $e->getMessage()];
        }
    }
    if (Auth::pendingSecondFactor() !== null) { layoutZweiterFaktor($meldung); exit; }
    // Passwort vergessen
    if ($aktion === 'vergessen') {
        App\PasswordReset::request((string) ($_POST['email'] ?? ''), ['inhaber', 'office'], rtrim($config['app']['url'], '/') . '/admin/?reset=', Auth::clientIp());
        layoutVergessen(['typ' => 'ok', 'text' => 'Wenn zu dieser Adresse ein Zugang gehört, ist eine E-Mail mit dem Link unterwegs. Bitte auch den Spam-Ordner prüfen.']); exit;
    }
    if (isset($_GET['vergessen'])) { layoutVergessen(App\Mailer::configured() ? null : ['typ' => 'fehler', 'text' => 'Der Mailversand ist nicht eingerichtet. Bitte wenden Sie sich an den Anbieter.']); exit; }
    if (isset($_GET['reset']) || $aktion === 'reset') {
        $token = (string) ($_POST['token'] ?? $_GET['reset'] ?? '');
        if ($aktion === 'reset') {
            try {
                if (($_POST['neu'] ?? '') !== ($_POST['neu2'] ?? '')) { throw new RuntimeException('Die Passwörter stimmen nicht überein.'); }
                App\PasswordReset::complete($token, ['inhaber', 'office'], (string) ($_POST['neu'] ?? ''));
                layoutLogin(['typ' => 'ok', 'text' => 'Passwort gesetzt. Bitte anmelden.']); exit;
            } catch (\Throwable $e) { layoutReset($token, ['typ' => 'fehler', 'text' => $e->getMessage()]); exit; }
        }
        if (App\PasswordReset::check($token, ['inhaber', 'office']) === null) { layoutLogin(['typ' => 'fehler', 'text' => 'Der Link ist ungültig oder abgelaufen. Bitte erneut anfordern.']); exit; }
        layoutReset($token, null); exit;
    }
    layoutLogin($meldung);
    exit;
}

$betrieb = $db->rawOne('SELECT * FROM betriebe WHERE id = ?', [$db->tenant()]);
$csrf    = Auth::csrfToken();

// ---------------------------------------------------------------------
// Hilfe-Assistent (JSON, nur angemeldet)
// ---------------------------------------------------------------------
if ($seite === 'hilfe') {
    header('Content-Type: application/json; charset=utf-8');
    $frage = mb_substr(trim((string) ($_GET['frage'] ?? '')), 0, 200);
    $anb = App\Invoice::settings(); $u = rtrim($config['app']['url'], '/');
    $out = ['treffer' => [], 'kontakt' => ['email' => $anb['email'] ?? '', 'telefon' => $anb['telefon'] ?? '', 'firma' => $anb['firma'] ?? ''], 'adressen' => ['app' => $u . '/app/', 'portal' => $u . '/portal/', 'besichtigung' => $u . '/besichtigung/']];
    if ($frage === '') { $out['alle'] = array_map(static fn($e) => ['f' => $e['f'], 'a' => $e['a'], 'l' => $e['l'] ?? null], App\Hilfe::wissen()); }
    else { $out['treffer'] = array_map(static fn($e) => ['f' => $e['f'], 'a' => $e['a'], 'l' => $e['l'] ?? null], App\Hilfe::suchen($frage)); }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}

// ---------------------------------------------------------------------
// Betrieb gesperrt oder gekuendigt
// ---------------------------------------------------------------------
if ($betrieb['status'] === 'gekuendigt') {
    layoutHinweis('Zugang beendet', 'Der Vertrag für diesen Betrieb ist beendet. Für einen Datenexport wenden Sie sich bitte an den Anbieter.');
    exit;
}
if ($betrieb['status'] === 'gesperrt') {
    $anb = App\Invoice::settings();
    layoutHinweis('Testphase beendet', 'Die kostenlose Testphase ist abgelaufen. Ihre Daten bleiben erhalten. Zum Weiterarbeiten genügt ein Anruf oder eine E-Mail' . (!empty($anb['telefon']) ? ' — ' . e($anb['telefon']) : '') . (!empty($anb['email']) ? ', ' . e($anb['email']) : '') . '.');
    exit;
}

// ---------------------------------------------------------------------
// Zustimmungspflicht: AV-Vertrag und AGB
// ---------------------------------------------------------------------
$fehlend = Consent::missing((int) $betrieb['id']);
if ($fehlend !== []) {
    if ($user['rolle'] !== 'inhaber') {
        layoutHinweis('Noch nicht freigeschaltet', 'Bevor der Bürobereich genutzt werden kann, muss der Inhaber des Betriebs die Vertragsbedingungen bestätigen. Bitte wenden Sie sich an ' . e($betrieb['name']) . '.');
        exit;
    }
    if ($aktion === 'zustimmen') {
        try {
            Auth::checkCsrf($_POST['csrf'] ?? null);
            if (empty($_POST['av_ok']) || empty($_POST['agb_ok'])) {
                throw new RuntimeException('Bitte beide Dokumente bestätigen.');
            }
            foreach ($fehlend as $typ) {
                Consent::accept((int) $betrieb['id'], (int) $user['id'], $typ, (string) $betrieb['name']);
            }
            header('Location: /admin/');
            exit;
        } catch (\Throwable $e) {
            $meldung = ['typ' => 'fehler', 'text' => $e->getMessage()];
        }
    }
    layoutZustimmung($betrieb, $csrf, $meldung);
    exit;
}

// ---------------------------------------------------------------------
// Seiten
// ---------------------------------------------------------------------
$seiten = [
    'start'       => ['Heute',        'seiten/start.php'],
    'kunden'      => ['Kunden',       'seiten/kunden.php'],
    'objekte'     => ['Objekte',      'seiten/objekte.php'],
    'mitarbeiter' => ['Mitarbeiter',  'seiten/mitarbeiter.php'],
    'dienstplan'  => ['Dienstplan',   'seiten/dienstplan.php'],
    'zeiten'      => ['Zeiterfassung','seiten/zeiten.php'],
    'beschwerden' => ['Beschwerden',  'seiten/beschwerden.php'],
    'angebote'    => ['Angebote',     'seiten/angebote.php'],
    'berichte'    => ['Berichte',     'seiten/berichte.php'],
    'rechnungen'  => ['Rechnungen',   'seiten/rechnungen.php'],
    'einstellungen' => ['Einstellungen', 'seiten/einstellungen.php'],
    'subunternehmer' => ['Subunternehmer', 'seiten/subunternehmer.php'],
    'konto'       => ['Mein Konto',   'seiten/konto.php'],
];
if (!isset($seiten[$seite])) {
    $seite = 'start';
}
$datei = __DIR__ . '/' . $seiten[$seite][1];
$meldung = $meldung ?? flashLesen();

ob_start();
if (is_file($datei)) {
    require $datei;
} else {
    echo '<h1>' . e($seiten[$seite][0]) . '</h1><p class="leer">Dieser Bereich ist in Arbeit.</p>';
}
$inhalt = ob_get_clean();

layoutSeite($seiten[$seite][0], $inhalt, $seiten, $seite, $betrieb, $user, $meldung);

// =====================================================================
// Layout
// =====================================================================

function layoutKopf(string $titel): void
{
    ?><!DOCTYPE html>
<html lang="de-AT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titel) ?> — Büro</title>
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,700,800|dm-sans:400,500,600">
<link rel="stylesheet" href="/assets/css/buero.css">



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
<?php
}

function layoutLogin(?array $meldung): void
{
    layoutKopf('Anmelden');
    ?>
    <div class="anmelden">
      <div class="marke-login"><b>Takt<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></b><span>Büro</span></div>
      <h1>Willkommen zurück</h1><p class="unter">Melden Sie sich mit Ihrem Bürozugang an.</p>
      <?php meldungAnzeigen($meldung); ?>
      <form method="post">
        <input type="hidden" name="aktion" value="login">
        <?php if (($_GET['weiter'] ?? '') === 'besichtigung'): ?><input type="hidden" name="weiter" value="besichtigung"><?php endif; ?>
        <label for="bn">Benutzername</label>
        <input id="bn" name="benutzername" autocomplete="username" required autofocus>
        <label for="pw">Passwort</label>
        <input id="pw" name="passwort" type="password" autocomplete="current-password" required>
        <button type="submit">Anmelden</button>
      </form>
      <div class="links"><a href="/admin/?vergessen">Passwort vergessen?</a><span class="trenn"></span>Noch kein Zugang? <a href="/registrieren">30 Tage kostenlos testen</a><br>Sie sind der Anbieter? <a href="/anbieter/">Zum Anbieter-Dashboard</a></div>
    </div>
    </body></html><?php
}

function layoutVergessen(?array $meldung): void
{
    layoutKopf('Passwort vergessen');
    ?><div class="anmelden"><div class="marke-login"><b>Takt<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></b><span>Büro</span></div><h1>Passwort vergessen</h1><p style="color:var(--text2);margin-bottom:18px">Wir schicken einen Link zum Zurücksetzen an die E-Mail-Adresse Ihres Zugangs.</p><?php meldungAnzeigen($meldung); ?>
      <form method="post"><input type="hidden" name="aktion" value="vergessen"><label for="em">E-Mail-Adresse</label><input id="em" name="email" type="email" required autofocus autocomplete="email"><button type="submit">Link anfordern</button></form>
      <p style="margin-top:16px;text-align:center"><a href="/admin/" style="font-size:14px">Zurück zur Anmeldung</a></p></div></body></html><?php
}

function layoutReset(string $token, ?array $meldung): void
{
    layoutKopf('Neues Passwort');
    ?><div class="anmelden"><div class="marke-login"><b>Takt<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4c9 0 15 6 15 15C10 19 4 13 3 4z" fill="#2FB36B"/></svg></b><span>Büro</span></div><h1>Neues Passwort</h1><?php meldungAnzeigen($meldung); ?>
      <form method="post"><input type="hidden" name="aktion" value="reset"><input type="hidden" name="token" value="<?= e($token) ?>">
        <label for="neu">Neues Passwort</label><input id="neu" name="neu" type="password" required minlength="10" autocomplete="new-password" autofocus>
        <label for="neu2">Wiederholen</label><input id="neu2" name="neu2" type="password" required autocomplete="new-password">
        <button type="submit">Passwort setzen</button></form></div></body></html><?php
}

function layoutZweiterFaktor(?array $meldung): void
{
    layoutKopf('Bestätigung');
    ?>
    <div class="anmelden">
      <h1>Bestätigung</h1>
      <p style="color:var(--text2);margin-bottom:18px">Bitte den sechsstelligen Code aus Ihrer Authenticator-App eingeben — oder einen Ihrer Backup-Codes.</p>
      <?php meldungAnzeigen($meldung); ?>
      <form method="post">
        <input type="hidden" name="aktion" value="zweiter_faktor">
        <label for="code">Code</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus style="font-size:24px;letter-spacing:.2em;text-align:center">
        <button type="submit">Bestätigen</button>
      </form>
      <form method="post" style="margin-top:14px"><input type="hidden" name="aktion" value="logout"><button type="submit" class="zweit">Abbrechen</button></form>
    </div>
    </body></html><?php
}

function layoutHinweis(string $titel, string $text): void
{
    layoutKopf($titel);
    echo '<div class="anmelden"><h1>' . e($titel) . '</h1><p>' . $text . '</p>';
    echo '<form method="post" style="margin-top:20px"><input type="hidden" name="aktion" value="logout"><button type="submit" class="zweit">Abmelden</button></form></div></body></html>';
}

function layoutZustimmung(array $betrieb, string $csrf, ?array $meldung): void
{
    layoutKopf('Vertragsbedingungen');
    ?>
    <div class="zustimmung">
      <h1>Willkommen, <?= e($betrieb['name']) ?></h1>
      <p class="unter">Bevor Sie beginnen, bestätigen Sie bitte die beiden Dokumente. Sie regeln, wie mit den Daten Ihrer Mitarbeiter und Kunden umgegangen wird — das ist Voraussetzung, damit Sie diese Daten hier eingeben dürfen.</p>
      <?php meldungAnzeigen($meldung); ?>
      <form method="post">
        <input type="hidden" name="aktion" value="zustimmen">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

        <details open>
          <summary>Vereinbarung zur Auftragsverarbeitung <span class="klein">Version <?= e(Consent::version('av_vertrag')) ?></span></summary>
          <div class="dokument"><?= Consent::html('av_vertrag', (string) $betrieb['name']) ?></div>
        </details>
        <label class="haken"><input type="checkbox" name="av_ok" value="1" required> Ich habe die Vereinbarung zur Auftragsverarbeitung gelesen und stimme ihr zu.</label>

        <details>
          <summary>Allgemeine Geschäftsbedingungen <span class="klein">Version <?= e(Consent::version('agb')) ?></span></summary>
          <div class="dokument"><?= Consent::html('agb', (string) $betrieb['name']) ?></div>
        </details>
        <label class="haken"><input type="checkbox" name="agb_ok" value="1" required> Ich habe die Allgemeinen Geschäftsbedingungen gelesen und stimme ihnen zu.</label>

        <p class="klein" style="margin:18px 0 14px">Ihre Zustimmung wird mit Datum, Uhrzeit, Benutzername und Version der Dokumente gespeichert. Beide Dokumente können Sie jederzeit unter „Einstellungen" erneut aufrufen.</p>
        <div class="zeile-knoepfe">
          <button type="submit">Bestätigen und starten</button>
          <button type="submit" name="aktion" value="logout" class="zweit" formnovalidate>Abmelden</button>
        </div>
      </form>
    </div>
    </body></html><?php
}

function layoutSeite(string $titel, string $inhalt, array $seiten, string $aktiv, array $betrieb, array $user, ?array $meldung): void
{
    layoutKopf($titel);
    ?>
    <?php if (!empty($_SESSION['anbieter_ansicht'])): ?><div class="anbieter-banner">Anbieter-Ansicht (<?= e((string) $_SESSION['anbieter_ansicht']) ?>) — Sie sehen den Betrieb wie der Inhaber. Zugriff wird protokolliert. <form method="post" class="inl"><input type="hidden" name="aktion" value="logout"><button type="submit" class="leise" style="color:#fff;text-decoration:underline">Beenden</button></form></div><?php endif; ?>
    <div class="rahmen">
      <aside class="seitenleiste">
        <div class="betrieb"><?= e($betrieb['name']) ?></div>
        <nav>
          <?php foreach ($seiten as $key => [$name]): if (in_array($key, ['konto', 'subunternehmer'], true)) continue; ?>
            <a href="/admin/?s=<?= $key ?>" class="<?= $key === $aktiv ? 'aktiv' : '' ?>"><?= e($name) ?></a>
          <?php endforeach; ?>
        </nav>
        <div class="leiste-fuss">
          <a href="/admin/?s=konto" class="<?= $aktiv === 'konto' ? 'aktiv' : '' ?>"><?= e($user['benutzername']) ?></a>
          <form method="post"><input type="hidden" name="aktion" value="logout"><button type="submit" class="leise">Abmelden</button></form>
        </div>
      </aside>
      <main>
        <?php meldungAnzeigen($meldung); ?>
        <?= $inhalt ?>
      </main>
    </div>
    <button class="hilfe-knopf" id="hilfe-knopf" type="button" aria-label="Hilfe öffnen" aria-expanded="false"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2-3 4"/><circle cx="12" cy="17.5" r=".6" fill="currentColor"/></svg><span>Hilfe</span></button>
    <div class="hilfe-panel" id="hilfe-panel" role="dialog" aria-label="Hilfe-Assistent" hidden>
      <div class="hilfe-kopf"><b>Hilfe</b><button type="button" class="hilfe-zu" id="hilfe-zu" aria-label="Schließen">×</button></div>
      <div class="hilfe-suche"><input id="hilfe-frage" type="search" placeholder="Was möchten Sie tun? z. B. Rechnung stornieren" autocomplete="off"></div>
      <div class="hilfe-inhalt" id="hilfe-inhalt"><p class="klein">Frage eingeben oder ein Thema wählen.</p></div>
      <div class="hilfe-fuss" id="hilfe-fuss"></div>
    </div>
    <script>
    (function(){
      var k=document.getElementById("hilfe-knopf"),p=document.getElementById("hilfe-panel"),z=document.getElementById("hilfe-zu"),f=document.getElementById("hilfe-frage"),i=document.getElementById("hilfe-inhalt"),fu=document.getElementById("hilfe-fuss");
      var alle=null,kontakt=null,adressen=null,timer=null;
      function esc(s){return String(s==null?"":s).replace(/[&<>"]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]})}
      function kontaktHtml(vorwort){var h='<div class="hilfe-kontakt">'+(vorwort?'<p>'+vorwort+'</p>':'');
        if(kontakt&&kontakt.email)h+='<a href="mailto:'+esc(kontakt.email)+'?subject='+encodeURIComponent("Frage zu Takt: "+(f.value||""))+'"><b>E-Mail</b> '+esc(kontakt.email)+'</a>';
        if(kontakt&&kontakt.telefon)h+='<a href="tel:'+esc(kontakt.telefon.replace(/\s+/g,""))+'"><b>Dringend: Telefon</b> '+esc(kontakt.telefon)+'</a>';
        return h+'</div>'}
      function eintrag(e){return '<details class="hilfe-e"><summary>'+esc(e.f)+'</summary><p>'+esc(e.a)+'</p>'+(e.l?'<a class="hilfe-link" href="'+esc(e.l)+'">Dorthin →</a>':'')+'</details>'}
      function zeigeAlle(){i.innerHTML='<p class="klein">Häufige Fragen — oder oben eine eigene Frage eingeben.</p>'+alle.map(eintrag).join("");fu.innerHTML=kontaktHtml("Nicht dabei?")+'<p class="klein hilfe-adressen">App: <code>'+esc(adressen.app)+'</code> · Portal: <code>'+esc(adressen.portal)+'</code></p>'}
      function lade(q,cb){fetch("/admin/?s=hilfe&frage="+encodeURIComponent(q||"")).then(function(r){return r.json()}).then(cb).catch(function(){i.innerHTML='<p class="klein">Verbindung unterbrochen.</p>'})}
      function oeffnen(){p.hidden=false;k.setAttribute("aria-expanded","true");f.focus();if(!alle)lade("",function(d){alle=d.alle;kontakt=d.kontakt;adressen=d.adressen;zeigeAlle()})}
      function schliessen(){p.hidden=true;k.setAttribute("aria-expanded","false")}
      k.addEventListener("click",function(){p.hidden?oeffnen():schliessen()});z.addEventListener("click",schliessen);
      document.addEventListener("keydown",function(ev){if(ev.key==="Escape"&&!p.hidden)schliessen()});
      f.addEventListener("input",function(){clearTimeout(timer);var q=f.value.trim();if(q.length<3){if(alle)zeigeAlle();return}
        timer=setTimeout(function(){lade(q,function(d){kontakt=d.kontakt;adressen=d.adressen;
          if(d.treffer.length){i.innerHTML=d.treffer.map(function(e,n){return eintrag(e).replace("<details","<details"+(n===0?" open":""))}).join("");fu.innerHTML=kontaktHtml("Hilft das nicht weiter?")}
          else{i.innerHTML='<p>Dazu habe ich keine Antwort.</p>';fu.innerHTML=kontaktHtml("Schreiben Sie uns — wir antworten innerhalb eines Werktags. Bei dringenden Fällen anrufen.")}})},250)});
    })();
    </script>
    </body></html><?php
}

function meldungAnzeigen(?array $m): void
{
    if ($m === null) {
        return;
    }
    echo '<div class="meldung ' . e($m['typ']) . '">' . e($m['text']) . '</div>';
}
