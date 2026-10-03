<?php
/**
 * Antworten, Routing und Eingabepruefung fuer die API.
 *
 * Ein einziger Einstiegspunkt (public/api/index.php) statt vieler
 * Einzeldateien. Dadurch laeuft jede Anfrage zwangslaeufig durch
 * Authentifizierung und Rechtepruefung — genau das hat im alten
 * Stand gefehlt.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Response
{
    /** Setzt die CORS-Kopfzeilen. Nur die eigene Domain, nie '*'. */
    public static function cors(array $allowedOrigins): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
        header('Access-Control-Max-Age: 86400');
    }

    /** Sicherheitskopfzeilen für jede Antwort. */
    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), payment=()');
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data = null, array $meta = []): never
    {
        $body = ['ok' => true];
        if ($data !== null) {
            $body['data'] = $data;
        }
        if ($meta !== []) {
            $body['meta'] = $meta;
        }
        self::json($body);
    }

    public static function error(string $message, int $status = 400, array $fields = []): never
    {
        $body = ['ok' => false, 'fehler' => $message];
        if ($fields !== []) {
            $body['felder'] = $fields;
        }
        self::json($body, $status);
    }
}

// ---------------------------------------------------------------------

final class Router
{
    /** @var array<string, array{0: callable|string, 1: array}> */
    private array $routes = [];

    /**
     * Route registrieren.
     *
     * @param string   $method  GET, POST, PATCH, DELETE
     * @param string   $path    z. B. 'einsaetze/{id}'
     * @param callable $handler
     * @param array    $roles   erlaubte Rollen; leer = nur angemeldet
     */
    public function add(string $method, string $path, callable $handler, array $roles = []): void
    {
        $this->routes[strtoupper($method) . ' ' . trim($path, '/')] = [$handler, $roles];
    }

    public function get(string $p, callable $h, array $r = []): void    { $this->add('GET', $p, $h, $r); }
    public function post(string $p, callable $h, array $r = []): void   { $this->add('POST', $p, $h, $r); }
    public function patch(string $p, callable $h, array $r = []): void  { $this->add('PATCH', $p, $h, $r); }
    public function delete(string $p, callable $h, array $r = []): void { $this->add('DELETE', $p, $h, $r); }

    /** Routen ohne Anmeldung (Login, Kontaktformular). */
    private array $public = [];

    public function open(string $method, string $path, callable $handler): void
    {
        $key = strtoupper($method) . ' ' . trim($path, '/');
        $this->routes[$key] = [$handler, []];
        $this->public[$key] = true;
    }

    public function dispatch(string $method, string $path): never
    {
        $method = strtoupper($method);
        $path   = trim($path, '/');

        foreach ($this->routes as $key => [$handler, $roles]) {
            [$routeMethod, $routePath] = explode(' ', $key, 2);
            if ($routeMethod !== $method) {
                continue;
            }
            $params = self::match($routePath, $path);
            if ($params === null) {
                continue;
            }

            if (!isset($this->public[$key])) {
                Auth::require($roles);
            }
            $result = $handler(new Request($params));
            Response::ok($result);
        }

        Response::error('Diese Adresse gibt es nicht.', 404);
    }

    /** Vergleicht Muster mit Pfad und liefert die Platzhalterwerte. */
    private static function match(string $pattern, string $path): ?array
    {
        $pParts = $pattern === '' ? [] : explode('/', $pattern);
        $aParts = $path === '' ? [] : explode('/', $path);
        if (count($pParts) !== count($aParts)) {
            return null;
        }
        $params = [];
        foreach ($pParts as $i => $part) {
            if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                $params[trim($part, '{}')] = $aParts[$i];
                continue;
            }
            if ($part !== $aParts[$i]) {
                return null;
            }
        }
        return $params;
    }
}

// ---------------------------------------------------------------------

/**
 * Eingaben aus der Anfrage. Jeder Zugriff geht ueber eine Methode
 * mit Typpruefung — nie direkt aus $_POST oder $_GET.
 */
final class Request
{
    private array $body;

