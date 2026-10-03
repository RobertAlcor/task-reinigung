<?php
/**
 * Rechnungen des Betriebs an seine Kunden.
 *
 * Ablauf: Entwurf aus dem Leistungsmonat erzeugen → Positionen pruefen →
 * festschreiben (Nummer, unveraenderbar) → PDF → senden → bezahlt buchen.
 * Korrektur nur per Storno mit Gegenrechnung (§ 11 UStG, § 132 BAO).
 *
 * Quellen einer Monatsrechnung:
 *   - Pauschalen: objekt_leistungen.preis_monat (im Monat gueltig)
 *   - Je Einsatz: erledigte Einsaetze × objekt_leistungen.preis_einheit
 *   - Nach Stunden: erledigte Einsaetze, Netto-Minuten × Stundensatz
 *     (objekt_leistung → leistungsart-Standard)
 *   - Regieleistungen: offen, mit Datum im Monat (oder frueher)
 *   - Material: einsatz_material × verkaufspreis
 */

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use RuntimeException;

final class CustomerInvoice
{
    private const PDF_DIR = __DIR__ . '/rechnungen';

    // ---------------------------------------------------------------
    // Einstellungen des Betriebs (USt, Kleinunternehmer)
    // ---------------------------------------------------------------

    public static function ustSatz(): float
    {
        $r = Database::get()->selectOne('einstellungen', "schluessel = 'ust_satz'");
        return $r !== null ? (float) $r['wert'] : 20.0;
    }

    public static function kleinunternehmer(): bool
    {
        $r = Database::get()->selectOne('einstellungen', "schluessel = 'kleinunternehmer'");
        return $r !== null && $r['wert'] === '1';
    }

    /** Vorschau der Positionen fuer Kunde und Monat, ohne zu speichern. */
    public static function vorschau(int $kundeId, int $jahr, int $monat): array
    {
        $db = Database::get();
        $von = sprintf('%04d-%02d-01', $jahr, $monat);
        $bis = (new DateTimeImmutable($von))->modify('last day of this month')->format('Y-m-d');
        $pos = [];

        // Pauschalen je Objekt-Leistung
        $pausch = $db->rawAll(
            "SELECT ol.id, ol.preis_monat, ol.gueltig_von, ol.gueltig_bis, o.id AS objekt_id, o.name AS objekt, l.name AS leistung, l.id AS leistungsart_id
               FROM objekt_leistungen ol JOIN objekte o ON o.id = ol.objekt_id JOIN leistungsarten l ON l.id = ol.leistungsart_id
              WHERE ol.betrieb_id = ? AND o.kunde_id = ? AND ol.preis_monat IS NOT NULL AND ol.preis_monat > 0
                AND (ol.gueltig_von IS NULL OR ol.gueltig_von <= ?) AND (ol.gueltig_bis IS NULL OR ol.gueltig_bis >= ?)
              ORDER BY o.name, l.name",
            [$db->tenant(), $kundeId, $bis, $von]
        );
        foreach ($pausch as $p) {
            $pos[] = ['objekt_id' => (int) $p['objekt_id'], 'leistungsart_id' => (int) $p['leistungsart_id'],
                'beschreibung' => $p['leistung'] . ' ' . $p['objekt'] . ', Pauschale ' . self::monatName($monat) . ' ' . $jahr,
                'menge' => 1, 'einheit' => 'Pauschale', 'einzelpreis' => (float) $p['preis_monat']];
        }

        // Je Einsatz
        $einheit = $db->rawAll(
            "SELECT o.id AS objekt_id, o.name AS objekt, l.id AS leistungsart_id, l.name AS leistung, ol.preis_einheit, COUNT(*) AS n
               FROM einsaetze e JOIN objekt_leistungen ol ON ol.id = e.objekt_leistung_id JOIN objekte o ON o.id = e.objekt_id JOIN leistungsarten l ON l.id = e.leistungsart_id
              WHERE e.betrieb_id = ? AND o.kunde_id = ? AND e.status = 'erledigt' AND e.datum BETWEEN ? AND ?
                AND ol.preis_einheit IS NOT NULL AND ol.preis_einheit > 0 AND (ol.preis_monat IS NULL OR ol.preis_monat = 0)
              GROUP BY o.id, l.id, ol.preis_einheit ORDER BY o.name, l.name",
            [$db->tenant(), $kundeId, $von, $bis]
        );
        foreach ($einheit as $p) {
            $pos[] = ['objekt_id' => (int) $p['objekt_id'], 'leistungsart_id' => (int) $p['leistungsart_id'],
                'beschreibung' => $p['leistung'] . ' ' . $p['objekt'] . ', ' . self::monatName($monat) . ' ' . $jahr,
                'menge' => (int) $p['n'], 'einheit' => 'Einsatz', 'einzelpreis' => (float) $p['preis_einheit']];
        }

        // Nach Stunden
        $stunden = $db->rawAll(
            "SELECT o.id AS objekt_id, o.name AS objekt, l.id AS leistungsart_id, l.name AS leistung,
                    COALESCE(ol.stundensatz, l.standard_stundensatz) AS satz, SUM(z.minuten_netto) AS minuten
               FROM zeiterfassung z JOIN einsaetze e ON e.id = z.einsatz_id JOIN objekte o ON o.id = e.objekt_id JOIN leistungsarten l ON l.id = e.leistungsart_id
          LEFT JOIN objekt_leistungen ol ON ol.id = e.objekt_leistung_id
              WHERE e.betrieb_id = ? AND o.kunde_id = ? AND e.status = 'erledigt' AND e.datum BETWEEN ? AND ? AND z.minuten_netto IS NOT NULL
                AND (ol.id IS NULL OR ((ol.preis_monat IS NULL OR ol.preis_monat = 0) AND (ol.preis_einheit IS NULL OR ol.preis_einheit = 0)))
              GROUP BY o.id, l.id, satz HAVING minuten > 0 ORDER BY o.name, l.name",
            [$db->tenant(), $kundeId, $von, $bis]
        );
        foreach ($stunden as $p) {
            if ($p['satz'] === null) { continue; }
            $h = round(((int) $p['minuten']) / 60, 2);
            $pos[] = ['objekt_id' => (int) $p['objekt_id'], 'leistungsart_id' => (int) $p['leistungsart_id'],
                'beschreibung' => $p['leistung'] . ' ' . $p['objekt'] . ' nach Aufwand, ' . self::monatName($monat) . ' ' . $jahr,
                'menge' => $h, 'einheit' => 'Std', 'einzelpreis' => (float) $p['satz']];
        }

        // Regieleistungen (offen)
        $regie = $db->rawAll(
            "SELECT r.*, o.name AS objekt, l.name AS leistung FROM regieleistungen r LEFT JOIN objekte o ON o.id = r.objekt_id LEFT JOIN leistungsarten l ON l.id = r.leistungsart_id
              WHERE r.betrieb_id = ? AND r.kunde_id = ? AND r.rechnung_id IS NULL AND r.datum <= ? ORDER BY r.datum",
            [$db->tenant(), $kundeId, $bis]
        );
        $regieIds = [];
        foreach ($regie as $r) {
            $regieIds[] = (int) $r['id'];
            $pos[] = ['objekt_id' => $r['objekt_id'] ? (int) $r['objekt_id'] : null, 'leistungsart_id' => $r['leistungsart_id'] ? (int) $r['leistungsart_id'] : null,
                'beschreibung' => date('d.m.Y', strtotime($r['datum'])) . ' ' . $r['beschreibung'] . ($r['objekt'] ? ', ' . $r['objekt'] : ''),
                'menge' => (float) $r['menge'], 'einheit' => $r['einheit'], 'einzelpreis' => (float) $r['einzelpreis']];
            if ((float) $r['fahrtkosten'] > 0) {
                $pos[] = ['objekt_id' => null, 'leistungsart_id' => null, 'beschreibung' => 'Fahrtkosten ' . date('d.m.Y', strtotime($r['datum'])), 'menge' => 1, 'einheit' => 'Pauschale', 'einzelpreis' => (float) $r['fahrtkosten']];
            }
        }

        // Material
        $material = $db->rawAll(
            "SELECT m.name, m.einheit, m.verkaufspreis, SUM(em.menge) AS menge
               FROM einsatz_material em JOIN material m ON m.id = em.material_id JOIN einsaetze e ON e.id = em.einsatz_id JOIN objekte o ON o.id = e.objekt_id
              WHERE em.betrieb_id = ? AND o.kunde_id = ? AND e.datum BETWEEN ? AND ? AND m.verkaufspreis IS NOT NULL AND m.verkaufspreis > 0
              GROUP BY m.id ORDER BY m.name",
            [$db->tenant(), $kundeId, $von, $bis]
        );
        foreach ($material as $m) {
            $pos[] = ['objekt_id' => null, 'leistungsart_id' => null, 'beschreibung' => 'Material: ' . $m['name'], 'menge' => (float) $m['menge'], 'einheit' => $m['einheit'], 'einzelpreis' => (float) $m['verkaufspreis']];
        }

        foreach ($pos as &$p) { $p['gesamtpreis'] = round($p['menge'] * $p['einzelpreis'], 2); }
        return ['positionen' => $pos, 'regie_ids' => $regieIds, 'von' => $von, 'bis' => $bis];
    }

