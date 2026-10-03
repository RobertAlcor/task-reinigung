<?php
/**
 * Abrechnung auf Anbieter-Ebene: Vertraege und Abo-Rechnungen an Betriebe.
 *
 * Rechnungen sind nach Ausstellung unveraenderbar; Korrektur nur per
 * Storno und Neuausstellung (§ 11 UStG, § 132 BAO).
 */

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use RuntimeException;

final class Billing
{
    /** Alle aktiven Tarife, sortiert. */
    public static function tarife(): array
    {
        return Database::get()->rawAll('SELECT * FROM anbieter_tarife WHERE aktiv = 1 ORDER BY sortierung');
    }

    public static function tarif(string $code): ?array
    {
        return Database::get()->rawOne('SELECT * FROM anbieter_tarife WHERE code = ? LIMIT 1', [$code]);
    }

    /** Vertrag eines Betriebs; legt bei Bedarf einen leeren an. */
    public static function vertrag(int $betriebId): array
    {
        $db = Database::get();
        $v = $db->rawOne('SELECT * FROM anbieter_vertraege WHERE betrieb_id = ?', [$betriebId]);
        if ($v === null) {
            $db->raw('INSERT INTO anbieter_vertraege (betrieb_id) VALUES (?)', [$betriebId]);
            $v = $db->rawOne('SELECT * FROM anbieter_vertraege WHERE betrieb_id = ?', [$betriebId]);
        }
        return $v;
    }

    /** Vertrag beim Anlegen eines Betriebs mit Tarifpreis initialisieren. */
    public static function initVertrag(int $betriebId, string $tarifCode, ?string $beginn, ?string $email): void
    {
        $t = self::tarif($tarifCode);
        Database::get()->raw(
            'INSERT INTO anbieter_vertraege (betrieb_id, preis_monat, vertragsbeginn, naechste_abrechnung, rechnungs_email)
             VALUES (?, ?, ?, ?, ?)',
            [$betriebId, $t['preis_monat'] ?? 0, $beginn, $beginn, $email]
        );
    }

    /** Vertragsdaten speichern. */
    public static function saveVertrag(int $betriebId, array $d): void
    {
        self::vertrag($betriebId);

        $zw = in_array($d['zahlungsweise'] ?? '', ['monatlich', 'jaehrlich'], true) ? $d['zahlungsweise'] : 'monatlich';
        $za = in_array($d['zahlungsart'] ?? '', ['ueberweisung', 'sepa'], true) ? $d['zahlungsart'] : 'ueberweisung';
        $ust = (float) str_replace(',', '.', (string) ($d['ust_satz'] ?? '20'));
        if (!in_array($ust, [0.0, 10.0, 13.0, 20.0], true)) {
            throw new RuntimeException('USt-Satz muss 0, 10, 13 oder 20 sein.');
        }

        Database::get()->raw(
            'UPDATE anbieter_vertraege SET
                preis_monat = ?, website_modul = ?, website_preis_monat = ?, einrichtung_einmalig = ?, rabatt_prozent = ?,
                zahlungsweise = ?, zahlungsart = ?, ust_satz = ?, vertragsbeginn = ?, naechste_abrechnung = ?,
                kuendigung_zum = ?, rechnungs_email = ?, rechnungsadresse = ?, uid_nummer = ?, notizen = ?
              WHERE betrieb_id = ?',
            [
                self::betrag($d['preis_monat'] ?? 0),
                !empty($d['website_modul']) ? 1 : 0,
                self::betrag($d['website_preis_monat'] ?? 0),
                self::betrag($d['einrichtung_einmalig'] ?? 0),
                self::betrag($d['rabatt_prozent'] ?? 0),
                $zw, $za, $ust,
                self::datum($d['vertragsbeginn'] ?? null),
                self::datum($d['naechste_abrechnung'] ?? null),
                self::datum($d['kuendigung_zum'] ?? null),
                self::leerNull($d['rechnungs_email'] ?? null, 150),
                self::leerNull($d['rechnungsadresse'] ?? null, 255),
                self::leerNull($d['uid_nummer'] ?? null, 20),
                self::leerNull($d['notizen'] ?? null, 4000),
                $betriebId,
            ]
        );
    }

