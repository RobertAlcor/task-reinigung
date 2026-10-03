<?php
/**
 * Passwort vergessen fuer Buero-Benutzer (inhaber, office) und Kunden (kunde).
 * Token 32 Bytes, nur als SHA-256-Hash gespeichert, eine Stunde gueltig,
 * einmal verwendbar. Die Antwort ist immer gleich — ob die Adresse
 * existiert, wird nicht verraten. Hoechstens drei Anfragen je Adresse
 * und Stunde.
 */

declare(strict_types=1);

namespace App;

final class PasswordReset
{
    // -----------------------------------------------------------------
    // Anbieter-Konten (anbieter_benutzer) — eigenes, einfacheres Paar,
    // da kein Betriebskontext noetig ist.
    // -----------------------------------------------------------------

    public static function requestAnbieter(string $email, string $linkBasis, string $ip, string $produkt): void
    {
        $db = Database::get();
        $email = mb_strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || !Mailer::configured()) { return; }
        $user = $db->rawOne('SELECT * FROM anbieter_benutzer WHERE LOWER(email) = ? AND aktiv = 1 LIMIT 1', [$email]);
        if ($user === null) { return; }
        $n = (int) ($db->rawOne('SELECT COUNT(*) n FROM passwort_reset_anbieter WHERE anbieter_benutzer_id = ? AND erstellt_am > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [(int) $user['id']])['n'] ?? 0);
        if ($n >= 3) { return; }
        $token = bin2hex(random_bytes(32));
        $db->raw('INSERT INTO passwort_reset_anbieter (anbieter_benutzer_id, token_hash, laeuft_ab, ip_adresse) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)', [(int) $user['id'], hash('sha256', $token), $ip]);
        $link = $linkBasis . $token;
        $text = "Guten Tag,

für den Anbieter-Zugang „" . $user['benutzername'] . "“ wurde ein neues Passwort angefordert. Über diesen Link können Sie es innerhalb einer Stunde setzen:

" . $link . "

Wenn Sie das nicht waren, ignorieren Sie diese Nachricht — Ihr Passwort bleibt unverändert.

" . $produkt;
        try { Mailer::send($email, (string) ($user['name'] ?? ''), 'Neues Passwort setzen – ' . $produkt, $text, $produkt, null); }
        catch (\Throwable $e) {
            error_log('Passwort-vergessen (Anbieter) Mailfehler: ' . $e->getMessage());
            try { $db->raw("INSERT INTO benachrichtigungen (betrieb_id, kanal, empfaenger_typ, empfaenger_id, empfaenger_email, betreff, inhalt, status, fehler) VALUES (0, 'mail', 'anbieter', ?, ?, 'Passwort vergessen (Anbieter)', '', 'fehler', ?)", [(int) $user['id'], $email, mb_substr($e->getMessage(), 0, 500)]); } catch (\Throwable) {}
        }
    }

    public static function checkAnbieter(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) { return null; }
        $db = Database::get();
        return $db->rawOne('SELECT b.*, r.id AS reset_id FROM passwort_reset_anbieter r JOIN anbieter_benutzer b ON b.id = r.anbieter_benutzer_id WHERE r.token_hash = ? AND r.verwendet_am IS NULL AND r.laeuft_ab > NOW() AND b.aktiv = 1 LIMIT 1', [hash('sha256', $token)]);
    }

    public static function completeAnbieter(string $token, string $neu): void
    {
        $user = self::checkAnbieter($token);
        if ($user === null) { throw new \RuntimeException('Der Link ist ungültig oder abgelaufen. Bitte erneut anfordern.'); }
        if (mb_strlen($neu) < 10) { throw new \RuntimeException('Mindestens 10 Zeichen.'); }
        $db = Database::get();
        $db->raw('UPDATE anbieter_benutzer SET passwort_hash = ?, fehlversuche = 0, gesperrt_bis = NULL WHERE id = ?', [password_hash($neu, PASSWORD_DEFAULT), (int) $user['id']]);
        $db->raw('UPDATE passwort_reset_anbieter SET verwendet_am = NOW() WHERE anbieter_benutzer_id = ? AND verwendet_am IS NULL', [(int) $user['id']]);
    }

