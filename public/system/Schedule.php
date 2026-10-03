<?php
/**
 * Dienstplan-Logik.
 *
 * - Einsaetze rollierend aus den Objekt-Leistungen erzeugen (idempotent)
 * - Konfliktpruefung bei Zuteilung
 * - Ersatzvorschlag aus Verfuegbarkeit, Abwesenheit und Belegung
 * - Kundenbenachrichtigung ueber die Ausgangswarteschlange
 *
 * Alle Methoden arbeiten im Mandantenkontext von Database.
 */

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use RuntimeException;

final class Schedule
{
    /** Wie weit im Voraus Einsaetze angelegt werden (Tage). */
    public const VORLAUF_TAGE = 56;

    // ---------------------------------------------------------------
    // Einsaetze erzeugen
    // ---------------------------------------------------------------

    /**
     * Legt fuer alle aktiven Objekt-Leistungen fehlende Einsaetze bis
     * heute + VORLAUF_TAGE an. Bestehende werden nie angeruehrt.
     *
     * @return int Anzahl neu angelegter Einsaetze
     */
    public static function generate(?string $bisDatum = null): int
    {
        $db = Database::get();
        $heute = new DateTimeImmutable('today');
        $bis = $bisDatum ? new DateTimeImmutable($bisDatum) : $heute->modify('+' . self::VORLAUF_TAGE . ' days');

        $leistungen = $db->rawAll(
            'SELECT ol.*, o.aktiv AS objekt_aktiv FROM objekt_leistungen ol JOIN objekte o ON o.id = ol.objekt_id
              WHERE ol.betrieb_id = ? AND ol.aktiv = 1 AND o.aktiv = 1', [$db->tenant()]
        );

        $neu = 0;
        foreach ($leistungen as $l) {
            $team = array_map('intval', array_column(
                $db->rawAll('SELECT mitarbeiter_id FROM objekt_leistung_mitarbeiter WHERE objekt_leistung_id = ? ORDER BY reihenfolge', [(int) $l['id']]),
                'mitarbeiter_id'
            ));
            $sub = $db->rawOne('SELECT subunternehmer_id FROM subunternehmer_objekte WHERE objekt_leistung_id = ? AND aktiv = 1 LIMIT 1', [(int) $l['id']]);

            foreach (self::termine($l, $heute, $bis) as $datum) {
                $vorhanden = $db->rawOne(
                    'SELECT id FROM einsaetze WHERE objekt_leistung_id = ? AND datum = ? LIMIT 1',
                    [(int) $l['id'], $datum]
                );
                if ($vorhanden !== null) {
                    continue;
                }
                $einsatzId = $db->insert('einsaetze', [
                    'objekt_id'          => (int) $l['objekt_id'],
                    'objekt_leistung_id' => (int) $l['id'],
                    'leistungsart_id'    => (int) $l['leistungsart_id'],
                    'subunternehmer_id'  => $sub ? (int) $sub['subunternehmer_id'] : null,
                    'datum'              => $datum,
                    'uhrzeit_von'        => $l['uhrzeit_von'] ?? '08:00:00',
                    'uhrzeit_bis'        => $l['uhrzeit_bis'] ?? '10:00:00',
                    'dauer_soll_minuten' => (int) $l['dauer_soll_minuten'],
                    'status'             => 'geplant',
                ]);
                foreach ($team as $mid) {
                    $db->insert('einsatz_mitarbeiter', ['einsatz_id' => $einsatzId, 'mitarbeiter_id' => $mid]);
                }
                $neu++;
            }
        }
        return $neu;
    }

