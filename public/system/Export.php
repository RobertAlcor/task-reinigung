<?php
/**
 * Datenexport eines Betriebs: alle Tabellen als CSV (UTF-8 mit BOM,
 * Semikolon — oeffnet sauber in Excel) plus Rechnungs- und Angebots-PDFs
 * in einem ZIP. Erfuellt Art. 20 DSGVO und die Rueckgabe bei Vertragsende.
 * Nur fuer den Inhaber; verschluesselte Felder werden entschluesselt mitgegeben.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;
use ZipArchive;

final class Export
{
    private const TABELLEN = ['kunden', 'kunden_notizen', 'objekte', 'objekt_leistungen', 'objekt_leistung_mitarbeiter', 'leistungsarten', 'mitarbeiter', 'mitarbeiter_verfuegbarkeit',
        'abwesenheiten', 'stundenkonto', 'einsaetze', 'einsatz_mitarbeiter', 'zeiterfassung', 'einsatz_fotos', 'regieleistungen', 'material', 'einsatz_material',
        'subunternehmer', 'subunternehmer_objekte', 'besichtigungen', 'angebote', 'angebot_positionen', 'rechnungen', 'rechnung_positionen', 'beschwerden', 'kundenfeedback', 'anfragen', 'benachrichtigungs_regeln'];

    public static function zip(): string
    {
        if (!class_exists('ZipArchive')) { throw new RuntimeException('PHP-Erweiterung zip fehlt.'); }
        $db = Database::get();
        $b = $db->rawOne('SELECT name FROM betriebe WHERE id = ?', [$db->tenant()]);
        $dir = __DIR__ . '/rechnungen/' . $db->tenant(); if (!is_dir($dir)) { mkdir($dir, 0750, true); }
        $pfad = $dir . '/export-' . date('Ymd-His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($pfad, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('ZIP konnte nicht angelegt werden.'); }

        foreach (self::TABELLEN as $t) {
            $rows = $db->select($t, '', [], 'ORDER BY id');
            if ($t === 'mitarbeiter') {
                foreach ($rows as &$r) {
                    $s = $db->rawOne('SELECT svnr_enc, iban_enc, anzahl_kinder FROM mitarbeiter_sensibel WHERE mitarbeiter_id = ? AND betrieb_id = ?', [(int) $r['id'], $db->tenant()]);
                    $r['svnr'] = $s ? Crypto::decrypt($s['svnr_enc']) : null; $r['iban'] = $s ? Crypto::decrypt($s['iban_enc']) : null; $r['anzahl_kinder'] = $s['anzahl_kinder'] ?? null;
                }
                unset($r);
            }
            if ($t === 'objekte') {
                foreach ($rows as &$r) {
                    $z = $db->rawOne('SELECT schluessel_info_enc, alarm_code_enc FROM objekt_zugang WHERE objekt_id = ? AND betrieb_id = ?', [(int) $r['id'], $db->tenant()]);
                    $r['schluessel_info'] = $z ? Crypto::decrypt($z['schluessel_info_enc']) : null; $r['alarm_code'] = $z ? Crypto::decrypt($z['alarm_code_enc']) : null;
                    unset($r['qr_token'], $r['nfc_token']);
                }
                unset($r);
            }
            $zip->addFromString('daten/' . $t . '.csv', self::csv($rows));
        }
        $zip->addFromString('daten/audit_log.csv', self::csv($db->rawAll('SELECT * FROM audit_log WHERE betrieb_id = ? ORDER BY id', [$db->tenant()])));
        $n = 0;
        foreach ($db->select('rechnungen', "status <> 'entwurf' AND pdf_pfad IS NOT NULL") as $r) {
            $p = $dir . '/' . $r['pdf_pfad']; if (is_file($p)) { $zip->addFile($p, 'rechnungen/' . $r['pdf_pfad']); $n++; }
        }
        foreach ($db->select('angebote', 'pdf_pfad IS NOT NULL') as $a) {
            $p = $dir . '/' . $a['pdf_pfad']; if (is_file($p)) { $zip->addFile($p, 'angebote/' . $a['pdf_pfad']); $n++; }
        }
        $zip->addFromString('LIESMICH.txt', "Datenexport " . $b['name'] . "\nErstellt am " . date('d.m.Y H:i') . "\n\nOrdner daten/: alle Tabellen als CSV (UTF-8, Semikolon, Excel-tauglich).\nOrdner rechnungen/ und angebote/: PDFs ({$n} Dateien).\nFotos sind nicht enthalten; sie werden drei Monate nach dem Einsatz gelöscht und können in der Nachweisansicht einzeln gespeichert werden.\n\nVerschlüsselte Felder (SVNR, IBAN, Alarmcodes) sind entschlüsselt enthalten — Datei entsprechend schützen.\n");
        $zip->close();
        return $pfad;
    }

    private static function csv(array $rows): string
    {
        if ($rows === []) { return "\xEF\xBB\xBF" . "(leer)\n"; }
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_keys($rows[0]), ';', '"', '\\');
        foreach ($rows as $r) {
            $r = array_map(static fn($v) => is_string($v) && !mb_check_encoding($v, 'UTF-8') ? '[binär]' : $v, $r);
            fputcsv($out, array_map(static fn($v) => $v === null ? '' : (string) $v, $r), ';', '"', '\\');
        }
        rewind($out); $s = stream_get_contents($out); fclose($out);
        return $s;
    }
}
