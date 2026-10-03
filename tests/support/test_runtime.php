<?php
/** Isolierte Testdoubles. Niemals im Webroot ablegen. */
declare(strict_types=1);
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }

// Nur fuer die reduzierte Testumgebung. Produktiv ist mbstring erforderlich.
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null, ?string $encoding = null): string {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? substr($s, $start, $length) : implode('', array_slice($chars, $start, $length));
    }
    function mb_strlen(string $s, ?string $encoding = null): int { return preg_match_all('/./us', $s); }
    function mb_strtolower(string $s, ?string $encoding = null): string { return strtolower($s); }
}

final class TestStatement extends PDOStatement
{
    private array $rows = [];
    private int $affected = 0;
    public function __construct(private TestPdo $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        $this->db->calls[] = [$this->sql, $params ?? []];
        [$this->rows, $this->affected] = ($this->db->handler)($this->sql, $params ?? []);
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function rowCount(): int { return $this->affected; }
}
final class TestPdo extends PDO
{
    public array $calls = [];
    public bool $transaction = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    public Closure $handler;
    public function __construct() { $this->handler = static fn($sql, $params) => [[], 1]; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new TestStatement($this, $query); }
    public function lastInsertId(?string $name = null): string|false { return '101'; }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; $this->commits++; return true; }
    public function rollBack(): bool {
        if (!$this->transaction) { throw new PDOException('No active transaction'); }
        $this->transaction = false; $this->rollbacks++; return true;
    }
    public function inTransaction(): bool { return $this->transaction; }
}
final class AuthFixture
{
    public TestPdo $pdo;
    public App\Database $db;
    public array $user;
    public array $provider;
    public bool $locked = false;
    public bool $cancelled = false;
    public bool $employeeInactive = false;
    public bool $tokenTenantMismatch = false;
    public bool $tokenValid = true;
    public bool $accountActive = true;
    public int $cas = 1;
    public int $failureWrites = 0;
    public int $tokenWrites = 0;
    public string $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    public function __construct() {
        $this->pdo = new TestPdo();
        $rc = new ReflectionClass(App\Database::class);
        $this->db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($this->db, $this->pdo);
        $rc->getProperty('instance')->setValue(null, $this->db);
        $this->user = ['id'=>1,'betrieb_id'=>7,'benutzername'=>'test-owner','rolle'=>'inhaber','aktiv'=>1,'betrieb_status'=>'aktiv',
            'gesperrt_bis'=>null,'fehlversuche'=>0,'totp_aktiv'=>1,'totp_secret_enc'=>App\Crypto::encrypt($this->secret),
            'totp_letzter_schritt'=>null,'totp_backup'=>'[]','mitarbeiter_id'=>11,'vorname'=>'Test','nachname'=>'Person',
            'passwort_hash'=>password_hash('Test-only-password', PASSWORD_BCRYPT, ['cost'=>4]),
            'pin_hash'=>password_hash('4826', PASSWORD_BCRYPT, ['cost'=>4])];
        $this->provider = $this->user;
        $this->provider['id'] = 20;
        $this->provider['benutzername'] = 'test-provider';
        $this->pdo->handler = function(string $sql, array $params): array {
            $s = preg_replace('/\s+/', ' ', $sql);
            if (str_starts_with($s, 'SELECT')) {
                if (str_contains($s, 'AS gesperrt')) { return [$this->accountActive ? [['gesperrt'=>$this->locked ? 1 : 0]] : [], 0]; }
                if (str_contains($s, 'SELECT gesperrt_bis')) { return [$this->locked ? [['gesperrt_bis'=>date('Y-m-d H:i:s',time()+900)]] : [], 0]; }
                if (str_contains($s, 'FROM anbieter_benutzer')) { return [$this->accountActive ? [$this->provider] : [], 0]; }
                if (str_contains($s, 'FROM api_tokens')) {
                    if (!$this->tokenValid || !$this->accountActive) { return [[],0]; }
                    if ($this->tokenTenantMismatch && str_contains($s,'b.betrieb_id = t.betrieb_id')) { return [[],0]; }
                    if ($this->cancelled && str_contains($s,"be.status <> 'gekuendigt'")) { return [[],0]; }
                    if ($this->employeeInactive && str_contains($s,'OR m.aktiv = 1')) { return [[],0]; }
                    return [[$this->user + ['token_id'=>91]],0];
                }
                if (str_contains($s, 'FROM benutzer')) {
                    if (!$this->accountActive) { return [[],0]; }
                    if ($this->cancelled && str_contains($s,"be.status <> 'gekuendigt'")) { return [[],0]; }
                    if ($this->employeeInactive && str_contains($s,'OR m.aktiv = 1')) { return [[],0]; }
                    return [[$this->user],0];
                }
                return [[],0];
            }
            if (str_starts_with($s,'UPDATE') && str_contains($s,'fehlversuche = fehlversuche + 1')) { $this->failureWrites++; }
            if (str_starts_with($s,'INSERT INTO api_tokens')) { $this->tokenWrites++; }
            if (str_starts_with($s,'UPDATE') && (str_contains($s,'SET totp_letzter_schritt') || str_contains($s,'SET totp_backup'))) { return [[], $this->cas]; }
            return [[],1];
        };
    }
    public function pending(bool $provider = false): void {
        App\Auth::startSession();
        $_SESSION[$provider ? 'zf_anbieter_id' : 'zf_benutzer_id'] = $provider ? 20 : 1;
        $_SESSION['zf_seit'] = time();
    }
    public function otp(): string { return App\Totp::code($this->secret, intdiv(time(),30)); }
}
