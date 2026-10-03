<?php
/**
 * Verschluesselung sensibler Felder (SVNR, IBAN, Alarmcode, Schluesselinfo).
 *
 * Verfahren: AES-256-GCM. Authentifiziert, das heisst manipulierte
 * Daten werden beim Entschluesseln erkannt und nicht stillschweigend
 * falsch ausgegeben.
 *
 * Der Schluessel liegt in der Konfiguration ausserhalb des Webroots,
 * nicht in der Datenbank. Wer die Datenbank bekommt, bekommt die
 * Klartexte damit nicht mit.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;   // GCM: 96 Bit
    private const TAG_LEN = 16;

    private static ?string $key = null;

    public static function init(string $keyBase64): void
    {
        $key = base64_decode($keyBase64, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Verschlüsselungsschlüssel fehlt oder ist ungültig (32 Byte, Base64 erwartet).');
        }
        self::$key = $key;
    }

    /** Erzeugt einen neuen Schluessel. Nur einmalig beim Einrichten verwenden. */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * Verschluesselt einen Text. Ergebnis: IV | Tag | Ciphertext (roh, binär).
     * Wird als VARBINARY gespeichert.
     */
    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        self::requireKey();

        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::$key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );
        if ($cipher === false) {
            throw new RuntimeException('Verschlüsselung fehlgeschlagen.');
        }
        return $iv . $tag . $cipher;
    }

    /** Entschluesselt. Gibt null zurueck, wenn nichts gespeichert war. */
    public static function decrypt(?string $blob): ?string
    {
        if ($blob === null || $blob === '') {
            return null;
        }
        self::requireKey();

        if (strlen($blob) <= self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('Verschlüsselter Wert ist unvollständig.');
        }
        $iv     = substr($blob, 0, self::IV_LEN);
        $tag    = substr($blob, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($blob, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipher, self::CIPHER, self::$key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            // Falscher Schluessel oder manipulierte Daten
            throw new RuntimeException('Entschlüsselung fehlgeschlagen.');
        }
        return $plain;
    }

    /** Zufälliges Token, z. B. für QR-Code am Objekt (32 Hex-Zeichen). */
    public static function token(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Hash zur Ablage von API-Tokens. Tokens werden nie im Klartext gespeichert. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function requireKey(): void
    {
        if (self::$key === null) {
            throw new RuntimeException('Crypto wurde nicht initialisiert.');
        }
    }
}
