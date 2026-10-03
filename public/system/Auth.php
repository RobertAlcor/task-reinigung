<?php
/**
 * Authentifizierung und Sitzungsverwaltung.
 *
 * Zwei Wege:
 *   - Session (Cookie) fuer Buero-Oberflaeche und Kundenportal
 *   - Bearer-Token fuer die Mitarbeiter-App (PWA, funktioniert offline
 *     und schickt das Token beim Synchronisieren mit)
 *
 * In beiden Faellen wird die betrieb_id aus dem geprueften Benutzer
 * gelesen und in Database gesetzt. Sie kommt nie aus der Anfrage.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Auth
{
    private static ?array $user = null;
    private static array $config = [];
    private const DUMMY_HASH = '$2y$12$7l4EjGxhkUbNsJbIGmdabOIKaVSEzrmXjZdPWiXp9qZwTrYrcLaPC';

    public static function init(array $securityConfig): void
    {
        self::$config = $securityConfig;
    }

    // ---------------------------------------------------------------
    // Sitzung starten
    // ---------------------------------------------------------------

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('rvsid');
        if (!session_start()) {
            throw new RuntimeException('Sitzung konnte nicht gestartet werden.');
        }
        $maxAge = max(1, (int) (self::$config['session_lifetime'] ?? 7200));
        if (isset($_SESSION['last_seen']) && (time() - (int) $_SESSION['last_seen']) > $maxAge) {
            // Eine neue anonyme Sitzung muss ihren CSRF-Token speichern koennen.
            $_SESSION = [];
            self::$user = null;
            Database::get()->clearTenant();
            session_regenerate_id(true);
        }
        $_SESSION['last_seen'] = time();
    }

    // ---------------------------------------------------------------
    // Login: Büro und Kundenportal (Benutzername + Passwort)
    // ---------------------------------------------------------------

    public static function loginWithPassword(string $username, string $password): array
    {
        $db = Database::get();
        $username = trim($username);

        if (self::isLockedOut($username)) {
            self::logAttempt($username, false);
            throw new AuthException('Zu viele Fehlversuche. Bitte in ' . (int) self::$config['lockout_minutes'] . ' Minuten erneut versuchen.');
        }

        $user = $db->rawOne(
            "SELECT b.*, be.status AS betrieb_status
               FROM benutzer b
               JOIN betriebe be ON be.id = b.betrieb_id
              WHERE b.benutzername = ? AND b.rolle IN ('inhaber','office','kunde') AND b.aktiv = 1
              LIMIT 1",
            [$username]
        );

        // Immer hashen, auch wenn kein Benutzer gefunden wurde — sonst
        // verraet die Antwortzeit, ob es den Benutzernamen gibt.
        $hash = $user['passwort_hash'] ?? self::DUMMY_HASH;
        $ok = password_verify($password, $hash);

        if (!$ok || $user === null) {
            self::logAttempt($username, false);
            self::countFailure($user);
            throw new AuthException('Benutzername oder Passwort stimmt nicht.');
        }
        if ($user['betrieb_status'] === 'gekuendigt') {
            throw new AuthException('Dieser Zugang ist nicht mehr aktiv.');
        }

        self::newLoginSession();
        if ((int) ($user['totp_aktiv'] ?? 0) === 1) {
            // Zweiter Faktor ausstehend: noch keine vollstaendige Sitzung
            $_SESSION['zf_benutzer_id'] = (int) $user['id'];
            $_SESSION['zf_seit'] = time();
            unset($_SESSION['benutzer_id'], $_SESSION['betrieb_id'], $_SESSION['rolle']);
            return $user + ['zweiter_faktor' => true];
        }
        self::afterLogin($user);
        $_SESSION['benutzer_id'] = (int) $user['id'];
        $_SESSION['betrieb_id']  = (int) $user['betrieb_id'];
        $_SESSION['rolle']       = $user['rolle'];
        $_SESSION['last_seen']   = time();

        return $user;
    }

    /** Wartet ein Buero-Login auf den zweiten Faktor? Liefert den Benutzer oder null. */
    public static function pendingSecondFactor(): ?array
    {
        self::startSession();
        if (empty($_SESSION['zf_benutzer_id']) || (time() - (int) ($_SESSION['zf_seit'] ?? 0)) > 300) {
            unset($_SESSION['zf_benutzer_id'], $_SESSION['zf_seit']);
            return null;
        }
        $user = self::tenantUser((int) $_SESSION['zf_benutzer_id']);
        if ($user === null || (int) ($user['totp_aktiv'] ?? 0) !== 1) {
            unset($_SESSION['zf_benutzer_id'], $_SESSION['zf_seit']);
            return null;
        }
        return $user;
    }

    /** Zweiten Faktor pruefen (App-Code oder Backup-Code) und Sitzung vervollstaendigen. */
    public static function completeSecondFactor(string $code): array
    {
        $user = self::pendingSecondFactor();
        if ($user === null) { throw new AuthException('Bitte erneut anmelden.'); }
        self::ensureUnlocked($user, 'benutzer');
        if (!self::consumeSecondFactor($user, $code, 'benutzer')) {
            self::logAttempt('2fa:' . $user['benutzername'], false);
            self::countFailure($user);
            throw new AuthException('Der Code stimmt nicht oder wurde bereits verwendet.');
        }
        unset($_SESSION['zf_benutzer_id'], $_SESSION['zf_anbieter_id'], $_SESSION['zf_seit'], $_SESSION['anbieter_id'], $_SESSION['totp_setup_secret']);
        session_regenerate_id(true);
        self::$user = null;
        Database::get()->clearTenant();
        self::afterLogin($user);
        $_SESSION['benutzer_id'] = (int) $user['id'];
        $_SESSION['betrieb_id']  = (int) $user['betrieb_id'];
        $_SESSION['rolle']       = $user['rolle'];
        $_SESSION['last_seen']   = time();
        return $user;
    }

    /** Zweiten Faktor fuer einen Anbieter pruefen. */
    public static function completeProviderSecondFactor(string $code): array
    {
        self::startSession();
        $id = (int) ($_SESSION['zf_anbieter_id'] ?? 0);
        if ($id < 1 || (time() - (int) ($_SESSION['zf_seit'] ?? 0)) > 300) {
            unset($_SESSION['zf_anbieter_id'], $_SESSION['zf_seit']);
            throw new AuthException('Bitte erneut anmelden.');
        }
        $db = Database::get();
        $user = $db->rawOne('SELECT * FROM anbieter_benutzer WHERE id = ? AND aktiv = 1', [$id]);
        if ($user === null || (int) ($user['totp_aktiv'] ?? 0) !== 1) {
            unset($_SESSION['zf_anbieter_id'], $_SESSION['zf_seit']);
            throw new AuthException('Bitte erneut anmelden.');
        }
        self::ensureUnlocked($user, 'anbieter_benutzer');
        if (!self::consumeSecondFactor($user, $code, 'anbieter_benutzer')) {
            self::logAttempt('2fa-anbieter:' . $user['benutzername'], false);
            $db->raw('UPDATE anbieter_benutzer SET gesperrt_bis = IF(fehlversuche + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), gesperrt_bis), fehlversuche = fehlversuche + 1 WHERE id = ?',
                [(int) (self::$config['max_login_tries'] ?? 5), (int) (self::$config['lockout_minutes'] ?? 15), $id]);
            throw new AuthException('Der Code stimmt nicht oder wurde bereits verwendet.');
        }
        unset($_SESSION['zf_anbieter_id'], $_SESSION['zf_benutzer_id'], $_SESSION['zf_seit'], $_SESSION['totp_setup_secret']);
        session_regenerate_id(true);
        self::$user = null;
        $db->clearTenant();
        $db->raw('UPDATE anbieter_benutzer SET letzter_login = NOW(), fehlversuche = 0, gesperrt_bis = NULL WHERE id = ?', [$id]);
        $_SESSION['anbieter_id'] = $id;
        $_SESSION['last_seen'] = time();
        unset($_SESSION['benutzer_id'], $_SESSION['betrieb_id'], $_SESSION['rolle']);
        self::logAttempt('2fa-anbieter:' . $user['benutzername'], true);
        return $user;
    }

    /** App-Token fuer einen Mitarbeiter-Benutzer ohne PIN (Direktzugang des Superusers). */
    public static function appTokenOhnePin(array $user): string
    {
        $user = self::tenantUser((int) ($user['id'] ?? 0));
        if ($user === null || $user['rolle'] !== 'mitarbeiter') {
            throw new AuthException('Dieser Zugang ist nicht mehr aktiv.');
        }
        self::ensureUnlocked($user, 'benutzer');
        $db = Database::get();
        $token = bin2hex(random_bytes(32));
        $db->raw('INSERT INTO api_tokens (betrieb_id, benutzer_id, token_hash, geraet, laeuft_ab) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))',
            [(int) $user['betrieb_id'], (int) $user['id'], Crypto::hashToken($token), 'Direktzugang Anbieter', ]);
        return $token;
    }

    /** Anbieter-Sitzung ohne Passwort (persoenlicher QR-Login). Zwei-Faktor bleibt bestehen. */
    public static function loginProviderOhnePasswort(array $user): array
    {
        $db = Database::get();
        $user = $db->rawOne('SELECT * FROM anbieter_benutzer WHERE id = ? AND aktiv = 1', [(int) ($user['id'] ?? 0)]);
        if ($user === null) { throw new AuthException('Dieser Zugang ist nicht mehr aktiv.'); }
        self::ensureUnlocked($user, 'anbieter_benutzer');
        self::newLoginSession();
        if ((int) ($user['totp_aktiv'] ?? 0) === 1) {
            $_SESSION['zf_anbieter_id'] = (int) $user['id'];
            $_SESSION['zf_seit'] = time();
            unset($_SESSION['anbieter_id'], $_SESSION['benutzer_id'], $_SESSION['betrieb_id'], $_SESSION['rolle']);
            return $user + ['zweiter_faktor' => true];
        }
        $db->raw('UPDATE anbieter_benutzer SET letzter_login = NOW(), fehlversuche = 0, gesperrt_bis = NULL WHERE id = ?', [(int) $user['id']]);
        self::logAttempt('anbieter-qr:' . $user['benutzername'], true);
        $_SESSION['anbieter_id'] = (int) $user['id'];
        $_SESSION['last_seen'] = time();
        unset($_SESSION['benutzer_id'], $_SESSION['betrieb_id'], $_SESSION['rolle']);
        return $user;
    }

    public static function pendingProviderSecondFactor(): bool
    {
        self::startSession();
        return !empty($_SESSION['zf_anbieter_id']) && (time() - (int) ($_SESSION['zf_seit'] ?? 0)) <= 300;
    }

    // ---------------------------------------------------------------
    // Login: Mitarbeiter-App (Personalnummer + PIN) → Token
    // ---------------------------------------------------------------

    public static function loginWithPin(string $personnelNumber, string $pin, string $device = ''): array
    {
        $db = Database::get();
        $personnelNumber = trim($personnelNumber);

        if (self::isLockedOut($personnelNumber)) {
            self::logAttempt($personnelNumber, false);
            throw new AuthException('Zu viele Fehlversuche. Bitte in ' . (int) self::$config['lockout_minutes'] . ' Minuten erneut versuchen.');
        }

        $user = $db->rawOne(
            "SELECT b.*, m.vorname, m.nachname, be.status AS betrieb_status
               FROM benutzer b
               JOIN mitarbeiter m ON m.id = b.mitarbeiter_id AND m.betrieb_id = b.betrieb_id
               JOIN betriebe be   ON be.id = b.betrieb_id
              WHERE b.benutzername = ? AND b.rolle = 'mitarbeiter' AND b.aktiv = 1 AND m.aktiv = 1
              LIMIT 1",
            [$personnelNumber]
        );

        $hash = $user['pin_hash'] ?? self::DUMMY_HASH;
        $ok = password_verify($pin, $hash);

        if (!$ok || $user === null) {
            self::logAttempt($personnelNumber, false);
            self::countFailure($user);
            throw new AuthException('Personalnummer oder PIN stimmt nicht.');
        }
        if ($user['betrieb_status'] === 'gekuendigt') {
            throw new AuthException('Dieser Zugang ist nicht mehr aktiv.');
        }

        self::afterLogin($user);

        // Token erzeugen; gespeichert wird nur der Hash
        $token = bin2hex(random_bytes(32));
        $db->raw(
            'INSERT INTO api_tokens (betrieb_id, benutzer_id, token_hash, geraet, laeuft_ab)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
            [
                (int) $user['betrieb_id'],
                (int) $user['id'],
                Crypto::hashToken($token),
                mb_substr($device, 0, 150),
                (int) (self::$config['token_lifetime'] ?? 2592000),
            ]
        );

        return [
            'token'      => $token,
            'benutzer'   => [
                'id'             => (int) $user['id'],
                'mitarbeiter_id' => (int) $user['mitarbeiter_id'],
                'name'           => $user['vorname'] . ' ' . $user['nachname'],
                'rolle'          => 'mitarbeiter',
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Anbieter (Softwarebetreiber) — eigene Session, kein Mandant
    // ---------------------------------------------------------------

    public static function loginProvider(string $username, string $password): array
    {
        $db = Database::get();
        $username = trim($username);

        $user = $db->rawOne(
            'SELECT * FROM anbieter_benutzer WHERE benutzername = ? AND aktiv = 1 LIMIT 1',
            [$username]
        );
        if ($user !== null) { self::ensureUnlocked($user, 'anbieter_benutzer'); }

        $hash = $user['passwort_hash'] ?? self::DUMMY_HASH;
        if (!password_verify($password, $hash) || $user === null) {
            self::logAttempt('anbieter:' . $username, false);
            if ($user !== null) {
                $max = (int) (self::$config['max_login_tries'] ?? 5);
                $min = (int) (self::$config['lockout_minutes'] ?? 15);
                $db->raw(
                    'UPDATE anbieter_benutzer
                        SET gesperrt_bis = IF(fehlversuche + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), gesperrt_bis),
                            fehlversuche = fehlversuche + 1
                      WHERE id = ?',
                    [$max, $min, (int) $user['id']]
                );
            }
            throw new AuthException('Benutzername oder Passwort stimmt nicht.');
        }

        self::logAttempt('anbieter:' . $username, true);

        self::newLoginSession();
        if ((int) ($user['totp_aktiv'] ?? 0) === 1) {
            $_SESSION['zf_anbieter_id'] = (int) $user['id'];
            $_SESSION['zf_seit'] = time();
            unset($_SESSION['anbieter_id'], $_SESSION['benutzer_id'], $_SESSION['betrieb_id'], $_SESSION['rolle']);
            return $user + ['zweiter_faktor' => true];
        }
        $db->raw('UPDATE anbieter_benutzer SET letzter_login = NOW(), fehlversuche = 0, gesperrt_bis = NULL WHERE id = ?', [(int) $user['id']]);
        $_SESSION['anbieter_id'] = (int) $user['id'];
        $_SESSION['last_seen']   = time();
        // Eine Anbieter-Sitzung ist nie gleichzeitig eine Mandanten-Sitzung
        unset($_SESSION['benutzer_id'], $_SESSION['betrieb_id'], $_SESSION['rolle']);

        return $user;
    }

    /** Liefert den angemeldeten Anbieter oder null. Setzt KEINEN Mandanten. */
    public static function provider(): ?array
    {
        self::startSession();
        if (empty($_SESSION['anbieter_id'])) {
            return null;
        }
        return Database::get()->rawOne(
            'SELECT * FROM anbieter_benutzer WHERE id = ? AND aktiv = 1 LIMIT 1',
            [(int) $_SESSION['anbieter_id']]
        );
    }

    public static function requireProvider(): array
    {
        $p = self::provider();
        if ($p === null) {
            throw new AuthException('Nicht angemeldet.', 401);
        }
        return $p;
    }

    // ---------------------------------------------------------------
    // Anfrage authentifizieren
    // ---------------------------------------------------------------

    /**
     * Prueft die aktuelle Anfrage: erst Bearer-Token, dann Session.
     * Setzt bei Erfolg den Mandanten in Database.
     */
    public static function authenticate(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $db = Database::get();
        $db->clearTenant();
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token = self::bearerToken();
        // Ein fehlerhafter Authorization-Header darf nicht auf Cookies zurueckfallen.
        if ($header !== '') {
            $user = $token !== null ? self::userFromToken($token) : null;
            if ($user !== null) {
                self::$user = $user;
                $db->setTenant((int) $user['betrieb_id']);
            }
            return $user;
        }
        self::startSession();
        if (empty($_SESSION['benutzer_id'])) {
            return null;
        }
        $user = self::tenantUser((int) $_SESSION['benutzer_id']);
        if ($user === null) {
            self::logout();
            return null;
        }
        self::$user = $user;
        $db->setTenant((int) $user['betrieb_id']);
        return $user;
    }

    /** Erzwingt eine Anmeldung; optional mit Rollenpruefung. */
    public static function require(array $roles = []): array
    {
        $user = self::authenticate();
        if ($user === null) {
            throw new AuthException('Nicht angemeldet.', 401);
        }
        if ($roles !== [] && !in_array($user['rolle'], $roles, true)) {
            throw new AuthException('Für diesen Bereich fehlt die Berechtigung.', 403);
        }
        return $user;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function userId(): int
    {
        return (int) (self::$user['id'] ?? 0);
    }

    public static function role(): string
    {
        return (string) (self::$user['rolle'] ?? '');
    }

    public static function logout(): void
    {
        // Sitzung erst laden, sonst gibt es nichts zu zerstoeren
        if (self::bearerToken() === null) {
            self::startSession();
        }
        $token = self::bearerToken();
        if ($token !== null) {
            Database::get()->raw(
                'UPDATE api_tokens SET widerrufen = 1 WHERE token_hash = ?',
                [Crypto::hashToken($token)]
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
        self::$user = null;
        Database::get()->clearTenant();
    }

    // ---------------------------------------------------------------
    // CSRF-Schutz für formularbasierte Bereiche
    // ---------------------------------------------------------------

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): void
    {
        self::startSession();
        if (empty($_SESSION['csrf']) || $token === null || !hash_equals($_SESSION['csrf'], $token)) {
            throw new AuthException('Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden.', 419);
        }
    }

    // ---------------------------------------------------------------
    // Intern
    // ---------------------------------------------------------------


    /** Nur nach erfolgreichem require() als CSRF-Ausnahme verwenden. */
    public static function usesBearerToken(): bool
    {
        return self::bearerToken() !== null;
    }

    private static function newLoginSession(): void
    {
        self::startSession();
        $_SESSION = [];
        self::$user = null;
        Database::get()->clearTenant();
        session_regenerate_id(true);
        $_SESSION['last_seen'] = time();
    }

    private static function tenantUser(int $id): ?array
    {
        return Database::get()->rawOne(
            "SELECT b.*, be.status AS betrieb_status
               FROM benutzer b
               JOIN betriebe be ON be.id = b.betrieb_id
               LEFT JOIN mitarbeiter m ON m.id = b.mitarbeiter_id AND m.betrieb_id = b.betrieb_id
              WHERE b.id = ? AND b.aktiv = 1 AND be.status <> 'gekuendigt'
                AND (b.rolle <> 'mitarbeiter' OR m.aktiv = 1)
              LIMIT 1", [$id]
        );
    }

    private static function ensureUnlocked(array $user, string $table): void
    {
        if (!in_array($table, ['benutzer', 'anbieter_benutzer'], true)) {
            throw new RuntimeException('Ungueltiger Kontotyp.');
        }
        // Zeitvergleich in der Datenbank, nicht zwischen verschiedenen Zeitzonen.
        $row = Database::get()->rawOne(
            "SELECT (gesperrt_bis IS NOT NULL AND gesperrt_bis > NOW()) AS gesperrt
               FROM {$table} WHERE id = ? AND aktiv = 1", [(int) $user['id']]
        );
        if ($row === null) { throw new AuthException('Dieser Zugang ist nicht mehr aktiv.'); }
        if ((int) $row['gesperrt'] === 1) {
            throw new AuthException('Zu viele Fehlversuche. Bitte in ' . (int) (self::$config['lockout_minutes'] ?? 15) . ' Minuten erneut versuchen.');
        }
    }

    /** Bedingtes UPDATE verhindert die doppelte Annahme desselben Codes. */
    private static function consumeSecondFactor(array $user, string $code, string $table): bool
    {
        if (!in_array($table, ['benutzer', 'anbieter_benutzer'], true)) {
            throw new RuntimeException('Ungueltiger Kontotyp.');
        }
        $code = trim($code);
        if ($code === '' || strlen($code) > 32 || (int) ($user['totp_aktiv'] ?? 0) !== 1) { return false; }
        $db = Database::get();
        $cipher = $user['totp_secret_enc'] ?? null;
        $secret = Crypto::decrypt($cipher);
        $lastStep = isset($user['totp_letzter_schritt']) ? (int) $user['totp_letzter_schritt'] : null;
        $step = $secret !== null ? Totp::verify($secret, $code, $lastStep) : null;
        if ($step !== null) {
            return $db->raw(
                "UPDATE {$table} SET totp_letzter_schritt = ?
                  WHERE id = ? AND aktiv = 1 AND totp_aktiv = 1 AND totp_secret_enc = ?
                    AND (gesperrt_bis IS NULL OR gesperrt_bis <= NOW())
                    AND (totp_letzter_schritt IS NULL OR totp_letzter_schritt < ?)",
                [$step, (int) $user['id'], $cipher, $step]
            )->rowCount() === 1;
        }
        $previous = (string) ($user['totp_backup'] ?? '');
        $hashes = json_decode($previous, true);
        if (!is_array($hashes)) { return false; }
        $remaining = Totp::verifyBackup($code, $hashes);
        if ($remaining === null) { return false; }
        return $db->raw(
            "UPDATE {$table} SET totp_backup = ?
              WHERE id = ? AND aktiv = 1 AND totp_aktiv = 1 AND totp_secret_enc = ?
                AND (gesperrt_bis IS NULL OR gesperrt_bis <= NOW())
                AND BINARY totp_backup = ?",
            [json_encode($remaining, JSON_THROW_ON_ERROR), (int) $user['id'], $cipher, $previous]
        )->rowCount() === 1;
    }

    private static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (is_string($header) && preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private static function userFromToken(string $token): ?array
    {
        $db = Database::get();
        $row = $db->rawOne(
            "SELECT b.*, t.id AS token_id
               FROM api_tokens t
               JOIN benutzer b ON b.id = t.benutzer_id AND b.betrieb_id = t.betrieb_id
               JOIN betriebe be ON be.id = b.betrieb_id
               LEFT JOIN mitarbeiter m ON m.id = b.mitarbeiter_id AND m.betrieb_id = b.betrieb_id
              WHERE t.token_hash = ? AND t.widerrufen = 0 AND t.laeuft_ab > NOW() AND b.aktiv = 1
                AND be.status <> 'gekuendigt'
                AND (b.rolle <> 'mitarbeiter' OR m.aktiv = 1)
              LIMIT 1",
            [Crypto::hashToken($token)]
        );
        if ($row === null) { return null; }
        $db->raw('UPDATE api_tokens SET letzter_zugriff = NOW() WHERE id = ?', [(int) $row['token_id']]);
        return $row;
    }

    /**
     * Session fuer einen Inhaber setzen, ohne Passwort — genutzt vom
     * Schnellzugang (QR/Link) des Anbieters. Baut dieselbe Sitzung auf
     * wie loginWithPassword(), inklusive Zwei-Faktor-Weiche, damit ein
     * mit 2FA gesichertes Konto nicht umgangen wird.
     */
    public static function loginOhnePasswort(array $user): array
    {
        $user = self::tenantUser((int) ($user['id'] ?? 0));
        if ($user === null || !in_array($user['rolle'], ['inhaber', 'office', 'kunde'], true)) {
            throw new AuthException('Dieser Zugang ist nicht mehr aktiv.');
        }
        self::ensureUnlocked($user, 'benutzer');
        self::newLoginSession();
        if ((int) ($user['totp_aktiv'] ?? 0) === 1) {
            $_SESSION['zf_benutzer_id'] = (int) $user['id'];
            $_SESSION['zf_seit'] = time();
            unset($_SESSION['benutzer_id'], $_SESSION['betrieb_id'], $_SESSION['rolle']);
            return $user + ['zweiter_faktor' => true];
        }
        self::afterLogin($user);
        $_SESSION['benutzer_id'] = (int) $user['id'];
        $_SESSION['betrieb_id']  = (int) $user['betrieb_id'];
        $_SESSION['rolle']       = $user['rolle'];
        $_SESSION['last_seen']   = time();
        return $user;
    }

    private static function afterLogin(array $user): void
    {
        $db = Database::get();
        $db->raw('UPDATE benutzer SET letzter_login = NOW(), fehlversuche = 0, gesperrt_bis = NULL WHERE id = ?', [(int) $user['id']]);
        self::logAttempt($user['benutzername'], true, (int) $user['betrieb_id']);
    }

    private static function countFailure(?array $user): void
    {
        if ($user === null) {
            return;
        }
        $max = (int) (self::$config['max_login_tries'] ?? 5);
        $min = (int) (self::$config['lockout_minutes'] ?? 15);
        Database::get()->raw(
            'UPDATE benutzer
                SET gesperrt_bis = IF(fehlversuche + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), gesperrt_bis),
                    fehlversuche = fehlversuche + 1
              WHERE id = ?',
            [$max, $min, (int) $user['id']]
        );
    }

    private static function isLockedOut(string $username): bool
    {
        $row = Database::get()->rawOne(
            'SELECT gesperrt_bis FROM benutzer WHERE benutzername = ? AND gesperrt_bis IS NOT NULL AND gesperrt_bis > NOW() LIMIT 1',
            [$username]
        );
        return $row !== null;
    }

    private static function logAttempt(string $username, bool $success, ?int $tenantId = null): void
    {
        Database::get()->raw(
            'INSERT INTO loginversuche (betrieb_id, benutzername, ip_adresse, erfolgreich) VALUES (?, ?, ?, ?)',
            [$tenantId, mb_substr($username, 0, 80), self::clientIp(), $success ? 1 : 0]
        );
    }

    // ---------------------------------------------------------------
    // Zwei-Faktor einrichten / abschalten
    // ---------------------------------------------------------------

    /** Startet die Einrichtung: neues Geheimnis in der Sitzung, gibt Secret und QR zurueck. */
    public static function totpSetupStart(string $konto, string $aussteller): array
    {
        self::startSession();
        $secret = Totp::secret();
        $_SESSION['totp_setup_secret'] = $secret;
        return ['secret' => $secret, 'qr' => Totp::qrSvg(Totp::uri($secret, $konto, $aussteller))];
    }

    /** Bestaetigt die Einrichtung mit einem Code aus der App. Gibt die Backup-Codes zurueck. */
    public static function totpSetupConfirm(string $tabelle, int $id, string $code): array
    {
        self::startSession();
        $secret = (string) ($_SESSION['totp_setup_secret'] ?? '');
        if ($secret === '' || Totp::verify($secret, $code) === null) { throw new AuthException('Der Code stimmt nicht. Bitte Uhrzeit am Handy prüfen und erneut versuchen.'); }
        $backup = Totp::backupCodes();
        $t = $tabelle === 'anbieter_benutzer' ? 'anbieter_benutzer' : 'benutzer';
        Database::get()->raw("UPDATE {$t} SET totp_secret_enc = ?, totp_aktiv = 1, totp_letzter_schritt = NULL, totp_backup = ? WHERE id = ?", [Crypto::encrypt($secret), json_encode($backup['hashes']), $id]);
        unset($_SESSION['totp_setup_secret']);
        return $backup['klar'];
    }

    public static function totpDisable(string $tabelle, int $id): void
    {
        $t = $tabelle === 'anbieter_benutzer' ? 'anbieter_benutzer' : 'benutzer';
        Database::get()->raw("UPDATE {$t} SET totp_secret_enc = NULL, totp_aktiv = 0, totp_letzter_schritt = NULL, totp_backup = NULL WHERE id = ?", [$id]);
    }

    public static function clientIp(): string
    {
        return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }
}

/** Fehler bei Anmeldung oder Berechtigung. Der Code wird als HTTP-Status verwendet. */
final class AuthException extends RuntimeException
{
    public function __construct(string $message, private int $status = 401)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