    /** Entwurf speichern. Regieleistungen werden dem Entwurf zugeordnet. */
    public static function entwurfErzeugen(int $kundeId, int $jahr, int $monat): int
    {
        $db = Database::get();
        $kunde = $db->find('kunden', $kundeId);
        if ($kunde === null) { throw new RuntimeException('Kunde nicht gefunden.'); }
        $v = self::vorschau($kundeId, $jahr, $monat);
        if ($v['positionen'] === []) { throw new RuntimeException('Für diesen Monat gibt es nichts abzurechnen.'); }
        $vorhanden = $db->selectOne('rechnungen', "kunde_id = ? AND leistungszeitraum_von = ? AND status <> 'storniert'", [$kundeId, $v['von']]);
        if ($vorhanden !== null) { throw new RuntimeException('Für diesen Kunden und Monat existiert bereits Rechnung ' . ($vorhanden['rechnungsnummer'] ?: 'im Entwurf') . '.'); }

        return $db->transaction(static function () use ($db, $kundeId, $kunde, $v): int {
            $id = $db->insert('rechnungen', ['kunde_id' => $kundeId, 'leistungszeitraum_von' => $v['von'], 'leistungszeitraum_bis' => $v['bis'],
                'ust_satz' => self::kleinunternehmer() ? 0 : self::ustSatz(), 'status' => 'entwurf']);
            $i = 0;
            foreach ($v['positionen'] as $p) {
                $db->insert('rechnung_positionen', ['rechnung_id' => $id, 'objekt_id' => $p['objekt_id'], 'leistungsart_id' => $p['leistungsart_id'],
                    'beschreibung' => mb_substr($p['beschreibung'], 0, 255), 'menge' => $p['menge'], 'einheit' => $p['einheit'], 'einzelpreis' => $p['einzelpreis'], 'gesamtpreis' => $p['gesamtpreis'], 'sortierung' => $i++]);
            }
            foreach ($v['regie_ids'] as $rid) { $db->update('regieleistungen', $rid, ['rechnung_id' => $id]); }
            self::summen($id);
            return $id;
        });
    }

    /** Summen aus den Positionen neu berechnen (nur im Entwurf). */
    public static function summen(int $id): void
    {
        $db = Database::get();
        $r = $db->find('rechnungen', $id);
        if ($r === null) { return; }
        $netto = (float) ($db->rawOne('SELECT IFNULL(SUM(gesamtpreis),0) s FROM rechnung_positionen WHERE rechnung_id = ? AND betrieb_id = ?', [$id, $db->tenant()])['s'] ?? 0);
        $ust = round($netto * (float) $r['ust_satz'] / 100, 2);
        $db->update('rechnungen', $id, ['netto' => round($netto, 2), 'ust' => $ust, 'brutto' => round($netto + $ust, 2)]);
    }

