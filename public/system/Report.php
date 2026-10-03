<?php
/**
 * Berichte und Auswertungen eines Betriebs.
 *
 * Alle Abfragen sind nach betrieb_id gefiltert (Database::rawAll mit
 * tenant()). Deckungsbeitraege setzen Lohndaten voraus und sind nur fuer
 * den Inhaber gedacht — die Seite entscheidet, was sie zeigt.
 */

declare(strict_types=1);

namespace App;

final class Report
{
    // ---------------------------------------------------------------
    // Monatsreihen (letzte 12 Monate inkl. laufendem)
    // ---------------------------------------------------------------

    /** ['2026-01', …, '2026-12'] — die letzten 12 Monate bis heute. */
    public static function monate(int $n = 12): array
    {
        $m = [];
        for ($i = $n - 1; $i >= 0; $i--) { $m[] = date('Y-m', strtotime("first day of -{$i} months")); }
        return $m;
    }

    /** Umsatz netto je Monat aus festgeschriebenen Rechnungen (Storno negativ). */
    public static function umsatzJeMonat(array $monate): array
    {
        $db = Database::get();
        $rows = $db->rawAll("SELECT DATE_FORMAT(rechnungsdatum,'%Y-%m') m, SUM(netto) s FROM rechnungen WHERE betrieb_id = ? AND status <> 'entwurf' AND rechnungsdatum >= ? GROUP BY m", [$db->tenant(), $monate[0] . '-01']);
        return self::reihe($monate, $rows, 'm', 's');
    }

    /** Geleistete Netto-Stunden je Monat. */
    public static function stundenJeMonat(array $monate): array
    {
        $db = Database::get();
        $rows = $db->rawAll("SELECT DATE_FORMAT(checkin_zeit,'%Y-%m') m, SUM(minuten_netto)/60 s FROM zeiterfassung WHERE betrieb_id = ? AND checkin_zeit >= ? AND minuten_netto IS NOT NULL GROUP BY m", [$db->tenant(), $monate[0] . '-01']);
        return self::reihe($monate, $rows, 'm', 's');
    }