    public function __construct(private array $params = [])
    {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $this->body = is_array($decoded) ? $decoded : $_POST;
    }

    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    public function paramInt(string $name): int
    {
        $v = $this->params[$name] ?? '';
        if (preg_match('/^\d+$/', (string) $v) !== 1) {
            Response::error('Ungültige ID in der Adresse.', 400);
        }
        return (int) $v;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        $v = $_GET[$name] ?? null;
        return is_string($v) ? $v : $default;
    }

    public function queryInt(string $name, int $default = 0): int
    {
        $v = $_GET[$name] ?? null;
        return is_string($v) && preg_match('/^-?\d+$/', $v) === 1 ? (int) $v : $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    public function text(string $key, int $max = 255, bool $required = false): ?string
    {
        $v = $this->body[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                Response::error('Pflichtfeld fehlt.', 422, [$key => 'Bitte ausfüllen.']);
            }
            return null;
        }
        if (!is_string($v)) {
            Response::error('Ungültiger Wert.', 422, [$key => 'Text erwartet.']);
        }
        $v = trim($v);
        if (mb_strlen($v) > $max) {
            Response::error('Eingabe zu lang.', 422, [$key => "Höchstens {$max} Zeichen."]);
        }
        return $v;
    }

    public function int(string $key, bool $required = false, ?int $min = null, ?int $max = null): ?int
    {
        $v = $this->body[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                Response::error('Pflichtfeld fehlt.', 422, [$key => 'Bitte ausfüllen.']);
            }
            return null;
        }
        if (!is_numeric($v) || (int) $v != $v) {
            Response::error('Ungültige Zahl.', 422, [$key => 'Ganze Zahl erwartet.']);
        }
        $i = (int) $v;
        if ($min !== null && $i < $min) {
            Response::error('Wert zu klein.', 422, [$key => "Mindestens {$min}."]);
        }
        if ($max !== null && $i > $max) {
            Response::error('Wert zu groß.', 422, [$key => "Höchstens {$max}."]);
        }
        return $i;
    }

    public function decimal(string $key, bool $required = false): ?float
    {
        $v = $this->body[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                Response::error('Pflichtfeld fehlt.', 422, [$key => 'Bitte ausfüllen.']);
            }
            return null;
        }
        // Deutsches Komma zulassen
        if (is_string($v)) {
            $v = str_replace(',', '.', $v);
        }
        if (!is_numeric($v)) {
            Response::error('Ungültiger Betrag.', 422, [$key => 'Zahl erwartet.']);
        }
        return (float) $v;
    }

    public function bool(string $key): bool
    {
        $v = $this->body[$key] ?? false;
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public function date(string $key, bool $required = false): ?string
    {
        $v = $this->text($key, 10, $required);
        if ($v === null) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            Response::error('Ungültiges Datum.', 422, [$key => 'Format JJJJ-MM-TT erwartet.']);
        }
        return $v;
    }

    public function time(string $key, bool $required = false): ?string
    {
        $v = $this->text($key, 8, $required);
        if ($v === null) {
            return null;
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v) !== 1) {
            Response::error('Ungültige Uhrzeit.', 422, [$key => 'Format HH:MM erwartet.']);
        }
        return mb_strlen($v) === 5 ? $v . ':00' : $v;
    }

    public function email(string $key, bool $required = false): ?string
    {
        $v = $this->text($key, 150, $required);
        if ($v === null) {
            return null;
        }
        if (filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            Response::error('Ungültige E-Mail-Adresse.', 422, [$key => 'Bitte prüfen.']);
        }
        return mb_strtolower($v);
    }

    /** Wert aus einer festen Liste, z. B. Status oder Typ. */
    public function enum(string $key, array $allowed, bool $required = false): ?string
    {
        $v = $this->text($key, 40, $required);
        if ($v === null) {
            return null;
        }
        if (!in_array($v, $allowed, true)) {
            Response::error('Ungültige Auswahl.', 422, [$key => 'Nicht erlaubter Wert.']);
        }
        return $v;
    }
}