    public static function positionSpeichern(int $rechnungId, ?int $posId, array $d): void
    {
        $db = Database::get();
        $r = $db->find('rechnungen', $rechnungId);
        if ($r === null || $r['status'] !== 'entwurf') { throw new RuntimeException('Nur Entwürfe sind änderbar.'); }
        $menge = (float) $d['menge']; $preis = (float) $d['einzelpreis'];
        $daten = ['beschreibung' => mb_substr(trim((string) $d['beschreibung']), 0, 255), 'menge' => $menge, 'einheit' => mb_substr(trim((string) ($d['einheit'] ?: 'Pauschale')), 0, 20), 'einzelpreis' => $preis, 'gesamtpreis' => round($menge * $preis, 2)];
        if ($daten['beschreibung'] === '') { throw new RuntimeException('Beschreibung fehlt.'); }
        if ($posId) {
            $p = $db->find('rechnung_positionen', $posId);
            if ($p === null || (int) $p['rechnung_id'] !== $rechnungId) { throw new RuntimeException('Position nicht gefunden.'); }
            $db->update('rechnung_positionen', $posId, $daten);
        } else {
            $daten['rechnung_id'] = $rechnungId;
            $daten['sortierung'] = (int) ($db->rawOne('SELECT IFNULL(MAX(sortierung),0)+1 s FROM rechnung_positionen WHERE rechnung_id = ?', [$rechnungId])['s'] ?? 0);
            $db->insert('rechnung_positionen', $daten);
        }
        self::summen($rechnungId);
    }

    public static function positionLoeschen(int $rechnungId, int $posId): void
    {
        $db = Database::get();
        $r = $db->find('rechnungen', $rechnungId);
        if ($r === null || $r['status'] !== 'entwurf') { throw new RuntimeException('Nur Entwürfe sind änderbar.'); }
        $db->delete('rechnung_positionen', $posId);
        self::summen($rechnungId);
    }

    public static function entwurfLoeschen(int $id): void
    {
        $db = Database::get();
        $r = $db->find('rechnungen', $id);
        if ($r === null || $r['status'] !== 'entwurf') { throw new RuntimeException('Nur Entwürfe können verworfen werden.'); }
        $db->transaction(static function () use ($db, $id): void {
            $db->raw('UPDATE regieleistungen SET rechnung_id = NULL WHERE rechnung_id = ? AND betrieb_id = ?', [$id, $db->tenant()]);
            $db->raw('DELETE FROM rechnung_positionen WHERE rechnung_id = ? AND betrieb_id = ?', [$id, $db->tenant()]);
            $db->delete('rechnungen', $id);
        });
    }

    /** Festschreiben: Nummer vergeben, Datum, Faelligkeit; danach unveraenderbar. */
    public static function festschreiben(int $id, ?string $datum = null): string
    {
        $db = Database::get();
        $r = $db->find('rechnungen', $id);
        if ($r === null || $r['status'] !== 'entwurf') { throw new RuntimeException('Rechnung ist kein Entwurf.'); }
        if ((float) $r['brutto'] <= 0) { throw new RuntimeException('Rechnungsbetrag ist null.'); }
        $kunde = $db->find('kunden', (int) $r['kunde_id']);
        $datum = $datum ?: date('Y-m-d');
        $jahr = (int) substr($datum, 0, 4);

        return $db->transaction(static function () use ($db, $id, $kunde, $datum, $jahr): string {
            $nummer = self::naechsteNummer($jahr);
            $ziel = (int) ($kunde['zahlungsziel_tage'] ?? 14);
            $db->update('rechnungen', $id, ['rechnungsnummer' => $nummer, 'rechnungsdatum' => $datum,
                'faellig_am' => date('Y-m-d', strtotime($datum . " +{$ziel} days")), 'status' => 'gestellt', 'festgeschrieben_am' => date('Y-m-d H:i:s')]);
            return $nummer;
        });
    }

    /** Naechste fortlaufende Rechnungsnummer des Jahres. Muss innerhalb einer Transaktion laufen. */
    private static function naechsteNummer(int $jahr): string
    {
        $db = Database::get();
        $db->raw("INSERT INTO nummernkreise (betrieb_id, typ, jahr, praefix, letzte_nummer) VALUES (?, 'rechnung', ?, 'RE', 0) ON DUPLICATE KEY UPDATE jahr = jahr", [$db->tenant(), $jahr]);
        $db->raw("UPDATE nummernkreise SET letzte_nummer = LAST_INSERT_ID(letzte_nummer + 1) WHERE betrieb_id = ? AND typ = 'rechnung' AND jahr = ?", [$db->tenant(), $jahr]);
        $nr = (int) $db->pdo()->lastInsertId();          // sofort lesen — jedes weitere Statement setzt den Wert zurueck
        if ($nr < 1) {
            $nr = (int) ($db->rawOne("SELECT letzte_nummer FROM nummernkreise WHERE betrieb_id = ? AND typ = 'rechnung' AND jahr = ?", [$db->tenant(), $jahr])['letzte_nummer'] ?? 1);
        }
        $praefix = $db->rawOne("SELECT praefix FROM nummernkreise WHERE betrieb_id = ? AND typ = 'rechnung' AND jahr = ?", [$db->tenant(), $jahr])['praefix'] ?? 'RE';
        return sprintf('%s-%d-%04d', $praefix, $jahr, $nr);
    }

    public static function bezahlt(int $id, ?string $datum, ?float $betrag): void
    {
        $db = Database::get();
        $r = $db->find('rechnungen', $id);
        if ($r === null || !in_array($r['status'], ['gestellt', 'teilbezahlt', 'mahnung_1', 'mahnung_2'], true)) { throw new RuntimeException('Rechnung ist nicht offen.'); }
        $bisherGezahlt = (float) ($r['bezahlt_betrag'] ?? 0);
        $betrag = $betrag ?? round((float) $r['brutto'] - $bisherGezahlt, 2);
        if ($betrag <= 0) { throw new RuntimeException('Betrag muss größer als null sein.'); }
        $bisher = $bisherGezahlt + $betrag;
        $voll = $bisher >= (float) $r['brutto'] - 0.005;
        $db->update('rechnungen', $id, ['bezahlt_am' => $datum ?: date('Y-m-d'), 'bezahlt_betrag' => round($bisher, 2), 'status' => $voll ? 'bezahlt' : 'teilbezahlt']);
    }

