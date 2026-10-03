<?php
/**
 * Eigenes Konto: Passwort aendern.
 */
declare(strict_types=1);

use App\Auth;

$ok = null;
if (($_POST['aktion'] ?? '') === 'passwort') {
    try {
        Auth::checkCsrf($_POST['csrf'] ?? null);
        $alt  = (string) ($_POST['alt'] ?? '');
        $neu  = (string) ($_POST['neu'] ?? '');
        $neu2 = (string) ($_POST['neu2'] ?? '');
        if (!password_verify($alt, (string) $user['passwort_hash'])) {
            throw new RuntimeException('Das bisherige Passwort stimmt nicht.');
        }
        if (mb_strlen($neu) < 10) {
            throw new RuntimeException('Das neue Passwort braucht mindestens 10 Zeichen.');
        }
        if ($neu !== $neu2) {
            throw new RuntimeException('Die beiden neuen Passwörter stimmen nicht überein.');
        }
        if ($neu === $alt) {
            throw new RuntimeException('Das neue Passwort muss sich vom bisherigen unterscheiden.');
        }
        $db->raw('UPDATE benutzer SET passwort_hash = ? WHERE id = ?', [password_hash($neu, PASSWORD_DEFAULT), (int) $user['id']]);
        $ok = ['typ' => 'ok', 'text' => 'Passwort geändert.'];
    } catch (\Throwable $e) {
        $ok = ['typ' => 'fehler', 'text' => $e->getMessage()];
    }
}
// ---------------------------------------------------------------------
// Zwei-Faktor-Anmeldung
// ---------------------------------------------------------------------
$zfaSetup = null; $zfaBackup = null;
$zfAktion = $_POST['aktion'] ?? '';
if (in_array($zfAktion, ['zfa_start', 'zfa_bestaetigen', 'zfa_aus'], true)) {
    Auth::checkCsrf($_POST['csrf'] ?? null);
    try {
        if ($zfAktion === 'zfa_start') {
            $zfaSetup = Auth::totpSetupStart($user['benutzername'] . '@' . $betrieb['name'], $config['app']['name'] ?? 'Reinigung');
        } elseif ($zfAktion === 'zfa_bestaetigen') {
            $zfaBackup = Auth::totpSetupConfirm('benutzer', (int) $user['id'], (string) ($_POST['code'] ?? ''));
            $user['totp_aktiv'] = 1;
            $ok = ['typ' => 'ok', 'text' => 'Zwei-Faktor-Anmeldung ist aktiv. Bitte die Backup-Codes jetzt sichern — sie werden nur einmal angezeigt.'];
        } else {
            if (!password_verify((string) ($_POST['passwort'] ?? ''), (string) $user['passwort_hash'])) { throw new RuntimeException('Passwort stimmt nicht.'); }
            Auth::totpDisable('benutzer', (int) $user['id']); $user['totp_aktiv'] = 0;
            $ok = ['typ' => 'ok', 'text' => 'Zwei-Faktor-Anmeldung abgeschaltet.'];
        }
    } catch (\Throwable $e) {
        $ok = ['typ' => 'fehler', 'text' => $e->getMessage()];
        if ($zfAktion === 'zfa_bestaetigen') { Auth::startSession(); $sec = (string) ($_SESSION['totp_setup_secret'] ?? ''); if ($sec !== '') { $zfaSetup = ['secret' => $sec, 'qr' => App\Totp::qrSvg(App\Totp::uri($sec, $user['benutzername'] . '@' . $betrieb['name'], $config['app']['name'] ?? 'Reinigung'))]; } }
    }
}

?>
<h1>Mein Konto</h1>
<p class="unter">Angemeldet als <b><?= e($user['benutzername']) ?></b>, Rolle <?= $user['rolle'] === 'inhaber' ? 'Inhaber' : 'Büro' ?>.</p>
<?php if ($ok): ?><div class="meldung <?= $ok['typ'] ?>"><?= e($ok['text']) ?></div><?php endif; ?>

<form method="post" class="formular" style="max-width:520px">
  <input type="hidden" name="aktion" value="passwort"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <h2 class="erste">Passwort ändern</h2>
  <div class="g"><label for="alt">Bisheriges Passwort</label><input id="alt" name="alt" type="password" autocomplete="current-password" required></div>
  <div class="g"><label for="neu">Neues Passwort</label><input id="neu" name="neu" type="password" autocomplete="new-password" required minlength="10"><div class="tipp">Mindestens 10 Zeichen. Ein Satz mit Leerzeichen ist erlaubt und leicht zu merken.</div></div>
  <div class="g"><label for="neu2">Neues Passwort wiederholen</label><input id="neu2" name="neu2" type="password" autocomplete="new-password" required></div>
  <button type="submit">Passwort ändern</button>
</form>

<div class="formular" style="max-width:520px;margin-top:22px">
  <h2 class="erste">Zwei-Faktor-Anmeldung</h2>
  <?php if ($zfaBackup): ?>
    <p style="margin-bottom:10px">Ihre Backup-Codes — jeder gilt einmal, falls das Handy nicht verfügbar ist:</p>
    <div class="backup-codes"><?php foreach ($zfaBackup as $c): ?><code><?= e($c) ?></code><?php endforeach; ?></div>
    <p class="klein">Ausdrucken oder im Passwortmanager ablegen.</p>
  <?php elseif ($zfaSetup): ?>
    <p style="margin-bottom:12px">1. Authenticator-App öffnen (Google Authenticator, Microsoft Authenticator, Aegis …) und diesen Code scannen:</p>
    <div class="qr-setup"><?= $zfaSetup['qr'] ?></div>
    <p class="klein" style="margin:8px 0 14px">Manuell: <code><?= e(chunk_split($zfaSetup['secret'], 4, ' ')) ?></code></p>
    <form method="post"><input type="hidden" name="aktion" value="zfa_bestaetigen"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="g"><label for="zc">2. Sechsstelligen Code aus der App eingeben</label><input id="zc" name="code" inputmode="numeric" autocomplete="one-time-code" required style="font-size:22px;letter-spacing:.2em;max-width:220px"></div>
      <button type="submit">Aktivieren</button></form>
  <?php elseif ((int) ($user['totp_aktiv'] ?? 0) === 1): ?>
    <p style="margin-bottom:14px"><span class="marke-ok">aktiv</span> Bei jeder Anmeldung wird zusätzlich ein Code aus der Authenticator-App verlangt.</p>
    <form method="post"><input type="hidden" name="aktion" value="zfa_aus"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="g" style="max-width:300px"><label for="zp">Passwort zur Bestätigung</label><input id="zp" name="passwort" type="password" autocomplete="current-password" required></div>
      <button type="submit" class="zweit" onclick="return confirm('Zwei-Faktor-Anmeldung abschalten?')">Abschalten</button></form>
  <?php else: ?>
    <p style="margin-bottom:14px">Schützt das Konto zusätzlich zum Passwort mit einem Code vom Handy. <?= $user['rolle'] === 'inhaber' ? 'Für den Inhaber-Zugang dringend empfohlen — hier liegen Löhne, Sozialversicherungsnummern und Zugangscodes.' : '' ?></p>
    <form method="post"><input type="hidden" name="aktion" value="zfa_start"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit">Einrichten</button></form>
  <?php endif; ?>
</div>
