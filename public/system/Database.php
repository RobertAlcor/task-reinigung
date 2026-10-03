<?php
/**
 * Datenbankzugriff.
 *
 * Kernpunkt: jede Abfrage laeuft ueber Prepared Statements, und alle
 * mandantenbezogenen Methoden setzen den Filter auf betrieb_id selbst.
 * Die betrieb_id kommt niemals aus der Anfrage, sondern immer aus der
 * geprueften Sitzung.
 */

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    /** Aktueller Mandant. Wird nach dem Login gesetzt. */
    private ?int $tenantId = null;

    private function __construct(array $config)
    {
        // Port und Unix-Socket sind optional; easyname nutzt localhost ohne beides
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s',
            $config['host'], $config['name'], $config['charset']);
        if (!empty($config['port'])) {
            $dsn .= ';port=' . (int) $config['port'];
        }
        if (!empty($config['socket'])) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s',
                $config['socket'], $config['name'], $config['charset']);
        }

        try {
            $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // Strikter Modus, damit stille Datenverluste ausgeschlossen sind
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            $this->pdo->exec("SET SESSION time_zone = '+01:00'");
        } catch (PDOException $e) {
            // Details nie nach aussen geben
            error_log('DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
            throw new RuntimeException('Datenbank nicht erreichbar.');
        }
    }

    public static function init(array $config): void
    {
        self::$instance = new self($config);
    }

    public static function get(): Database
    {
        if (self::$instance === null) {
            throw new RuntimeException('Database wurde nicht initialisiert.');
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    // ---------------------------------------------------------------
    // Mandant
    // ---------------------------------------------------------------

    public function setTenant(int $tenantId): void
    {
        if ($tenantId < 1) {
            throw new RuntimeException('Ungültige Betriebs-ID.');
        }
        $this->tenantId = $tenantId;
    }

    public function tenant(): int
    {
        if ($this->tenantId === null) {
            throw new RuntimeException('Kein Betrieb im Kontext gesetzt.');
        }
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    // ---------------------------------------------------------------
    // Abfragen OHNE Mandantenfilter — nur für Login, Setup, Systemtabellen
    // ---------------------------------------------------------------

    public function raw(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function rawOne(string $sql, array $params = []): ?array
    {
        $row = $this->raw($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function rawAll(string $sql, array $params = []): array
    {
        return $this->raw($sql, $params)->fetchAll();
    }

    // ---------------------------------------------------------------
    // Mandantensichere Abfragen
    // ---------------------------------------------------------------

    /**
     * SELECT mit automatischem Mandantenfilter.
     *
     * $where wird an "WHERE betrieb_id = ? AND (...)" angehaengt.
     * Tabellenname wird gegen eine Positivliste geprueft, damit er nie
     * aus Benutzereingaben stammen kann.
     */
    public function select(string $table, string $where = '', array $params = [], string $suffix = ''): array
    {
        $table = $this->checkTable($table);
        $sql = "SELECT * FROM `{$table}` WHERE betrieb_id = ?";
        if ($where !== '') {
            $sql .= " AND ({$where})";
        }
        if ($suffix !== '') {
            $sql .= ' ' . $suffix;
        }
        return $this->rawAll($sql, array_merge([$this->tenant()], $params));
    }

    public function selectOne(string $table, string $where = '', array $params = []): ?array
    {
        $rows = $this->select($table, $where, $params, 'LIMIT 1');
        return $rows[0] ?? null;
    }

    /** Einzelner Datensatz nach ID, mandantensicher. */
    public function find(string $table, int $id): ?array
    {
        return $this->selectOne($table, 'id = ?', [$id]);
    }

    /** INSERT mit automatisch gesetzter betrieb_id. Gibt die neue ID zurueck. */
    public function insert(string $table, array $data): int
    {
        $table = $this->checkTable($table);
        $data['betrieb_id'] = $this->tenant();

        $columns = array_keys($data);
        $this->checkColumns($columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->raw($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /** UPDATE nach ID, mandantensicher. Gibt die Zahl geaenderter Zeilen zurueck. */
    public function update(string $table, int $id, array $data): int
    {
        $table = $this->checkTable($table);
        unset($data['id'], $data['betrieb_id']);   // nie ueberschreibbar
        if ($data === []) {
            return 0;
        }
        $columns = array_keys($data);
        $this->checkColumns($columns);

        $set = implode(', ', array_map(static fn($c) => "`{$c}` = ?", $columns));
        $sql = "UPDATE `{$table}` SET {$set} WHERE id = ? AND betrieb_id = ?";

        $params = array_values($data);
        $params[] = $id;
        $params[] = $this->tenant();

        return $this->raw($sql, $params)->rowCount();
    }

    /** DELETE nach ID, mandantensicher. Nur fuer Tabellen ohne Nachweispflicht. */
    public function delete(string $table, int $id): int
    {
        $table = $this->checkTable($table);
        $sql = "DELETE FROM `{$table}` WHERE id = ? AND betrieb_id = ?";
        return $this->raw($sql, [$id, $this->tenant()])->rowCount();
    }

    public function count(string $table, string $where = '', array $params = []): int
    {
        $table = $this->checkTable($table);
        $sql = "SELECT COUNT(*) AS n FROM `{$table}` WHERE betrieb_id = ?";
        if ($where !== '') {
            $sql .= " AND ({$where})";
        }
        $row = $this->rawOne($sql, array_merge([$this->tenant()], $params));
        return (int) ($row['n'] ?? 0);
    }

    // ---------------------------------------------------------------
    // Transaktionen
    // ---------------------------------------------------------------

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------
    // Schutz gegen eingeschleuste Tabellen- und Spaltennamen
    // ---------------------------------------------------------------

    /** Alle Tabellen des Schemas, die eine betrieb_id tragen. */
    private const TABLES = [
        'aenderungsanfragen', 'abwesenheiten', 'angebot_positionen', 'angebote',
        'anfragen', 'api_tokens', 'app_nachrichten', 'benachrichtigungen',
        'benachrichtigungs_regeln', 'benutzer', 'beschwerden', 'besichtigung_fotos',
        'besichtigungen', 'einsatz_fotos', 'einsatz_material', 'einsatz_mitarbeiter',
        'einsatz_nachweise', 'einsatz_anfragen', 'einsaetze', 'einstellungen', 'kunden', 'kunden_notizen',
        'kundenfeedback', 'leistungsarten', 'mahnungen', 'material', 'mitarbeiter',
        'mitarbeiter_qualifikationen', 'mitarbeiter_sensibel', 'mitarbeiter_verfuegbarkeit', 'nummernkreise',
        'push_abos', 'qualifikationen',
        'objekt_leistung_mitarbeiter', 'objekt_leistungen', 'objekt_zugang', 'objekte',
        'rechnung_positionen', 'rechnungen', 'rechnungs_layout', 'regieleistungen',
        'stundenkonto', 'subunternehmer', 'subunternehmer_objekte', 'website_config',
        'website_fotos', 'zeiterfassung',
    ];

    private function checkTable(string $table): string
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new RuntimeException('Unbekannte Tabelle: ' . $table);
        }
        return $table;
    }

    private function checkColumns(array $columns): void
    {
        foreach ($columns as $c) {
            if (!is_string($c) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $c) !== 1) {
                throw new RuntimeException('Ungültiger Spaltenname.');
            }
        }
    }
}