    public static function mahnstufe(int $id, int $stufe): void
    {
        $db = Database::get();
        $r = $db->find('rechnungen', $id);
        if ($r === null || !in_array($r['status'], ['gestellt', 'mahnung_1', 'teilbezahlt'], true)) { throw new RuntimeException('Keine Mahnung möglich.'); }
        $db->update('rechnungen', $id, ['status' => $stufe >= 2 ? 'mahnung_2' : 'mahnung_1']);
    }

    /**
     * Mahnung erzeugen: Stufe 1 = Zahlungserinnerung (ohne Spesen, ohne Zinsen ausser eingestellt),
     * Stufe 2 = Mahnung mit Verzugszinsen (§ 456 UGB) und Betreibungskosten (§ 458 UGB) laut Layout.
     * Erstellt PDF, setzt Rechnungsstatus, liefert die Mahnungs-ID.
     */
    public static function mahnungErstellen(int $rechnungId, int $stufe): int
    {
        $db = Database::get();
        $r = $db->find('rechnungen', $rechnungId);
        if ($r === null || !in_array($r['status'], ['gestellt', 'teilbezahlt', 'mahnung_1', 'mahnung_2'], true)) { throw new RuntimeException('Zu dieser Rechnung ist keine Mahnung möglich.'); }
        if ($r['faellig_am'] >= date('Y-m-d')) { throw new RuntimeException('Die Rechnung ist noch nicht fällig (' . date('d.m.Y', strtotime($r['faellig_am'])) . ').'); }
        $stufe = $stufe >= 2 ? 2 : 1;
        $lay = $db->rawOne('SELECT * FROM rechnungs_layout WHERE betrieb_id = ?', [$db->tenant()]) ?? [];
        $offen = round((float) $r['brutto'] - (float) ($r['bezahlt_betrag'] ?? 0), 2);
        if ($offen <= 0) { throw new RuntimeException('Es ist nichts mehr offen.'); }
        $tage = (int) ((strtotime(date('Y-m-d')) - strtotime($r['faellig_am'])) / 86400);
        $zins = $stufe === 2 ? (float) ($lay['verzugszins_prozent'] ?? 9.2) : 0.0;
        $zinsen = round($offen * $zins / 100 * $tage / 365, 2);
        $spesen = (float) ($stufe === 2 ? ($lay['mahnspesen_2'] ?? 40) : ($lay['mahnspesen_1'] ?? 0));
        $frist = date('Y-m-d', strtotime('+' . (int) ($lay['mahnfrist_tage'] ?? 10) . ' days'));
        $mid = $db->insert('mahnungen', ['rechnung_id' => $rechnungId, 'stufe' => $stufe, 'datum' => date('Y-m-d'), 'frist_bis' => $frist, 'offen_betrag' => $offen, 'verzugstage' => $tage, 'zinssatz' => $zins, 'verzugszinsen' => $zinsen, 'mahnspesen' => $spesen, 'gesamt' => round($offen + $zinsen + $spesen, 2)]);
        $db->update('rechnungen', $rechnungId, ['status' => $stufe === 2 ? 'mahnung_2' : 'mahnung_1']);
        self::mahnungPdf($mid);
        return $mid;
    }

    public static function mahnungPdf(int $mahnungId): string
    {
        $db = Database::get();
        $m = $db->find('mahnungen', $mahnungId); if ($m === null) { throw new RuntimeException('Mahnung nicht gefunden.'); }
        $r = $db->find('rechnungen', (int) $m['rechnung_id']);
        $pfad = self::PDF_DIR . '/' . $db->tenant() . '/Mahnung-' . $m['stufe'] . '-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $r['rechnungsnummer']) . '-' . $mahnungId . '.pdf';
        if (is_file($pfad)) { return $pfad; }
        $dir = dirname($pfad); if (!is_dir($dir) && !mkdir($dir, 0750, true)) { throw new RuntimeException('PDF-Ordner nicht anlegbar.'); }
        require_once __DIR__ . '/lib/dompdf/autoload.inc.php';
        $opt = new \Dompdf\Options(); $opt->set('isRemoteEnabled', false); $opt->set('defaultFont', 'DejaVu Sans');
        $opt->set('fontDir', __DIR__ . '/lib/dompdf/vendor/dompdf/dompdf/lib/fonts'); $opt->set('fontCache', self::PDF_DIR); $opt->set('chroot', dirname(__DIR__));
        $pdf = new \Dompdf\Dompdf($opt); $pdf->setPaper('A4'); $pdf->loadHtml(self::mahnungHtml($m, $r), 'UTF-8'); $pdf->render();
        file_put_contents($pfad, $pdf->output());
        $db->update('mahnungen', $mahnungId, ['pdf_pfad' => basename($pfad)]);
        return $pfad;
    }