    /** Erzeugt Token und sendet die Mail. Gibt immer true zurueck (kein Hinweis auf Existenz). */
    public static function request(string $email, array $rollen, string $linkBasis, string $ip): void
    {
        $db = Database::get();
        $email = mb_strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || !Mailer::configured()) { return; }
        $in = implode(',', array_fill(0, count($rollen), '?'));
        $user = $db->rawOne("SELECT b.*, be.name AS betrieb_name, be.email AS betrieb_email FROM benutzer b JOIN betriebe be ON be.id = b.betrieb_id WHERE LOWER(b.email) = ? AND b.rolle IN ({$in}) AND b.aktiv = 1 AND be.status IN ('test','aktiv') LIMIT 1", array_merge([$email], $rollen));
        if ($user === null) {
            // Kunden haben oft keine eigene E-Mail am Benutzer: ueber die Kundenakte suchen
            if (in_array('kunde', $rollen, true)) {
                $user = $db->rawOne("SELECT b.*, be.name AS betrieb_name, be.email AS betrieb_email FROM benutzer b JOIN kunden k ON k.id = b.kunde_id JOIN betriebe be ON be.id = b.betrieb_id WHERE (LOWER(k.email) = ? OR LOWER(b.benutzername) = ?) AND b.rolle = 'kunde' AND b.aktiv = 1 LIMIT 1", [$email, $email]);
            }
            if ($user === null) { return; }
        }
        $n = (int) ($db->rawOne('SELECT COUNT(*) n FROM passwort_reset WHERE benutzer_id = ? AND erstellt_am > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [(int) $user['id']])['n'] ?? 0);
        if ($n >= 3) { return; }
        $token = bin2hex(random_bytes(32));
        $db->raw('INSERT INTO passwort_reset (benutzer_id, token_hash, laeuft_ab, ip_adresse) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)', [(int) $user['id'], hash('sha256', $token), $ip]);
        $link = $linkBasis . $token;
        $text = "Guten Tag,\n\nfür den Zugang „" . $user['benutzername'] . "“ wurde ein neues Passwort angefordert. Über diesen Link können Sie es innerhalb einer Stunde setzen:\n\n" . $link . "\n\nWenn Sie das nicht waren, ignorieren Sie diese Nachricht — Ihr Passwort bleibt unverändert.\n\n" . $user['betrieb_name'];
        try { Mailer::send($email, '', 'Neues Passwort setzen', $text, (string) $user['betrieb_name'], $user['betrieb_email'] ?: null); } catch (\Throwable) {}
    }

    /** Prueft ein Token; gibt den Benutzer zurueck oder null. */
    public static function check(string $token, array $rollen): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) { return null; }
        $db = Database::get();
        $in = implode(',', array_fill(0, count($rollen), '?'));
        return $db->rawOne("SELECT b.*, r.id AS reset_id FROM passwort_reset r JOIN benutzer b ON b.id = r.benutzer_id WHERE r.token_hash = ? AND r.verwendet_am IS NULL AND r.laeuft_ab > NOW() AND b.rolle IN ({$in}) AND b.aktiv = 1 LIMIT 1", array_merge([hash('sha256', $token)], $rollen));
    }

    /** Setzt das Passwort, entwertet das Token, widerruft App-Tokens und andere Reset-Tokens. */
    public static function complete(string $token, array $rollen, string $neu): void
    {
        $user = self::check($token, $rollen);
        if ($user === null) { throw new \RuntimeException('Der Link ist ungültig oder abgelaufen. Bitte erneut anfordern.'); }
        if (mb_strlen($neu) < 10) { throw new \RuntimeException('Mindestens 10 Zeichen.'); }
        $db = Database::get();
        $db->raw('UPDATE benutzer SET passwort_hash = ?, fehlversuche = 0, gesperrt_bis = NULL WHERE id = ?', [password_hash($neu, PASSWORD_DEFAULT), (int) $user['id']]);
        $db->raw('UPDATE passwort_reset SET verwendet_am = NOW() WHERE benutzer_id = ? AND verwendet_am IS NULL', [(int) $user['id']]);
        $db->raw('UPDATE api_tokens SET widerrufen = 1 WHERE benutzer_id = ?', [(int) $user['id']]);
    }
}