    /**
     * Rechnung fuer den naechsten Abrechnungszeitraum erstellen.
     * Zeitraum ergibt sich aus naechste_abrechnung und Zahlungsweise.
     */
    public static function rechnungErstellen(int $betriebId): array
    {
        $db  = Database::get();
        $pdo = $db->pdo();
        $v   = self::vertrag($betriebId);
        $b   = $db->rawOne('SELECT name FROM betriebe WHERE id = ?', [$betriebId]);

        if (empty($v['naechste_abrechnung'])) {
            throw new RuntimeException('Im Vertrag fehlt das Datum der nächsten Abrechnung.');
        }
        $von = new DateTimeImmutable((string) $v['naechste_abrechnung']);
        $bis = $v['zahlungsweise'] === 'jaehrlich'
            ? $von->modify('+1 year')->modify('-1 day')
            : $von->modify('+1 month')->modify('-1 day');
        $monate = $v['zahlungsweise'] === 'jaehrlich' ? 12 : 1;
        $verrechnet = $v['zahlungsweise'] === 'jaehrlich' ? 11 : 1;    // Jahreszahlung: ein Monat geschenkt

        // Positionen
        $positionen = [];
        if ((float) ($v['einrichtung_einmalig'] ?? 0) > 0 && (int) ($v['einrichtung_verrechnet'] ?? 0) === 0) {
            $positionen[] = ['text' => 'Einrichtung und Datenübernahme, einmalig', 'betrag' => round((float) $v['einrichtung_einmalig'], 2)];
        }
        $grund = (float) $v['preis_monat'];
        if ($grund > 0) {
            $positionen[] = ['text' => 'Softwarenutzung ' . ($monate === 12 ? '12 Monate (11 verrechnet, ein Monat geschenkt)' : '1 Monat') . ', ' . $von->format('d.m.Y') . ' bis ' . $bis->format('d.m.Y'),
                             'betrag' => round($grund * $verrechnet, 2)];
        }
        if ((int) $v['website_modul'] === 1 && (float) $v['website_preis_monat'] > 0) {
            $positionen[] = ['text' => 'Website-Modul ' . ($monate === 12 ? '12 Monate (11 verrechnet)' : '1 Monat'),
                             'betrag' => round((float) $v['website_preis_monat'] * $verrechnet, 2)];
        }
        $summe = array_sum(array_column($positionen, 'betrag'));
        $rabatt = (float) $v['rabatt_prozent'];
        if ($rabatt > 0 && $summe > 0) {
            $r = round($summe * $rabatt / 100, 2);
            $positionen[] = ['text' => "Rabatt {$rabatt} %", 'betrag' => -$r];
            $summe -= $r;
        }
        if ($positionen === [] || $summe <= 0) {
            throw new RuntimeException('Der Vertrag ergibt keinen Rechnungsbetrag. Bitte Preis eintragen.');
        }

        $netto  = round($summe, 2);
        $ustS   = (float) $v['ust_satz'];
        $ust    = round($netto * $ustS / 100, 2);
        $brutto = round($netto + $ust, 2);
        $heute  = new DateTimeImmutable('today');
        $faellig = $heute->modify('+14 days');

        $pdo->beginTransaction();
        try {
            $jahr = (int) $heute->format('Y');
            $db->raw('INSERT INTO anbieter_nummernkreis (jahr, letzte_nummer) VALUES (?, 0) ON DUPLICATE KEY UPDATE jahr = jahr', [$jahr]);
            $db->raw('UPDATE anbieter_nummernkreis SET letzte_nummer = LAST_INSERT_ID(letzte_nummer + 1) WHERE jahr = ?', [$jahr]);
            $nr = (int) $pdo->lastInsertId();
            $nummer = sprintf('RE-A-%d-%04d', $jahr, $nr);

            $db->raw(
                'INSERT INTO anbieter_rechnungen
                   (betrieb_id, rechnungsnummer, rechnungsdatum, zeitraum_von, zeitraum_bis, faellig_am, positionen, netto, ust_satz, ust, brutto, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'offen\')',
                [
                    $betriebId, $nummer, $heute->format('Y-m-d'), $von->format('Y-m-d'), $bis->format('Y-m-d'),
                    $faellig->format('Y-m-d'), json_encode($positionen, JSON_UNESCAPED_UNICODE), $netto, $ustS, $ust, $brutto,
                ]
            );
            $id = (int) $pdo->lastInsertId();

            // Naechste Abrechnung vorruecken, Einrichtung als verrechnet markieren
            $db->raw('UPDATE anbieter_vertraege SET naechste_abrechnung = ?, einrichtung_verrechnet = IF(einrichtung_einmalig > 0, 1, einrichtung_verrechnet) WHERE betrieb_id = ?',
                [$bis->modify('+1 day')->format('Y-m-d'), $betriebId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['id' => $id, 'nummer' => $nummer, 'brutto' => $brutto];
    }

    public static function alsBezahlt(int $rechnungId, ?string $datum = null): void
    {
        Database::get()->raw(
            "UPDATE anbieter_rechnungen SET status = 'bezahlt', bezahlt_am = ? WHERE id = ? AND status = 'offen'",
            [$datum ?: date('Y-m-d'), $rechnungId]
        );
    }

    public static function wiederOffen(int $rechnungId): void
    {
        Database::get()->raw(
            "UPDATE anbieter_rechnungen SET status = 'offen', bezahlt_am = NULL WHERE id = ? AND status = 'bezahlt'",
            [$rechnungId]
        );
    }

    /** Storno: erzeugt eine Gegenrechnung mit negativen Betraegen, Original wird als storniert markiert. */
    public static function stornieren(int $rechnungId): string
    {
        $db  = Database::get();
        $pdo = $db->pdo();
        $r   = $db->rawOne('SELECT * FROM anbieter_rechnungen WHERE id = ?', [$rechnungId]);
        if ($r === null || $r['status'] === 'storniert') {
            throw new RuntimeException('Rechnung nicht gefunden oder bereits storniert.');
        }

        $pdo->beginTransaction();
        try {
            $jahr = (int) date('Y');
            $db->raw('INSERT INTO anbieter_nummernkreis (jahr, letzte_nummer) VALUES (?, 0) ON DUPLICATE KEY UPDATE jahr = jahr', [$jahr]);
            $db->raw('UPDATE anbieter_nummernkreis SET letzte_nummer = LAST_INSERT_ID(letzte_nummer + 1) WHERE jahr = ?', [$jahr]);
            $nummer = sprintf('RE-A-%d-%04d', $jahr, (int) $pdo->lastInsertId());

            $pos = json_decode((string) $r['positionen'], true) ?: [];
            foreach ($pos as &$p) {
                $p['betrag'] = -(float) $p['betrag'];
            }
            array_unshift($pos, ['text' => 'Storno zu Rechnung ' . $r['rechnungsnummer'], 'betrag' => 0]);

            $db->raw(
                'INSERT INTO anbieter_rechnungen
                   (betrieb_id, rechnungsnummer, rechnungsdatum, zeitraum_von, zeitraum_bis, faellig_am, positionen, netto, ust_satz, ust, brutto, status, storno_von_id, bezahlt_am)
                 VALUES (?, ?, CURDATE(), ?, ?, CURDATE(), ?, ?, ?, ?, ?, \'bezahlt\', ?, CURDATE())',
                [
                    (int) $r['betrieb_id'], $nummer, $r['zeitraum_von'], $r['zeitraum_bis'],
                    json_encode($pos, JSON_UNESCAPED_UNICODE),
                    -(float) $r['netto'], (float) $r['ust_satz'], -(float) $r['ust'], -(float) $r['brutto'],
                    $rechnungId,
                ]
            );
            $db->raw("UPDATE anbieter_rechnungen SET status = 'storniert' WHERE id = ?", [$rechnungId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $nummer;
    }

    public static function rechnungen(int $betriebId): array
    {
        return Database::get()->rawAll(
            'SELECT * FROM anbieter_rechnungen WHERE betrieb_id = ? ORDER BY rechnungsdatum DESC, id DESC', [$betriebId]
        );
    }

    /** Kennzahlen fuer die Uebersicht. */
    public static function kennzahlen(): array
    {
        $db = Database::get();
        $mrr = $db->rawOne(
            "SELECT IFNULL(SUM(monatsbetrag_netto),0) AS s FROM v_anbieter_betriebe WHERE status = 'aktiv'"
        );
        $offen = $db->rawOne(
            "SELECT IFNULL(SUM(brutto),0) AS s, COUNT(*) AS n FROM anbieter_rechnungen WHERE status = 'offen'"
        );
        $ueber = $db->rawOne(
            "SELECT IFNULL(SUM(brutto),0) AS s, COUNT(*) AS n FROM anbieter_rechnungen WHERE status = 'offen' AND faellig_am < CURDATE()"
        );
        $faellig = $db->rawOne(
            "SELECT COUNT(*) AS n FROM anbieter_vertraege v JOIN betriebe b ON b.id = v.betrieb_id
              WHERE b.status = 'aktiv' AND v.naechste_abrechnung <= CURDATE()"
        );
        return [
            'mrr_netto'        => (float) $mrr['s'],
            'offen_brutto'     => (float) $offen['s'],
            'offen_anzahl'     => (int) $offen['n'],
            'ueberfaellig_brutto' => (float) $ueber['s'],
            'ueberfaellig_anzahl' => (int) $ueber['n'],
            'abrechnung_faellig'  => (int) $faellig['n'],
        ];
    }

    // ---------------------------------------------------------------

    private static function betrag(mixed $v): float
    {
        $s = str_replace([' ', ','], ['', '.'], (string) $v);
        return is_numeric($s) ? round((float) $s, 2) : 0.0;
    }

    private static function datum(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new RuntimeException("Ungültiges Datum: {$v}");
        }
        return $v;
    }

    private static function leerNull(?string $v, int $max): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