    private static function mahnungHtml(array $m, array $r): string
    {
        $db = Database::get();
        $b = $db->rawOne('SELECT * FROM betriebe WHERE id = ?', [$db->tenant()]);
        $lay = $db->rawOne('SELECT * FROM rechnungs_layout WHERE betrieb_id = ?', [$db->tenant()]) ?? [];
        $k = $db->find('kunden', (int) $r['kunde_id']) ?? [];
        $e = static fn(?string $x): string => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $g = static fn(float $x): string => number_format($x, 2, ',', '.') . ' €';
        $d = static fn(?string $x): string => $x ? date('d.m.Y', strtotime($x)) : '';
        $farbe = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($lay['farbe'] ?? $b['farbe_primaer'] ?? '')) ? ($lay['farbe'] ?? $b['farbe_primaer']) : '#0B5D4A';
        $logo = ''; if (!empty($b['logo_pfad'])) { $lp = dirname(__DIR__) . '/uploads/' . $b['logo_pfad']; if (is_file($lp)) { $logo = '<img src="' . $e($lp) . '" style="max-height:18mm;max-width:60mm">'; } }
        $empf = $k['rechnungsadresse'] ? nl2br($e(str_replace(', ', "\n", $k['rechnungsadresse']))) : $e($k['firmenname'] ?? '') . ($k['adresse'] ? '<br>' . $e($k['adresse']) . '<br>' . $e($k['plz'] . ' ' . $k['ort']) : '');
        $stufe = (int) $m['stufe'];
        $titel = $stufe === 1 ? 'Zahlungserinnerung' : 'Mahnung';
        $anrede = $k['kontaktperson'] ? 'Sehr geehrte/r ' . $e($k['kontaktperson']) . ',' : 'Sehr geehrte Damen und Herren,';
        $text1 = $stufe === 1
            ? 'sicher ist es Ihrer Aufmerksamkeit entgangen: Die unten angeführte Rechnung ist seit dem ' . $d($r['faellig_am']) . ' fällig und bei uns noch nicht eingegangen. Wir bitten Sie, den offenen Betrag bis zum <b>' . $d($m['frist_bis']) . '</b> zu überweisen. Sollte sich Ihre Zahlung mit diesem Schreiben überschnitten haben, betrachten Sie es bitte als gegenstandslos.'
            : 'trotz unserer Zahlungserinnerung ist die unten angeführte Rechnung weiterhin offen. Wir fordern Sie auf, den Gesamtbetrag bis zum <b>' . $d($m['frist_bis']) . '</b> zu überweisen. Verzugszinsen nach § 456 UGB und Betreibungskosten nach § 458 UGB sind ausgewiesen. Bleibt auch diese Frist ungenutzt, übergeben wir die Forderung ohne weitere Ankündigung an ein Inkassobüro bzw. leiten das gerichtliche Mahnverfahren ein; die dadurch entstehenden Kosten gehen zu Ihren Lasten.';
        $zeilen = '<tr><td>Rechnung ' . $e($r['rechnungsnummer']) . ' vom ' . $d($r['rechnungsdatum']) . ', fällig ' . $d($r['faellig_am']) . '</td><td class="r">' . $g((float) $r['brutto']) . '</td></tr>';
        if ((float) ($r['bezahlt_betrag'] ?? 0) > 0) { $zeilen .= '<tr><td>abzüglich bereits bezahlt</td><td class="r">− ' . $g((float) $r['bezahlt_betrag']) . '</td></tr>'; }
        $zeilen .= '<tr><td><b>Offener Rechnungsbetrag</b></td><td class="r"><b>' . $g((float) $m['offen_betrag']) . '</b></td></tr>';
        if ((float) $m['verzugszinsen'] > 0) { $zeilen .= '<tr><td>Verzugszinsen ' . number_format((float) $m['zinssatz'], 2, ',', '') . ' % p. a. für ' . (int) $m['verzugstage'] . ' Tage (§ 456 UGB)</td><td class="r">' . $g((float) $m['verzugszinsen']) . '</td></tr>'; }
        if ((float) $m['mahnspesen'] > 0) { $zeilen .= '<tr><td>Betreibungskosten (§ 458 UGB)</td><td class="r">' . $g((float) $m['mahnspesen']) . '</td></tr>'; }
        $zeilen .= '<tr class="summe"><td><b>Zu zahlen bis ' . $d($m['frist_bis']) . '</b></td><td class="r"><b>' . $g((float) $m['gesamt']) . '</b></td></tr>';
        return '<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8"><style>
  @page{margin:20mm 20mm 25mm}body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;color:#13201B;line-height:1.5}
  .kopf{display:table;width:100%;margin-bottom:6mm}.kopf>div{display:table-cell;vertical-align:top}.kopf .r{text-align:right;font-size:8.5pt;color:#4E5C55}
  .absender{font-size:7.5pt;color:#4E5C55;border-bottom:.4pt solid #DDE3DE;margin-bottom:2mm;padding-bottom:1mm}.empf{min-height:20mm;margin-bottom:4mm}
  h1{font-size:15pt;color:' . $farbe . ';margin:0 0 4mm}p{margin:0 0 2.5mm}table{width:100%;border-collapse:collapse;margin:4mm 0}td{padding:1.6mm 0;border-bottom:.3pt solid #DDE3DE;vertical-align:top}td.r{text-align:right;white-space:nowrap}tr.summe td{border-top:.8pt solid ' . $farbe . ';border-bottom:0;padding-top:3mm;font-size:11pt}
  .bank{margin-top:5mm;padding:3mm 4mm;background:#F3F5F3;border-radius:2mm;font-size:9pt}.fuss{position:fixed;bottom:-16mm;left:0;right:0;font-size:7.5pt;color:#4E5C55;border-top:.4pt solid #DDE3DE;padding-top:2mm}
</style></head><body>
  <div class="kopf"><div>' . ($logo ?: '<b style="font-size:13pt;color:' . $farbe . '">' . $e($b['name']) . '</b>') . '</div><div class="r">' . $e($b['name']) . '<br>' . $e(trim(($b['adresse'] ?? '') . ', ' . ($b['plz'] ?? '') . ' ' . ($b['ort'] ?? ''), ', ')) . '<br>' . $e($b['telefon'] ?? '') . '<br>' . $e($b['email'] ?? '') . ($b['uid_nummer'] ? '<br>UID ' . $e($b['uid_nummer']) : '') . '</div></div>
  <div class="absender">' . $e($b['name']) . ' · ' . $e(trim(($b['adresse'] ?? '') . ', ' . ($b['plz'] ?? '') . ' ' . ($b['ort'] ?? ''), ', ')) . '</div><div class="empf">' . $empf . '</div>
  <div style="text-align:right;font-size:9pt;color:#4E5C55;margin-bottom:4mm">' . $e($b['ort'] ?? '') . ', ' . $d($m['datum']) . '</div>
  <h1>' . ($stufe === 2 ? '2. ' : '') . $titel . ' zu Rechnung ' . $e($r['rechnungsnummer']) . '</h1>
  <p>' . $anrede . '</p><p>' . $text1 . '</p>
  <table>' . $zeilen . '</table>
  <div class="bank"><b>Bankverbindung</b><br>' . $e($lay['bankname'] ?? '') . ' · IBAN ' . $e($lay['iban'] ?? '') . ($lay['bic'] ? ' · BIC ' . $e($lay['bic']) : '') . '<br>Verwendungszweck: ' . $e($r['rechnungsnummer']) . '</div>
  <p style="margin-top:6mm">Mit freundlichen Grüßen<br>' . $e($b['name']) . '</p>
  <div class="fuss">' . $e($b['name']) . ' · ' . $titel . ' zu ' . $e($r['rechnungsnummer']) . ' · ' . $d($m['datum']) . '</div>
</body></html>';
    }

    public static function mahnungSenden(int $mahnungId, array $betrieb): void
    {
        $db = Database::get();
        $m = $db->find('mahnungen', $mahnungId); if ($m === null) { throw new RuntimeException('Mahnung nicht gefunden.'); }
        $r = $db->find('rechnungen', (int) $m['rechnung_id']); $k = $db->find('kunden', (int) $r['kunde_id']);
        $an = $k['rechnungs_email'] ?: $k['email'];
        if (!$an) { throw new RuntimeException('Beim Kunden ist keine E-Mail hinterlegt.'); }
        $pfad = self::mahnungPdf($mahnungId);
        $titel = (int) $m['stufe'] === 1 ? 'Zahlungserinnerung' : '2. Mahnung';
        $text = "Guten Tag,\n\nanbei " . ($m['stufe'] === 1 ? 'eine Zahlungserinnerung' : 'die 2. Mahnung') . ' zu Rechnung ' . $r['rechnungsnummer'] . ".\nOffen: " . number_format((float) $m['gesamt'], 2, ',', '.') . " €, zahlbar bis " . date('d.m.Y', strtotime($m['frist_bis'])) . ', Verwendungszweck ' . $r['rechnungsnummer'] . ".\n\nMit freundlichen Grüßen\n" . $betrieb['name'];
        Mailer::send($an, (string) $k['firmenname'], $titel . ' zu Rechnung ' . $r['rechnungsnummer'] . ' – ' . $betrieb['name'], $text, (string) $betrieb['name'], $betrieb['email'] ?: null, [$pfad => basename($pfad)]);
        $db->update('mahnungen', $mahnungId, ['gesendet_am' => date('Y-m-d H:i:s')]);
        $db->insert('benachrichtigungen', ['kanal' => 'mail', 'empfaenger_typ' => 'kunde', 'empfaenger_id' => (int) $k['id'], 'empfaenger_email' => $an, 'betreff' => $titel . ' ' . $r['rechnungsnummer'], 'inhalt' => $text, 'referenz_typ' => 'rechnung', 'referenz_id' => (int) $r['id'], 'status' => 'gesendet', 'gesendet_am' => date('Y-m-d H:i:s')]);
    }

    /** Storno: Gegenrechnung mit negativen Positionen, Original als storniert. Regie wieder freigeben. */
    public static function stornieren(int $id): string
    {
        $db = Database::get();
        $r = $db->find('rechnungen', $id);
        if ($r === null || $r['status'] === 'storniert' || $r['status'] === 'entwurf') { throw new RuntimeException('Nicht stornierbar.'); }
        $pos = $db->select('rechnung_positionen', 'rechnung_id = ?', [$id], 'ORDER BY sortierung');

        return $db->transaction(static function () use ($db, $id, $r, $pos): string {
            $neu = $db->insert('rechnungen', ['kunde_id' => (int) $r['kunde_id'], 'leistungszeitraum_von' => $r['leistungszeitraum_von'], 'leistungszeitraum_bis' => $r['leistungszeitraum_bis'],
                'ust_satz' => (float) $r['ust_satz'], 'status' => 'entwurf', 'storno_von_rechnung_id' => $id, 'notizen' => 'Storno zu ' . $r['rechnungsnummer']]);
            $i = 0;
            foreach ($pos as $p) {
                $db->insert('rechnung_positionen', ['rechnung_id' => $neu, 'objekt_id' => $p['objekt_id'], 'leistungsart_id' => $p['leistungsart_id'], 'beschreibung' => $p['beschreibung'],
                    'menge' => (float) $p['menge'], 'einheit' => $p['einheit'], 'einzelpreis' => -(float) $p['einzelpreis'], 'gesamtpreis' => -(float) $p['gesamtpreis'], 'sortierung' => $i++]);
            }
            $db->update('rechnungen', $neu, ['netto' => -(float) $r['netto'], 'ust' => -(float) $r['ust'], 'brutto' => -(float) $r['brutto']]);
            // Festschreiben ohne Betragspruefung
            $nummer = self::naechsteNummer((int) date('Y'));
            $db->update('rechnungen', $neu, ['rechnungsnummer' => $nummer, 'rechnungsdatum' => date('Y-m-d'), 'faellig_am' => date('Y-m-d'), 'status' => 'bezahlt', 'bezahlt_am' => date('Y-m-d'), 'bezahlt_betrag' => -(float) $r['brutto'], 'festgeschrieben_am' => date('Y-m-d H:i:s')]);
            $db->update('rechnungen', $id, ['status' => 'storniert']);
            $db->raw('UPDATE regieleistungen SET rechnung_id = NULL WHERE rechnung_id = ? AND betrieb_id = ?', [$id, $db->tenant()]);
            return $nummer;
        });
    }

    // ---------------------------------------------------------------
    // PDF
    // ---------------------------------------------------------------

    public static function pdfPfad(array $r): string
    {
        $db = Database::get();
        $name = $r['rechnungsnummer'] ? preg_replace('/[^A-Za-z0-9\-]/', '', $r['rechnungsnummer']) : 'Entwurf-' . (int) $r['id'];
        return self::PDF_DIR . '/' . $db->tenant() . '/' . $name . '.pdf';
    }

    public static function pdf(int $id, bool $neu = false): string
    {
        $db = Database::get();
        $r = $db->find('rechnungen', $id);
        if ($r === null) { throw new RuntimeException('Rechnung nicht gefunden.'); }
        $pfad = self::pdfPfad($r);
        if (is_file($pfad) && !$neu && $r['status'] !== 'entwurf') { return $pfad; }
        $dir = dirname($pfad);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) { throw new RuntimeException('PDF-Ordner nicht anlegbar.'); }

        require_once __DIR__ . '/lib/dompdf/autoload.inc.php';
        $opt = new \Dompdf\Options();
        $opt->set('isRemoteEnabled', false); $opt->set('defaultFont', 'DejaVu Sans');
        $opt->set('fontDir', __DIR__ . '/lib/dompdf/vendor/dompdf/dompdf/lib/fonts'); $opt->set('fontCache', self::PDF_DIR); $opt->set('chroot', dirname(__DIR__));
        $pdf = new \Dompdf\Dompdf($opt);
        $pdf->setPaper('A4'); $pdf->loadHtml(self::html($r), 'UTF-8'); $pdf->render();
        file_put_contents($pfad, $pdf->output());
        if ($r['status'] !== 'entwurf') { $db->update('rechnungen', $id, ['pdf_pfad' => basename($pfad)]); }
        return $pfad;
    }

    private static function html(array $r): string
    {
        $db = Database::get();
        $b = $db->rawOne('SELECT * FROM betriebe WHERE id = ?', [$db->tenant()]);
        $lay = $db->rawOne('SELECT * FROM rechnungs_layout WHERE betrieb_id = ?', [$db->tenant()]) ?? [];
        $k = $db->find('kunden', (int) $r['kunde_id']) ?? [];
        $pos = $db->select('rechnung_positionen', 'rechnung_id = ?', [(int) $r['id']], 'ORDER BY sortierung');
        $klein = self::kleinunternehmer();
        $storno = !empty($r['storno_von_rechnung_id']);
        $e = static fn(?string $x): string => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $g = static fn(float $x): string => number_format($x, 2, ',', '.') . ' €';
        $d = static fn(?string $x): string => $x ? date('d.m.Y', strtotime($x)) : '';
        $farbe = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($lay['farbe'] ?? $b['farbe_primaer'] ?? '')) ? ($lay['farbe'] ?? $b['farbe_primaer']) : '#0B5D4A';

        $logo = '';
        if (!empty($b['logo_pfad'])) {
            $lp = dirname(__DIR__) . '/uploads/' . $b['logo_pfad'];
            if (is_file($lp)) { $logo = '<img src="' . $e($lp) . '" style="max-height:18mm;max-width:60mm">'; }
        }
        $empf = $k['rechnungsadresse'] ? nl2br($e(str_replace(', ', "\n", $k['rechnungsadresse']))) : $e($k['firmenname'] ?? '') . ($k['adresse'] ? '<br>' . $e($k['adresse']) . '<br>' . $e($k['plz'] . ' ' . $k['ort']) : '');
        $uidK = !empty($k['uid_nummer']) ? '<br>UID ' . $e($k['uid_nummer']) : '';

        $zeilen = '';
        $zeigeDetail = (int) ($lay['zeige_positionen_detail'] ?? 1) === 1;
        foreach ($pos as $p) {
            $zeilen .= '<tr><td>' . $e($p['beschreibung']) . '</td>'
                . ($zeigeDetail ? '<td class="r">' . rtrim(rtrim(number_format((float) $p['menge'], 2, ',', '.'), '0'), ',') . ' ' . $e($p['einheit']) . '</td><td class="r">' . $g((float) $p['einzelpreis']) . '</td>' : '')
                . '<td class="r">' . $g((float) $p['gesamtpreis']) . '</td></tr>';
        }
        $titel = $r['status'] === 'entwurf' ? 'Rechnungsentwurf' : ($storno ? 'Gutschrift / Storno' : 'Rechnung');
        $nr = $r['rechnungsnummer'] ?: '— Entwurf —';
        $ustZeile = $klein ? '' : '<tr><td>zzgl. ' . number_format((float) $r['ust_satz'], 0) . ' % USt</td><td class="r">' . $g((float) $r['ust']) . '</td></tr>';
        $ustHinweis = $klein ? '<p class="klein">Umsatzsteuerbefreit gemäß § 6 Abs. 1 Z 27 UStG (Kleinunternehmerregelung).</p>' : '';
        $zahlung = $storno ? 'Der Betrag wird mit offenen Forderungen verrechnet oder rückerstattet.'
                 : ($lay['zahlungsbedingungen'] ?: 'Zahlbar bis ' . $d($r['faellig_am']) . ' ohne Abzug.') . ' Verwendungszweck: ' . $e($nr) . '.';
        $bank = implode(' · ', array_filter([$lay['bankname'] ?? '', ($lay['iban'] ?? '') !== '' ? 'IBAN ' . chunk_split((string) $lay['iban'], 4, ' ') : '', ($lay['bic'] ?? '') !== '' ? 'BIC ' . $lay['bic'] : '']));
        $fuss = implode(' · ', array_filter([$b['name'], trim(($b['adresse'] ?? '') . ', ' . ($b['plz'] ?? '') . ' ' . ($b['ort'] ?? ''), ', '), $b['telefon'] ?? '', $b['email'] ?? '', ($b['uid_nummer'] ?? '') !== '' ? 'UID ' . $b['uid_nummer'] : '']));
        $wasserzeichen = $r['status'] === 'entwurf' ? '<div style="position:fixed;top:40%;left:0;right:0;text-align:center;font-size:60pt;color:#E5E9E5;transform:rotate(-20deg);z-index:-1">ENTWURF</div>' : '';

        return '<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8"><style>
  @page{margin:20mm 20mm 26mm}body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;color:#13201B;line-height:1.45}
  .absender{font-size:7.5pt;color:#4E5C55;border-bottom:.4pt solid ' . $farbe . ';padding-bottom:2mm;margin-bottom:8mm}
  .kopf{width:100%}.kopf td{vertical-align:top}.empf{font-size:10.5pt;line-height:1.5}.meta{font-size:9pt;text-align:right}
  .meta b{display:inline-block;width:38mm;text-align:left;color:#4E5C55;font-weight:normal}
  h1{font-size:18pt;color:' . $farbe . ';margin:12mm 0 4mm;letter-spacing:-.02em}
  table.pos{width:100%;border-collapse:collapse;margin:4mm 0 5mm}table.pos th{text-align:left;font-size:8.5pt;color:#4E5C55;border-bottom:.8pt solid ' . $farbe . ';padding:0 0 2mm;font-weight:normal}
  table.pos td{padding:2.4mm 0;border-bottom:.3pt solid #DDE3DE;vertical-align:top}table.pos td.r,table.pos th.r{text-align:right;white-space:nowrap;padding-left:6mm}
  table.summe{width:70mm;margin-left:auto;border-collapse:collapse}table.summe td{padding:1.6mm 0}table.summe tr.ges td{border-top:.8pt solid ' . $farbe . ';font-weight:bold;font-size:11.5pt;padding-top:3mm}
  .klein{font-size:8.5pt;color:#4E5C55}.text{margin:0 0 3mm}.fuss{position:fixed;bottom:-16mm;left:0;right:0;font-size:7.5pt;color:#4E5C55;border-top:.4pt solid #DDE3DE;padding-top:2mm;line-height:1.5}
</style></head><body>' . $wasserzeichen . '
  <table class="kopf"><tr><td class="absender" style="border:0;padding:0">' . ($logo ?: '<b style="font-size:12pt;color:' . $farbe . '">' . $e($b['name']) . '</b>') . '</td><td style="text-align:right;font-size:8pt;color:#4E5C55">' . nl2br($e($lay['kopfzeile'] ?? '')) . '</td></tr></table>
  <div class="absender">' . $e($fuss) . '</div>
  <table class="kopf"><tr><td class="empf">' . $empf . $uidK . '</td><td class="meta"><b>Rechnungsnummer</b>' . $e($nr) . '<br><b>Rechnungsdatum</b>' . ($d($r['rechnungsdatum']) ?: $d(date('Y-m-d'))) . '<br><b>Leistungszeitraum</b>' . $d($r['leistungszeitraum_von']) . ' – ' . $d($r['leistungszeitraum_bis']) . ($r['faellig_am'] ? '<br><b>Fällig</b>' . $d($r['faellig_am']) : '') . '</td></tr></table>
  <h1>' . $titel . ($r['rechnungsnummer'] ? ' ' . $e($r['rechnungsnummer']) : '') . '</h1>
  ' . (($lay['einleitungstext'] ?? '') !== '' ? '<p class="text">' . nl2br($e($lay['einleitungstext'])) . '</p>' : '') . '
  <table class="pos"><tr><th>Leistung</th>' . ($zeigeDetail ? '<th class="r">Menge</th><th class="r">Einzelpreis</th>' : '') . '<th class="r">Betrag</th></tr>' . $zeilen . '</table>
  <table class="summe"><tr><td>Netto</td><td class="r">' . $g((float) $r['netto']) . '</td></tr>' . $ustZeile . '<tr class="ges"><td>Gesamt</td><td class="r">' . $g((float) $r['brutto']) . '</td></tr></table>' . $ustHinweis . '
  <p class="text" style="margin-top:8mm">' . $e($zahlung) . '</p>' . ($bank ? '<p class="klein">' . $e($bank) . '</p>' : '') . '
  ' . (($lay['schlusstext'] ?? '') !== '' ? '<p class="text" style="margin-top:6mm">' . nl2br($e($lay['schlusstext'])) . '</p>' : '') . '
  <div class="fuss">' . $e($fuss) . ($bank ? '<br>' . $e($bank) : '') . (($lay['fusszeile'] ?? '') !== '' ? '<br>' . nl2br($e($lay['fusszeile'])) : '') . '</div>
</body></html>';
    }

    /** Rechnung per Mail an den Kunden senden. */
    public static function senden(int $id, array $betrieb): void
    {
        $db = Database::get();
        $r = $db->find('rechnungen', $id);
        if ($r === null || $r['status'] === 'entwurf') { throw new RuntimeException('Nur festgeschriebene Rechnungen können versendet werden.'); }
        $k = $db->find('kunden', (int) $r['kunde_id']);
        $an = $k['rechnungs_email'] ?: $k['email'];
        if (!$an) { throw new RuntimeException('Beim Kunden ist keine E-Mail hinterlegt.'); }
        $pfad = self::pdf($id);
        $storno = !empty($r['storno_von_rechnung_id']);
        $text = "Guten Tag,\n\nanbei " . ($storno ? 'die Gutschrift ' : 'die Rechnung ') . $r['rechnungsnummer']
              . ($storno ? '' : ' über ' . number_format((float) $r['brutto'], 2, ',', '.') . ' € für den Zeitraum ' . date('d.m.Y', strtotime($r['leistungszeitraum_von'])) . ' bis ' . date('d.m.Y', strtotime($r['leistungszeitraum_bis'])) . ".\nZahlbar bis " . date('d.m.Y', strtotime($r['faellig_am'])) . ', Verwendungszweck ' . $r['rechnungsnummer'] . '.')
              . "\n\nMit freundlichen Grüßen\n" . $betrieb['name'];
        Mailer::send($an, (string) $k['firmenname'], ($storno ? 'Gutschrift ' : 'Rechnung ') . $r['rechnungsnummer'] . ' – ' . $betrieb['name'], $text, (string) $betrieb['name'], $betrieb['email'] ?: null, [$pfad => basename($pfad)]);
        $db->insert('benachrichtigungen', ['kanal' => 'mail', 'empfaenger_typ' => 'kunde', 'empfaenger_id' => (int) $k['id'], 'empfaenger_email' => $an,
            'betreff' => 'Rechnung ' . $r['rechnungsnummer'], 'inhalt' => $text, 'referenz_typ' => 'rechnung', 'referenz_id' => $id, 'status' => 'gesendet', 'gesendet_am' => date('Y-m-d H:i:s')]);
    }

    public static function monatName(int $m): string
    {
        return ['', 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'][$m] ?? '';
    }
}
