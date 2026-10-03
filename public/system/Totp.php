<?php
/**
 * Zeitbasierte Einmalpasswoerter nach RFC 6238 (TOTP), SHA-1, 6 Stellen,
 * 30-Sekunden-Fenster — kompatibel mit Google Authenticator, Microsoft
 * Authenticator, Aegis, 1Password. Ohne externe Bibliothek.
 *
 * Das Geheimnis wird verschluesselt gespeichert (Crypto), Backup-Codes
 * als password_hash. Ein Code darf nur einmal gelten (letzter Zeitschritt
 * wird gemerkt).
 */

declare(strict_types=1);

namespace App;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Neues Geheimnis, 20 Bytes → 32 Zeichen Base32. */
    public static function secret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** otpauth-URI fuer den QR-Code. */
    public static function uri(string $secret, string $konto, string $aussteller): string
    {
        return 'otpauth://totp/' . rawurlencode($aussteller) . ':' . rawurlencode($konto) . '?secret=' . $secret . '&issuer=' . rawurlencode($aussteller) . '&algorithm=SHA1&digits=6&period=30';
    }

    /** QR-Code als SVG (ohne XML-Kopf). */
    public static function qrSvg(string $uri): string
    {
        require_once __DIR__ . '/lib/qrcode/autoload.php';
        $o = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG, 'eccLevel' => \chillerlan\QRCode\QRCode::ECC_M, 'addQuietzone' => true, 'outputBase64' => false, 'svgAddXmlHeader' => false]);
        return (new \chillerlan\QRCode\QRCode($o))->render($uri);
    }

    /** Prueft einen Code; erlaubt ±1 Zeitschritt (Uhrabweichung). Gibt den Zeitschritt zurueck oder null. */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $now = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) { return null; }
        $now ??= time();
        $step = intdiv($now, 30);
        foreach ([0, -1, 1] as $d) {
            $s = $step + $d;
            if ($lastStep !== null && $s <= $lastStep) { continue; }          // Wiederverwendung verhindern
            if (hash_equals(self::code($secret, $s), $code)) { return $s; }
        }
        return null;
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $msg = pack('N2', 0, $step);
        $h = hash_hmac('sha1', $msg, $key, true);
        $off = ord($h[19]) & 0x0F;
        $bin = ((ord($h[$off]) & 0x7F) << 24) | (ord($h[$off + 1]) << 16) | (ord($h[$off + 2]) << 8) | ord($h[$off + 3]);
        return str_pad((string) ($bin % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** 8 Backup-Codes (Klartext) und ihre Hashes. */
    public static function backupCodes(): array
    {
        $klar = []; $hashes = [];
        for ($i = 0; $i < 8; $i++) {
            $c = strtolower(substr(self::base32Encode(random_bytes(5)), 0, 8));
            $c = substr($c, 0, 4) . '-' . substr($c, 4, 4);
            $klar[] = $c; $hashes[] = password_hash($c, PASSWORD_DEFAULT);
        }
        return ['klar' => $klar, 'hashes' => $hashes];
    }

    /** Prueft einen Backup-Code; gibt die verbleibenden Hashes zurueck oder null. */
    public static function verifyBackup(string $code, array $hashes): ?array
    {
        $code = strtolower(trim($code));
        foreach ($hashes as $i => $h) {
            if (password_verify($code, (string) $h)) { unset($hashes[$i]); return array_values($hashes); }
        }
        return null;
    }

    public static function base32Encode(string $bin): string
    {
        $bits = ''; foreach (str_split($bin) as $c) { $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); }
        $out = ''; foreach (str_split($bits, 5) as $chunk) { $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))]; }
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $s) ?? '');
        $bits = ''; foreach (str_split($s) as $c) { $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT); }
        $out = ''; foreach (str_split($bits, 8) as $b) { if (strlen($b) === 8) { $out .= chr(bindec($b)); } }
        return $out;
    }
}
