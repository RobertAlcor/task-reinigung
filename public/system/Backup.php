<?php
/**
 * Datenbank-Sicherung ohne mysqldump (auf Shared Hosting oft nicht
 * aufrufbar): alle Tabellen als INSERT-Statements in eine gzip-Datei
 * unter system/backups/. 14 Tage aufbewahrt. Taeglich vom Cron.
 */

declare(strict_types=1);

namespace App;

final class Backup
{
    private const DIR = __DIR__ . '/backups';

    public static function run(int $tageBehalten = 14): string
    {
        $db = Database::get(); $pdo = $db->pdo();
        if (!is_dir(self::DIR) && !mkdir(self::DIR, 0750, true)) { throw new \RuntimeException('Backup-Ordner nicht anlegbar.'); }
        if (!is_file(self::DIR . '/.htaccess')) { file_put_contents(self::DIR . '/.htaccess', "Require all denied\n"); }
        $pfad = self::DIR . '/db-' . date('Ymd-His') . '.sql.gz';
        $gz = gzopen($pfad, 'wb6');
        if ($gz === false) { throw new \RuntimeException('Backup-Datei nicht anlegbar.'); }
        gzwrite($gz, "-- Sicherung " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tabellen = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM);
        foreach ($tabellen as [$t]) {
            $create = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(\PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `{$t}`;\n{$create};\n\n");
            $st = $pdo->query("SELECT * FROM `{$t}`");
            $n = 0; $puffer = [];
            while (($row = $st->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $werte = array_map(static fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row);
                $puffer[] = '(' . implode(',', $werte) . ')';
                if (count($puffer) >= 200) { gzwrite($gz, "INSERT INTO `{$t}` (`" . implode('`,`', array_keys($row)) . "`) VALUES\n" . implode(",\n", $puffer) . ";\n"); $puffer = []; }
                $n++;
            }
            if ($puffer !== []) { gzwrite($gz, "INSERT INTO `{$t}` (`" . implode('`,`', array_keys($row ?: [])) . "`) VALUES\n" . implode(",\n", $puffer) . ";\n"); }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        // Alte Sicherungen entfernen
        foreach (glob(self::DIR . '/db-*.sql.gz') ?: [] as $alt) {
            if (filemtime($alt) < time() - $tageBehalten * 86400) { @unlink($alt); }
        }
        return $pfad;
    }
}