    /** Liefert alle Termine (Y-m-d) einer Objekt-Leistung im Zeitraum. */
    public static function termine(array $l, DateTimeImmutable $von, DateTimeImmutable $bis): array
    {
        $start = $l['gueltig_von'] ? new DateTimeImmutable((string) $l['gueltig_von']) : $von;
        $ende  = $l['gueltig_bis'] ? new DateTimeImmutable((string) $l['gueltig_bis']) : $bis;
        if ($ende < $von || $start > $bis) {
            return [];
        }
        $a = max($start, $von);
        $b = min($ende, $bis);
        $maske = (int) $l['wochentage'];
        $out = [];

        if ($l['turnus'] === 'einmalig') {
            $d = $start;
            if ($d >= $von && $d <= $bis) {
                $out[] = $d->format('Y-m-d');
            }
            return $out;
        }

        // Referenzwoche fuer 14-taegig: Montag der Woche von gueltig_von (oder heute)
        $ref = ($l['gueltig_von'] ? new DateTimeImmutable((string) $l['gueltig_von']) : $von)->modify('monday this week');

        for ($d = $a; $d <= $b; $d = $d->modify('+1 day')) {
            $wt = (int) $d->format('N');   // 1 = Mo … 7 = So
            if (!($maske & (1 << ($wt - 1)))) {
                continue;
            }
            if ($l['turnus'] === 'woechentlich') {
                $out[] = $d->format('Y-m-d');
                continue;
            }
            if ($l['turnus'] === '14taegig') {
                $wochen = (int) floor(($d->modify('monday this week')->getTimestamp() - $ref->getTimestamp()) / 604800);
                if ($wochen % 2 === 0) {
                    $out[] = $d->format('Y-m-d');
                }
                continue;
            }
            if ($l['turnus'] === 'monatlich') {
                // Erstes Vorkommen des Wochentags im Monat
                if ((int) $d->format('j') <= 7) {
                    $out[] = $d->format('Y-m-d');
                }
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Konflikte
    // ---------------------------------------------------------------

    /**
     * Prueft, ob ein Mitarbeiter zu einem Zeitpunkt anderweitig belegt
     * oder abwesend ist. Liefert null (frei) oder einen Text.
     */
    public static function conflict(int $mitarbeiterId, string $datum, string $von, string $bis, ?int $ausserEinsatz = null): ?string
    {
        $db = Database::get();
        $abw = $db->rawOne(
            "SELECT typ FROM abwesenheiten WHERE mitarbeiter_id = ? AND betrieb_id = ? AND status = 'genehmigt' AND ? BETWEEN datum_von AND datum_bis LIMIT 1",
            [$mitarbeiterId, $db->tenant(), $datum]
        );
        if ($abw !== null) {
            return 'abwesend';
        }
        $params = [$mitarbeiterId, $db->tenant(), $datum, $bis, $von];
        $sql = "SELECT o.name FROM einsatz_mitarbeiter em JOIN einsaetze e ON e.id = em.einsatz_id JOIN objekte o ON o.id = e.objekt_id
                 WHERE em.mitarbeiter_id = ? AND em.betrieb_id = ? AND e.datum = ? AND e.status NOT IN ('storniert','ausgefallen')
                   AND e.uhrzeit_von < ? AND e.uhrzeit_bis > ?";
        if ($ausserEinsatz !== null) {
            $sql .= ' AND e.id <> ?';
            $params[] = $ausserEinsatz;
        }
        $row = $db->rawOne($sql . ' LIMIT 1', $params);
        return $row !== null ? 'bereits bei ' . $row['name'] : null;
    }

    // ---------------------------------------------------------------
    // Ersatzvorschlag
    // ---------------------------------------------------------------

    /**
     * Kandidaten fuer einen Einsatz, sortiert: verfuegbar laut Plan zuerst,
     * dann frei aber ohne Verfuegbarkeitsangabe. Abwesende und belegte
     * werden ausgelassen. Bereits zugeteilte ebenfalls.
     */
    public static function suggest(int $einsatzId): array
    {
        $db = Database::get();
        $e = $db->find('einsaetze', $einsatzId);
        if ($e === null) {
            return [];
        }
        $wt = (int) (new DateTimeImmutable((string) $e['datum']))->format('N');
        $zugeteilt = array_map('intval', array_column($db->rawAll('SELECT mitarbeiter_id FROM einsatz_mitarbeiter WHERE einsatz_id = ?', [$einsatzId]), 'mitarbeiter_id'));
        // Benoetigte Qualifikation der Leistungsart
        $la = $db->find('leistungsarten', (int) $e['leistungsart_id']);
        $qualId = isset($la['qualifikation_id']) ? (int) $la['qualifikation_id'] : 0;
        $qualName = $qualId > 0 ? ($db->find('qualifikationen', $qualId)['name'] ?? '') : '';
        // Wochengrenzen fuer die Auslastung
        $tag = new DateTimeImmutable((string) $e['datum']);
        $woVon = $tag->modify('monday this week')->format('Y-m-d'); $woBis = $tag->modify('sunday this week')->format('Y-m-d');
        $offeneAnfragen = array_map('intval', array_column($db->rawAll("SELECT mitarbeiter_id FROM einsatz_anfragen WHERE einsatz_id = ? AND status = 'offen'", [$einsatzId]), 'mitarbeiter_id'));

        $alle = $db->select('mitarbeiter', 'aktiv = 1', [], 'ORDER BY nachname, vorname');
        $vorschlag = [];
        foreach ($alle as $m) {
            $mid = (int) $m['id'];
            if (in_array($mid, $zugeteilt, true)) {
                continue;
            }
            $konflikt = self::conflict($mid, (string) $e['datum'], (string) $e['uhrzeit_von'], (string) $e['uhrzeit_bis'], $einsatzId);
            if ($konflikt !== null) {
                continue;
            }
            $qualOk = $qualId === 0 || $db->rawOne('SELECT 1 FROM mitarbeiter_qualifikationen WHERE mitarbeiter_id = ? AND qualifikation_id = ? LIMIT 1', [$mid, $qualId]) !== null;
            $wochenMin = (int) ($db->rawOne('SELECT IFNULL(SUM(e2.dauer_soll_minuten),0) s FROM einsatz_mitarbeiter em JOIN einsaetze e2 ON e2.id = em.einsatz_id WHERE em.mitarbeiter_id = ? AND e2.datum BETWEEN ? AND ? AND e2.status IN (\'geplant\',\'laeuft\',\'erledigt\')', [$mid, $woVon, $woBis])['s'] ?? 0);
            $verf = $db->rawOne(
                'SELECT id FROM mitarbeiter_verfuegbarkeit WHERE mitarbeiter_id = ? AND wochentag = ? AND uhrzeit_von <= ? AND uhrzeit_bis >= ? LIMIT 1',
                [$mid, $wt, $e['uhrzeit_von'], $e['uhrzeit_bis']]
            );
            $hatPlan = (int) ($db->rawOne('SELECT COUNT(*) n FROM mitarbeiter_verfuegbarkeit WHERE mitarbeiter_id = ?', [$mid])['n'] ?? 0) > 0;
            // Kennt das Objekt schon? (Stammteam einer Leistung dort)
            $kennt = $db->rawOne(
                'SELECT 1 FROM objekt_leistung_mitarbeiter olm JOIN objekt_leistungen ol ON ol.id = olm.objekt_leistung_id WHERE olm.mitarbeiter_id = ? AND ol.objekt_id = ? LIMIT 1',
                [$mid, (int) $e['objekt_id']]
            ) !== null;
            $vorschlag[] = [
                'id'   => $mid,
                'name' => $m['vorname'] . ' ' . $m['nachname'],
                'verfuegbar' => $verf !== null,
                'ohne_plan'  => !$hatPlan,
                'kennt_objekt' => $kennt,
                'telefon' => $m['telefon'],
                'qualifiziert' => $qualOk,
                'qualifikation' => $qualName,
                'wochen_std' => round($wochenMin / 60, 1),
                'wochen_soll' => (float) $m['stunden_woche'],
                'anfrage_offen' => in_array($mid, $offeneAnfragen, true),
            ];
        }
        usort($vorschlag, static fn($a, $b) =>
            [$b['qualifiziert'], $b['kennt_objekt'], $b['verfuegbar'], $b['ohne_plan'], $a['wochen_std'], $a['name']] <=> [$a['qualifiziert'], $a['kennt_objekt'], $a['verfuegbar'], $a['ohne_plan'], $b['wochen_std'], $b['name']]);
        return $vorschlag;
    }

    // ---------------------------------------------------------------
    // Benachrichtigung
    // ---------------------------------------------------------------

    /**
     * Stellt eine Kundenbenachrichtigung in die Warteschlange, wenn die
     * Regel des Betriebs das vorsieht. Der Grund einer Abwesenheit wird
     * nie uebermittelt.
     */
    public static function notify(int $einsatzId, string $ereignis, array $extra = []): bool
    {
        $db = Database::get();
        $regel = $db->selectOne('benachrichtigungs_regeln', 'ereignis = ? AND aktiv = 1', [$ereignis]);
        if ($regel === null || (int) $regel['kunde_informieren'] !== 1) {
            return false;
        }
        $e = $db->rawOne(
            'SELECT e.*, o.name AS objekt_name, o.email AS objekt_email, k.email AS kunde_email, k.firmenname
               FROM einsaetze e JOIN objekte o ON o.id = e.objekt_id JOIN kunden k ON k.id = o.kunde_id
              WHERE e.id = ? AND e.betrieb_id = ?', [$einsatzId, $db->tenant()]
        );
        if ($e === null) {
            return false;
        }
        $an = $e['objekt_email'] ?: $e['kunde_email'];
        if (!$an) {
            return false;
        }
        $platzhalter = [
            '{objekt}'       => $e['objekt_name'],
            '{kunde}'        => $e['firmenname'],
            '{datum}'        => date('d.m.Y', strtotime((string) $e['datum'])),
            '{uhrzeit}'      => substr((string) $e['uhrzeit_von'], 0, 5),
            '{neuer_termin}' => $extra['neuer_termin'] ?? '',
            '{betrieb}'      => $db->rawOne('SELECT name FROM betriebe WHERE id = ?', [$db->tenant()])['name'] ?? '',
        ];
        $db->insert('benachrichtigungen', [
            'kanal'            => 'mail',
            'empfaenger_typ'   => 'kunde',
            'empfaenger_id'    => null,
            'empfaenger_email' => $an,
            'betreff'          => strtr((string) $regel['vorlage_betreff'], $platzhalter),
            'inhalt'           => strtr((string) $regel['vorlage_text'], $platzhalter),
            'referenz_typ'     => 'einsatz',
            'referenz_id'      => $einsatzId,
        ]);
        $db->update('einsaetze', $einsatzId, ['kunde_informiert_am' => date('Y-m-d H:i:s')]);
        return true;
    }
}
