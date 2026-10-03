<?php
/**
 * Web Push (RFC 8030) mit VAPID (RFC 8292) ohne Fremdbibliothek.
 * Es wird ohne Nutzlast gesendet ("tickle"): Der Service Worker holt beim
 * Eintreffen die aktuelle Kurzmeldung vom Server ab. So entfaellt die
 * Nachrichtenverschluesselung (RFC 8291), und es liegen keine Inhalte beim
 * Push-Dienst von Google, Apple oder Mozilla.
 *
 * VAPID-Schluesselpaar (EC P-256) liegt in anbieter_einstellungen.
 */

declare(strict_types=1);

namespace App;

final class Push
{
    /** Liefert das VAPID-Paar, erzeugt es beim ersten Aufruf. */
    public static function keys(): array
    {
        $db = Database::get();
        $pub = $db->rawOne("SELECT wert FROM anbieter_einstellungen WHERE schluessel = 'vapid_public'")['wert'] ?? '';
        $priv = $db->rawOne("SELECT wert FROM anbieter_einstellungen WHERE schluessel = 'vapid_private'")['wert'] ?? '';
        if ($pub !== '' && $priv !== '') { return ['public' => $pub, 'private' => $priv]; }
        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($res === false) { throw new \RuntimeException('OpenSSL: EC-Schlüssel nicht erzeugbar.'); }
        openssl_pkey_export($res, $pem);
        $d = openssl_pkey_get_details($res);
        $pubRaw = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $pub = self::b64url($pubRaw); $priv = base64_encode($pem);
        $db->raw("INSERT INTO anbieter_einstellungen (schluessel, wert) VALUES ('vapid_public', ?), ('vapid_private', ?) ON DUPLICATE KEY UPDATE wert = VALUES(wert)", [$pub, $priv]);
        return ['public' => $pub, 'private' => $priv];
    }

    /** Alle Abos eines Mitarbeiters anstossen. Gibt Anzahl erfolgreich zurueck. */
    public static function mitarbeiter(int $mitarbeiterId): int
    {
        $db = Database::get(); $ok = 0;
        foreach ($db->rawAll('SELECT * FROM push_abos WHERE mitarbeiter_id = ?', [$mitarbeiterId]) as $abo) {
            $code = self::send($abo['endpoint']);
            if ($code >= 200 && $code < 300) { $ok++; $db->raw('UPDATE push_abos SET zuletzt_ok = NOW(), fehler = 0 WHERE id = ?', [(int) $abo['id']]); }
            elseif ($code === 404 || $code === 410) { $db->raw('DELETE FROM push_abos WHERE id = ?', [(int) $abo['id']]); }   // Abo abgelaufen
            else { $db->raw('UPDATE push_abos SET fehler = fehler + 1 WHERE id = ?', [(int) $abo['id']]); }
        }
        return $ok;
    }

    /** Nur bekannte Push-Dienste; keine beliebigen serverseitigen Ziel-URLs. */
    public static function validEndpoint(string $endpoint): bool
    {
        if (strlen($endpoint) > 2048 || preg_match('/[\\x00-\\x20\\x7f\\\\]/', $endpoint)) { return false; }
        $u = parse_url($endpoint);
        if (!is_array($u) || ($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass']) || isset($u['fragment'])) { return false; }
        if (isset($u['port']) && $u['port'] !== 443) { return false; }
        $host = strtolower((string) ($u['host'] ?? ''));
        $path = (string) ($u['path'] ?? '');
        if ($path === '' || $path === '/') { return false; }
        return in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com'], true)
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\\.)+push\\.apple\\.com$/D', $host) === 1
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\\.)+notify\\.windows\\.com$/D', $host) === 1;
    }

    /** Ein Push ohne Nutzlast an einen Endpoint. Liefert den HTTP-Status. */
    public static function send(string $endpoint, int $ttl = 86400): int
    {
        if (!self::validEndpoint($endpoint)) { return 400; }
        $ttl = max(0, min(2419200, $ttl));
        $k = self::keys();
        $aud = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $subject = 'mailto:' . (Database::get()->rawOne("SELECT wert FROM anbieter_einstellungen WHERE schluessel = 'email'")['wert'] ?? 'office@example.at');
        $jwt = self::jwt(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject], (string) base64_decode($k['private']));
        $headers = ['Authorization: vapid t=' . $jwt . ', k=' . $k['public'], 'TTL: ' . $ttl, 'Content-Length: 0', 'Urgency: high'];
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
            return $code;
        }
        // Ohne curl: Stream-Kontext
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => '', 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0]]);
        @file_get_contents($endpoint, false, $ctx);
        $status = $http_response_header[0] ?? '';
        return preg_match('/\s(\d{3})\s/', $status, $m) === 1 ? (int) $m[1] : 0;
    }

    /** ES256-JWT (RFC 7519) mit OpenSSL; DER-Signatur wird in R||S umgewandelt. */
    public static function jwt(array $claims, string $pem): string
    {
        $h = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $c = self::b64url(json_encode($claims));
        $key = openssl_pkey_get_private($pem);
        if ($key === false || !openssl_sign($h . '.' . $c, $der, $key, OPENSSL_ALGO_SHA256)) { throw new \RuntimeException('JWT-Signatur fehlgeschlagen.'); }
        return $h . '.' . $c . '.' . self::b64url(self::derToRs($der));
    }

    /** DER (SEQUENCE { INTEGER r, INTEGER s }) → 64 Bytes r||s. */
    private static function derToRs(string $der): string
    {
        $pos = 2; if (ord($der[1]) & 0x80) { $pos += ord($der[1]) & 0x7F; }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $pos++;                                   // 0x02
            $len = ord($der[$pos++]);
            $int = substr($der, $pos, $len); $pos += $len;
            $int = ltrim($int, "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    public static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** Ausstehende Pushes verschicken (vom Cron): neue Nachrichten und offene Anfragen ohne push_am. */
    public static function ausstehend(): array
    {
        $db = Database::get(); $gesendet = 0; $leute = [];
        foreach ($db->rawAll("SELECT DISTINCT mitarbeiter_id FROM app_nachrichten WHERE push_am IS NULL AND gelesen_am IS NULL AND erstellt_am > DATE_SUB(NOW(), INTERVAL 2 DAY)") as $r) { $leute[(int) $r['mitarbeiter_id']] = true; }
        foreach ($db->rawAll("SELECT DISTINCT mitarbeiter_id FROM einsatz_anfragen WHERE push_am IS NULL AND status = 'offen'") as $r) { $leute[(int) $r['mitarbeiter_id']] = true; }
        foreach (array_keys($leute) as $mid) {
            if ($db->rawOne('SELECT 1 FROM push_abos WHERE mitarbeiter_id = ? LIMIT 1', [$mid]) !== null) { $gesendet += self::mitarbeiter($mid) > 0 ? 1 : 0; }
            $db->raw('UPDATE app_nachrichten SET push_am = NOW() WHERE mitarbeiter_id = ? AND push_am IS NULL', [$mid]);
            $db->raw("UPDATE einsatz_anfragen SET push_am = NOW() WHERE mitarbeiter_id = ? AND push_am IS NULL", [$mid]);
        }
        return ['personen' => count($leute), 'gesendet' => $gesendet];
    }
}