    /** Einsaetze je Monat: erledigt, ausgefallen, verspaetet (> 15 Min). */
    public static function einsaetzeJeMonat(array $monate): array
    {
        $db = Database::get();
        $rows = $db->rawAll("SELECT DATE_FORMAT(e.datum,'%Y-%m') m, SUM(e.status='erledigt') erledigt, SUM(e.status='ausgefallen') ausgefallen,
                (SELECT COUNT(*) FROM zeiterfassung z JOIN einsaetze e2 ON e2.id = z.einsatz_id WHERE e2.betrieb_id = e.betrieb_id AND DATE_FORMAT(e2.datum,'%Y-%m') = DATE_FORMAT(e.datum,'%Y-%m') AND TIME(z.checkin_zeit) > ADDTIME(e2.uhrzeit_von,'00:15:00')) verspaetet
              FROM einsaetze e WHERE e.betrieb_id = ? AND e.datum >= ? AND e.datum <= CURDATE() GROUP BY m", [$db->tenant(), $monate[0] . '-01']);
        $out = ['erledigt' => self::reihe($monate, $rows, 'm', 'erledigt'), 'ausgefallen' => self::reihe($monate, $rows, 'm', 'ausgefallen'), 'verspaetet' => self::reihe($monate, $rows, 'm', 'verspaetet')];
        return $out;
    }

    // ---------------------------------------------------------------
    // Tabellen
    // ---------------------------------------------------------------

    /** Umsatz je Kunde im Zeitraum, mit offenen Posten und mittlerer Zahlungsdauer. */
    public static function kunden(string $von, string $bis): array
    {
        $db = Database::get();
        return $db->rawAll("SELECT k.id, k.firmenname,
                COUNT(r.id) rechnungen, IFNULL(SUM(r.netto),0) netto, IFNULL(SUM(r.brutto),0) brutto,
                IFNULL(SUM(CASE WHEN r.status IN ('gestellt','teilbezahlt','mahnung_1','mahnung_2') THEN r.brutto - IFNULL(r.bezahlt_betrag,0) END),0) offen,
                IFNULL(SUM(CASE WHEN r.status IN ('gestellt','teilbezahlt','mahnung_1','mahnung_2') AND r.faellig_am < CURDATE() THEN r.brutto - IFNULL(r.bezahlt_betrag,0) END),0) ueberfaellig,
                ROUND(AVG(CASE WHEN r.status = 'bezahlt' AND r.bezahlt_am IS NOT NULL AND r.brutto > 0 THEN DATEDIFF(r.bezahlt_am, r.rechnungsdatum) END),0) zahlungsdauer,
                (SELECT COUNT(*) FROM beschwerden b WHERE b.kunde_id = k.id AND b.datum BETWEEN ? AND ?) beschwerden
              FROM kunden k LEFT JOIN rechnungen r ON r.kunde_id = k.id AND r.status <> 'entwurf' AND r.rechnungsdatum BETWEEN ? AND ?
             WHERE k.betrieb_id = ? GROUP BY k.id HAVING rechnungen > 0 OR beschwerden > 0 ORDER BY netto DESC", [$von, $bis, $von, $bis, $db->tenant()]);
    }

    /**
     * Objekte im Zeitraum: Einsaetze, Stunden, Umsatzanteil (Rechnungspositionen
     * mit objekt_id), Lohnkosten (Stunden × Stundenlohn des jeweiligen
     * Mitarbeiters) und Deckungsbeitrag. Lohnfelder nur mit $mitLohn.
     */
    public static function objekte(string $von, string $bis, bool $mitLohn): array
    {
        $db = Database::get();
        $rows = $db->rawAll("SELECT o.id, o.name, k.firmenname,
                (SELECT COUNT(*) FROM einsaetze e WHERE e.objekt_id = o.id AND e.status = 'erledigt' AND e.datum BETWEEN ? AND ?) einsaetze,
                (SELECT COUNT(*) FROM einsaetze e WHERE e.objekt_id = o.id AND e.status = 'ausgefallen' AND e.datum BETWEEN ? AND ?) ausgefallen,
                (SELECT IFNULL(SUM(z.minuten_netto),0)/60 FROM zeiterfassung z JOIN einsaetze e ON e.id = z.einsatz_id WHERE e.objekt_id = o.id AND e.datum BETWEEN ? AND ?) stunden,
                (SELECT IFNULL(SUM(e.dauer_soll_minuten),0)/60 FROM einsaetze e WHERE e.objekt_id = o.id AND e.status = 'erledigt' AND e.datum BETWEEN ? AND ?) soll_stunden,
                (SELECT IFNULL(SUM(p.gesamtpreis),0) FROM rechnung_positionen p JOIN rechnungen r ON r.id = p.rechnung_id WHERE p.objekt_id = o.id AND r.status <> 'entwurf' AND r.rechnungsdatum BETWEEN ? AND ?) umsatz,
                " . ($mitLohn ? "(SELECT IFNULL(SUM(z.minuten_netto/60 * IFNULL(m.stundenlohn,0)),0) FROM zeiterfassung z JOIN einsaetze e ON e.id = z.einsatz_id JOIN mitarbeiter m ON m.id = z.mitarbeiter_id WHERE e.objekt_id = o.id AND e.datum BETWEEN ? AND ?) lohn," : "0 lohn,") . "
                (SELECT COUNT(*) FROM beschwerden b WHERE b.objekt_id = o.id AND b.datum BETWEEN ? AND ?) beschwerden
              FROM objekte o JOIN kunden k ON k.id = o.kunde_id WHERE o.betrieb_id = ? HAVING einsaetze > 0 OR umsatz > 0 ORDER BY umsatz DESC, o.name",
            array_merge([$von, $bis, $von, $bis, $von, $bis, $von, $bis, $von, $bis], $mitLohn ? [$von, $bis] : [], [$von, $bis, $db->tenant()]));
        foreach ($rows as &$r) {
            $r['lohn_faktor'] = 1.30;                                   // Lohnnebenkosten Dienstgeber (Pauschalannahme)
            $r['kosten'] = round((float) $r['lohn'] * $r['lohn_faktor'], 2);
            $r['deckung'] = $mitLohn ? round((float) $r['umsatz'] - $r['kosten'], 2) : null;
            $r['deckung_prozent'] = ($mitLohn && (float) $r['umsatz'] > 0) ? round($r['deckung'] / (float) $r['umsatz'] * 100) : null;
            $r['stunden_abweichung'] = (float) $r['soll_stunden'] > 0 ? round(((float) $r['stunden'] - (float) $r['soll_stunden']) / (float) $r['soll_stunden'] * 100) : null;
        }
        return $rows;
    }

    /** Mitarbeiter im Zeitraum: Einsaetze, Stunden, Soll aus Stundenkonto, Verspaetungen, Abwesenheiten. */
    public static function mitarbeiter(string $von, string $bis): array
    {
        $db = Database::get();
        return $db->rawAll("SELECT m.id, CONCAT(m.vorname,' ',m.nachname) name, m.stunden_woche, m.aktiv,
                (SELECT COUNT(DISTINCT z.einsatz_id) FROM zeiterfassung z WHERE z.mitarbeiter_id = m.id AND DATE(z.checkin_zeit) BETWEEN ? AND ?) einsaetze,
                (SELECT IFNULL(SUM(z.minuten_netto),0)/60 FROM zeiterfassung z WHERE z.mitarbeiter_id = m.id AND DATE(z.checkin_zeit) BETWEEN ? AND ?) stunden,
                (SELECT IFNULL(SUM(s.soll_minuten),0)/60 FROM stundenkonto s WHERE s.mitarbeiter_id = m.id AND STR_TO_DATE(CONCAT(s.jahr,'-',s.monat,'-01'),'%Y-%m-%d') BETWEEN ? AND ?) soll,
                (SELECT COUNT(*) FROM zeiterfassung z JOIN einsaetze e ON e.id = z.einsatz_id WHERE z.mitarbeiter_id = m.id AND e.datum BETWEEN ? AND ? AND TIME(z.checkin_zeit) > ADDTIME(e.uhrzeit_von,'00:15:00')) verspaetet,
                (SELECT COUNT(*) FROM zeiterfassung z WHERE z.mitarbeiter_id = m.id AND z.checkin_methode = 'manuell' AND DATE(z.checkin_zeit) BETWEEN ? AND ?) nachtraege,
                (SELECT IFNULL(SUM(a.tage),0) FROM abwesenheiten a WHERE a.mitarbeiter_id = m.id AND a.typ = 'urlaub' AND a.status = 'genehmigt' AND a.datum_von BETWEEN ? AND ?) urlaub,
                (SELECT COUNT(*) FROM beschwerden b WHERE b.mitarbeiter_id = m.id AND b.datum BETWEEN ? AND ?) beschwerden
              FROM mitarbeiter m WHERE m.betrieb_id = ? ORDER BY m.aktiv DESC, m.nachname", [$von, $bis, $von, $bis, $von, $bis, $von, $bis, $von, $bis, $von, $bis, $von, $bis, $db->tenant()]);
    }

    /**
     * Abwesenheiten je Mitarbeiter im Zeitraum: Krankenstandstage und
     * -meldungen, Urlaub, Zeitausgleich; Krankenquote = Kranktage /
     * Arbeitstage laut Anstellung. Kein Grund, keine Bewertung.
     */
    public static function abwesenheiten(string $von, string $bis): array
    {
        $db = Database::get();
        $rows = $db->rawAll("SELECT m.id, CONCAT(m.vorname,' ',m.nachname) name, m.aktiv, m.arbeitstage_woche, m.eintrittsdatum, m.austrittsdatum,
                (SELECT IFNULL(SUM(a.tage),0) FROM abwesenheiten a WHERE a.mitarbeiter_id = m.id AND a.typ = 'krank' AND a.status = 'genehmigt' AND a.datum_von BETWEEN ? AND ?) krank_tage,
                (SELECT COUNT(*) FROM abwesenheiten a WHERE a.mitarbeiter_id = m.id AND a.typ = 'krank' AND a.status = 'genehmigt' AND a.datum_von BETWEEN ? AND ?) krank_faelle,
                (SELECT COUNT(*) FROM abwesenheiten a WHERE a.mitarbeiter_id = m.id AND a.typ = 'krank' AND a.status = 'genehmigt' AND a.datum_von BETWEEN ? AND ? AND a.tage <= 1) krank_kurz,
                (SELECT COUNT(*) FROM abwesenheiten a WHERE a.mitarbeiter_id = m.id AND a.typ = 'krank' AND a.status = 'genehmigt' AND a.datum_von BETWEEN ? AND ? AND DAYOFWEEK(a.datum_von) IN (2,6)) krank_mo_fr,
                (SELECT IFNULL(SUM(a.tage),0) FROM abwesenheiten a WHERE a.mitarbeiter_id = m.id AND a.typ = 'urlaub' AND a.status = 'genehmigt' AND a.datum_von BETWEEN ? AND ?) urlaub_tage,
                (SELECT IFNULL(SUM(a.tage),0) FROM abwesenheiten a WHERE a.mitarbeiter_id = m.id AND a.typ = 'zeitausgleich' AND a.status = 'genehmigt' AND a.datum_von BETWEEN ? AND ?) za_tage,
                (SELECT IFNULL(SUM(a.tage),0) FROM abwesenheiten a WHERE a.mitarbeiter_id = m.id AND a.typ IN ('einschulung','sonstiges') AND a.status = 'genehmigt' AND a.datum_von BETWEEN ? AND ?) sonst_tage
              FROM mitarbeiter m WHERE m.betrieb_id = ? ORDER BY m.aktiv DESC, m.nachname", [$von, $bis, $von, $bis, $von, $bis, $von, $bis, $von, $bis, $von, $bis, $von, $bis, $db->tenant()]);
        foreach ($rows as &$r) {
            // Arbeitstage im Zeitraum, begrenzt auf Eintritt/Austritt
            $a = max(strtotime($von), $r['eintrittsdatum'] ? strtotime($r['eintrittsdatum']) : 0);
            $b = min(strtotime($bis), $r['austrittsdatum'] ? strtotime($r['austrittsdatum']) : PHP_INT_MAX, time());
            $wochen = $b > $a ? ($b - $a) / 604800 : 0;
            $r['arbeitstage'] = round($wochen * (int) ($r['arbeitstage_woche'] ?: 5));
            $r['krank_quote'] = $r['arbeitstage'] > 0 ? round((float) $r['krank_tage'] / $r['arbeitstage'] * 100, 1) : null;
        }
        return $rows;
    }

    /** Krankenstandstage je Monat (Betrieb gesamt). */
    public static function krankJeMonat(array $monate): array
    {
        $db = Database::get();
        $rows = $db->rawAll("SELECT DATE_FORMAT(datum_von,'%Y-%m') m, SUM(tage) s FROM abwesenheiten WHERE betrieb_id = ? AND typ = 'krank' AND status = 'genehmigt' AND datum_von >= ? GROUP BY m", [$db->tenant(), $monate[0] . '-01']);
        return self::reihe($monate, $rows, 'm', 's');
    }

    /** Beschwerden je Mitarbeiter mit Kategorien; Mitarbeiter ohne Beschwerden bleiben sichtbar. */
    public static function beschwerdenJeMitarbeiter(string $von, string $bis): array
    {
        $db = Database::get();
        $rows = $db->rawAll("SELECT m.id, CONCAT(m.vorname,' ',m.nachname) name, m.aktiv,
                (SELECT COUNT(DISTINCT z.einsatz_id) FROM zeiterfassung z WHERE z.mitarbeiter_id = m.id AND DATE(z.checkin_zeit) BETWEEN ? AND ?) einsaetze,
                (SELECT COUNT(*) FROM beschwerden b WHERE b.mitarbeiter_id = m.id AND b.datum BETWEEN ? AND ?) gesamt,
                (SELECT COUNT(*) FROM beschwerden b WHERE b.mitarbeiter_id = m.id AND b.datum BETWEEN ? AND ? AND b.kategorie = 'qualitaet') qualitaet,
                (SELECT COUNT(*) FROM beschwerden b WHERE b.mitarbeiter_id = m.id AND b.datum BETWEEN ? AND ? AND b.kategorie = 'vergessen') vergessen,
                (SELECT COUNT(*) FROM beschwerden b WHERE b.mitarbeiter_id = m.id AND b.datum BETWEEN ? AND ? AND b.kategorie = 'puenktlichkeit') puenktlichkeit,
                (SELECT COUNT(*) FROM beschwerden b WHERE b.mitarbeiter_id = m.id AND b.datum BETWEEN ? AND ? AND b.kategorie = 'verhalten') verhalten,
                (SELECT COUNT(*) FROM beschwerden b WHERE b.mitarbeiter_id = m.id AND b.datum BETWEEN ? AND ? AND b.kategorie = 'schaden') schaden,
                (SELECT COUNT(*) FROM beschwerden b WHERE b.mitarbeiter_id = m.id AND b.datum BETWEEN ? AND ? AND b.status <> 'erledigt') offen
              FROM mitarbeiter m WHERE m.betrieb_id = ? ORDER BY gesamt DESC, m.nachname", [$von, $bis, $von, $bis, $von, $bis, $von, $bis, $von, $bis, $von, $bis, $von, $bis, $von, $bis, $db->tenant()]);
        foreach ($rows as &$r) { $r['je_100'] = (int) $r['einsaetze'] > 0 ? round((int) $r['gesamt'] / (int) $r['einsaetze'] * 100, 1) : null; }
        return $rows;
    }

    /** Einzelne Beschwerden eines Mitarbeiters im Zeitraum. */
    public static function beschwerdenListe(int $mitarbeiterId, string $von, string $bis): array
    {
        $db = Database::get();
        return $db->rawAll("SELECT b.*, o.name objekt, k.firmenname FROM beschwerden b JOIN objekte o ON o.id = b.objekt_id LEFT JOIN kunden k ON k.id = b.kunde_id WHERE b.betrieb_id = ? AND b.mitarbeiter_id = ? AND b.datum BETWEEN ? AND ? ORDER BY b.datum DESC", [$db->tenant(), $mitarbeiterId, $von, $bis]);
    }

    /** Offene Posten nach Faelligkeit gestaffelt. */
    public static function offenePosten(): array
    {
        $db = Database::get();
        return $db->rawOne("SELECT
                IFNULL(SUM(CASE WHEN faellig_am >= CURDATE() THEN brutto - IFNULL(bezahlt_betrag,0) END),0) nicht_faellig,
                IFNULL(SUM(CASE WHEN faellig_am < CURDATE() AND faellig_am >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN brutto - IFNULL(bezahlt_betrag,0) END),0) bis30,
                IFNULL(SUM(CASE WHEN faellig_am < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND faellig_am >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN brutto - IFNULL(bezahlt_betrag,0) END),0) bis60,
                IFNULL(SUM(CASE WHEN faellig_am < DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN brutto - IFNULL(bezahlt_betrag,0) END),0) ueber60,
                COUNT(*) anzahl
              FROM rechnungen WHERE betrieb_id = ? AND status IN ('gestellt','teilbezahlt','mahnung_1','mahnung_2')", [$db->tenant()]) ?? [];
    }

    // ---------------------------------------------------------------
    // Monatsnachweis fuer einen Kunden (PDF-HTML)
    // ---------------------------------------------------------------

    public static function monatsnachweisHtml(int $kundeId, string $monat, bool $mitNamen = false): ?string
    {
        $db = Database::get();
        $k = $db->find('kunden', $kundeId); $b = $db->rawOne('SELECT * FROM betriebe WHERE id = ?', [$db->tenant()]);
        if ($k === null) { return null; }
        $rows = $db->rawAll("SELECT e.datum, e.uhrzeit_von, e.uhrzeit_bis, e.status, o.name objekt, l.name leistung,
                (SELECT MIN(z.checkin_zeit) FROM zeiterfassung z WHERE z.einsatz_id = e.id) ein, (SELECT MAX(z.checkout_zeit) FROM zeiterfassung z WHERE z.einsatz_id = e.id) aus,
                (SELECT IFNULL(SUM(z.minuten_netto),0) FROM zeiterfassung z WHERE z.einsatz_id = e.id) minuten,
                (SELECT GROUP_CONCAT(" . ($mitNamen ? "CONCAT(m.vorname,' ',m.nachname)" : "CONCAT(m.vorname,' ',LEFT(m.nachname,1),'.')") . " SEPARATOR ', ') FROM zeiterfassung z JOIN mitarbeiter m ON m.id = z.mitarbeiter_id WHERE z.einsatz_id = e.id) team,
                (SELECT COUNT(*) FROM einsatz_fotos f WHERE f.einsatz_id = e.id AND f.geloescht = 0) fotos, e.notizen_mitarbeiter
              FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id JOIN leistungsarten l ON l.id = e.leistungsart_id
             WHERE e.betrieb_id = ? AND o.kunde_id = ? AND DATE_FORMAT(e.datum,'%Y-%m') = ? AND e.status IN ('erledigt','ausgefallen') ORDER BY o.name, e.datum, e.uhrzeit_von", [$db->tenant(), $kundeId, $monat]);
        $e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $farbe = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($b['farbe_primaer'] ?? '')) ? $b['farbe_primaer'] : '#0B5D4A';
        $logo = ''; if (!empty($b['logo_pfad'])) { $lp = dirname(__DIR__) . '/uploads/' . $b['logo_pfad']; if (is_file($lp)) { $logo = '<img src="' . $e($lp) . '" style="max-height:16mm;max-width:55mm">'; } }
        $zeilen = ''; $summeMin = 0; $n = 0; $aktObjekt = '';
        foreach ($rows as $r) {
            if ($r['objekt'] !== $aktObjekt) { $aktObjekt = $r['objekt']; $zeilen .= '<tr class="obj"><td colspan="6">' . $e($aktObjekt) . '</td></tr>'; }
            if ($r['status'] === 'ausgefallen') { $zeilen .= '<tr class="aus"><td>' . self::wt($r['datum']) . ' ' . date('d.m.', strtotime($r['datum'])) . '</td><td>' . $e($r['leistung']) . '</td><td colspan="4">entfallen</td></tr>'; continue; }
            $summeMin += (int) $r['minuten']; $n++;
            $zeilen .= '<tr><td>' . self::wt($r['datum']) . ' ' . date('d.m.', strtotime($r['datum'])) . '</td><td>' . $e($r['leistung']) . '</td><td>' . ($r['ein'] ? substr($r['ein'], 11, 5) : '—') . ' – ' . ($r['aus'] ? substr($r['aus'], 11, 5) : '—') . '</td><td class="r">' . number_format((int) $r['minuten'] / 60, 1, ',', '') . '</td><td>' . $e($r['team'] ?? '') . '</td><td class="r">' . ((int) $r['fotos'] > 0 ? (int) $r['fotos'] : '') . '</td></tr>'
                     . ($r['notizen_mitarbeiter'] ? '<tr class="notiz"><td></td><td colspan="5">' . $e($r['notizen_mitarbeiter']) . '</td></tr>' : '');
        }
        [$j, $mo] = explode('-', $monat);
        return '<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8"><style>
  @page{margin:18mm 18mm 22mm}body{font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;color:#13201B;line-height:1.45}
  .kopf{display:table;width:100%;margin-bottom:6mm}.kopf>div{display:table-cell;vertical-align:top}.kopf .r{text-align:right;font-size:8.5pt;color:#4E5C55}
  h1{font-size:16pt;color:' . $farbe . ';margin:0 0 1mm}.sub{color:#4E5C55;margin-bottom:5mm}
  table{width:100%;border-collapse:collapse}th{text-align:left;font-size:8pt;color:#4E5C55;border-bottom:.8pt solid ' . $farbe . ';padding:0 2mm 1.5mm 0;font-weight:normal}
  td{padding:1.8mm 3mm 1.8mm 0;border-bottom:.3pt solid #DDE3DE;vertical-align:top}td.r,th.r{text-align:right;padding-right:5mm}
  tr.obj td{background:#E6EFE9;font-weight:bold;padding:2mm;border:0;margin-top:2mm}tr.aus td{color:#A32218}tr.notiz td{font-size:8.5pt;color:#4E5C55;border-top:0;padding-top:0}
  .summe{margin-top:5mm;text-align:right;font-size:11pt}.summe b{color:' . $farbe . '}
  .fuss{position:fixed;bottom:-14mm;left:0;right:0;font-size:7.5pt;color:#4E5C55;border-top:.4pt solid #DDE3DE;padding-top:2mm}
</style></head><body>
  <div class="kopf"><div>' . ($logo ?: '<b style="font-size:12pt;color:' . $farbe . '">' . $e($b['name']) . '</b>') . '</div><div class="r">' . $e($b['name']) . '<br>' . $e(trim(($b['adresse'] ?? '') . ', ' . ($b['plz'] ?? '') . ' ' . ($b['ort'] ?? ''), ', ')) . '<br>' . $e($b['telefon'] ?? '') . '</div></div>
  <h1>Leistungsnachweis ' . CustomerInvoice::monatName((int) $mo) . ' ' . $j . '</h1><div class="sub">' . $e($k['firmenname']) . ($k['adresse'] ? ' · ' . $e($k['adresse'] . ', ' . $k['plz'] . ' ' . $k['ort']) : '') . '</div>
  ' . ($rows === [] ? '<p>In diesem Monat wurden keine Einsätze erfasst.</p>' : '<table><tr><th>Datum</th><th>Leistung</th><th>Zeit</th><th class="r">Std</th><th>Team</th><th class="r">Fotos</th></tr>' . $zeilen . '</table>
  <div class="summe">' . $n . ' Einsätze · <b>' . number_format($summeMin / 60, 1, ',', '.') . ' Stunden</b></div>') . '
  <p style="margin-top:8mm;font-size:8.5pt;color:#4E5C55">Zeiten aus der Erfassung am Objekt (QR-Code beim Kommen und Gehen). Fotos sind im Kundenportal drei Monate lang einsehbar. Bei Fragen zu einzelnen Einsätzen genügt ein Anruf.</p>
  <div class="fuss">' . $e($b['name']) . ' · Leistungsnachweis ' . $e($monat) . ' · erstellt ' . date('d.m.Y') . '</div></body></html>';
    }

    // ---------------------------------------------------------------
    // Hilfen
    // ---------------------------------------------------------------

    private static function reihe(array $monate, array $rows, string $kKey, string $vKey): array
    {
        $map = []; foreach ($rows as $r) { $map[$r[$kKey]] = (float) $r[$vKey]; }
        $out = []; foreach ($monate as $m) { $out[$m] = round($map[$m] ?? 0, 2); }
        return $out;
    }

    private static function wt(string $d): string
    {
        return ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int) date('w', strtotime($d))];
    }

    /** Balkendiagramm als inline SVG (eine oder zwei Reihen), ohne JavaScript. */
    public static function balken(array $reihen, array $labels, string $einheit = '', int $breite = 720, int $hoehe = 200): string
    {
        $farben = ['#0B5D4A', '#B5D2C3', '#A32218'];
        $max = 0; foreach ($reihen as $r) { $max = max($max, max($r['werte'] ?: [0])); }
        if ($max <= 0) { $max = 1; }
        $n = count($labels); $rand = 34; $bottom = 26; $w = ($breite - $rand) / max(1, $n); $gruppen = count($reihen); $bw = ($w - 8) / $gruppen;
        $svg = '<svg viewBox="0 0 ' . $breite . ' ' . $hoehe . '" class="diagramm" role="img" aria-label="Balkendiagramm"><g font-family="DM Sans,system-ui" font-size="13" fill="#4E5C55">';
        for ($g = 0; $g <= 4; $g++) { $y = ($hoehe - $bottom) - ($hoehe - $bottom - 12) * $g / 4; $v = $max * $g / 4; $svg .= '<line x1="' . $rand . '" y1="' . $y . '" x2="' . $breite . '" y2="' . $y . '" stroke="#E5E9E5"/><text x="' . ($rand - 5) . '" y="' . ($y + 4) . '" text-anchor="end">' . self::kurz($v) . '</text>'; }
        foreach ($labels as $i => $l) {
            $x0 = $rand + $i * $w + 4;
            foreach ($reihen as $ri => $r) {
                $v = (float) ($r['werte'][$i] ?? 0); $h = ($hoehe - $bottom - 12) * $v / $max; $y = ($hoehe - $bottom) - $h;
                $svg .= '<rect x="' . round($x0 + $ri * $bw, 1) . '" y="' . round($y, 1) . '" width="' . round($bw - 1, 1) . '" height="' . round($h, 1) . '" rx="3" fill="' . ($farben[$ri] ?? '#999') . '"><title>' . htmlspecialchars($r['name'] . ' ' . $l . ': ' . number_format($v, $v == round($v) ? 0 : 1, ',', '.') . ' ' . $einheit) . '</title></rect>';
            }
            $svg .= '<text x="' . round($x0 + ($w - 8) / 2, 1) . '" y="' . ($hoehe - 8) . '" text-anchor="middle">' . htmlspecialchars($l) . '</text>';
        }
        $svg .= '</g></svg>';
        if (count($reihen) > 1) { $svg .= '<div class="legende">' . implode('', array_map(static fn($r, $i) => '<span><i style="background:' . $farben[$i] . '"></i>' . htmlspecialchars($r['name']) . '</span>', $reihen, array_keys($reihen))) . '</div>'; }
        return $svg;
    }

    private static function kurz(float $v): string
    {
        if ($v >= 1000) { return number_format($v / 1000, $v >= 10000 ? 0 : 1, ',', '') . 'k'; }
        return number_format($v, $v == round($v) ? 0 : 1, ',', '');
    }

    /** CSV-Datei (UTF-8 BOM, Semikolon) ausliefern. */
    public static function csv(string $name, array $spalten, array $rows): never
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $name . '.csv"');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values($spalten), ';', '"', '\\');
        foreach ($rows as $r) { fputcsv($out, array_map(static fn($k) => is_float($r[$k] ?? null) || (is_numeric($r[$k] ?? null) && str_contains((string) $r[$k], '.')) ? str_replace('.', ',', (string) round((float) $r[$k], 2)) : (string) ($r[$k] ?? ''), array_keys($spalten)), ';', '"', '\\'); }
        fclose($out); exit;
    }
}
