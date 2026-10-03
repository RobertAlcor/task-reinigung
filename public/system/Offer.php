<?php
/**
 * Angebote des Betriebs — entstehen aus einer Besichtigung oder frei.
 * Kalkulation nach dem bewaehrten Schema: Flaeche / qm pro Stunde
 * + Zuschlaege je Sanitaereinheit, Kueche, Empfang, Besprechung
 * × Zustandsfaktor × Einsaetze pro Monat × Stundensatz.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Offer
{
    public const KALK = ['qm_pro_stunde' => 55, 'wc' => 15, 'urinal' => 5, 'dusche' => 10, 'kueche' => 20, 'empfang' => 10, 'besprechung' => 15];
    public const EINSAETZE = ['5x' => 21.67, '4x' => 17.33, '3x' => 13, '2x' => 8.67, '1x' => 4.33, '2x-monat' => 2, '1x-monat' => 1];
    public const INTERVALL_TEXT = ['5x' => '5× wöchentlich', '4x' => '4× wöchentlich', '3x' => '3× wöchentlich', '2x' => '2× wöchentlich', '1x' => '1× wöchentlich', '2x-monat' => '2× monatlich', '1x-monat' => '1× monatlich'];
    private const PDF_DIR = __DIR__ . '/rechnungen';

    /** Stunden je Einsatz und Monatspreis aus Besichtigungsdaten. */
    public static function kalkulieren(array $b): array
    {
        $min = ((float) ($b['gesamtflaeche'] ?? 0) / self::KALK['qm_pro_stunde']) * 60;
        $min += (int) ($b['wc'] ?? 0) * self::KALK['wc'] + (int) ($b['urinale'] ?? 0) * self::KALK['urinal'] + (int) ($b['duschen'] ?? 0) * self::KALK['dusche'];
        if ((int) ($b['kueche'] ?? 0) > 0) { $min += self::KALK['kueche'] * max(1, (int) $b['kueche']); }
        if (!empty($b['empfang'])) { $min += self::KALK['empfang']; }
        if ((int) ($b['besprechungsraeume'] ?? 0) > 0) { $min += self::KALK['besprechung'] * (int) $b['besprechungsraeume']; }
        $eindruck = (int) ($b['gesamteindruck'] ?? 3);           // 1 = sehr schlecht … 5 = sehr gut
        $faktor = match (true) { $eindruck <= 2 => 1.3, $eindruck >= 4 => 0.9, default => 1.0 };
        $min *= $faktor;
        $stunden = round($min / 60, 1);
        $einsaetze = self::EINSAETZE[$b['intervall'] ?? '1x'] ?? 4.33;
        $stundenMonat = round($stunden * $einsaetze, 1);
        $satz = (float) ($b['stundensatz'] ?? 0);
        if ($satz <= 0) { $satz = self::standardSatz(); }
        $rabatt = (float) ($b['rabatt_prozent'] ?? 0);
        $material = (float) ($b['material_pauschale'] ?? 0);
        $netto = round($stundenMonat * $satz * (1 - $rabatt / 100) + $material, 2);
        return ['stunden_einsatz' => $stunden, 'einsaetze_monat' => $einsaetze, 'stunden_monat' => $stundenMonat, 'faktor' => $faktor, 'netto_monat' => $netto];
    }

    /** Standard-Stundensatz der Unterhaltsreinigung des Betriebs. */
    public static function standardSatz(): float
    {
        $l = Database::get()->selectOne('leistungsarten', "kategorie = 'unterhalt' AND aktiv = 1");
        return (float) ($l['standard_stundensatz'] ?? 35);
    }

    /** Angebot aus einer Besichtigung erzeugen (Entwurf → sofort gesendet-faehig). */
    public static function ausBesichtigung(int $besichtigungId, array $daten = []): int
    {
        $db = Database::get();
        $b = $db->find('besichtigungen', $besichtigungId);
        if ($b === null) { throw new RuntimeException('Besichtigung nicht gefunden.'); }
        if ($b['kunde_id'] === null) { throw new RuntimeException('Der Besichtigung ist noch kein Kunde zugeordnet.'); }
        if ((float) ($b['stundensatz'] ?? 0) <= 0) { $b['stundensatz'] = self::standardSatz(); }
        $k = self::kalkulieren($b);
        $ust = CustomerInvoice::kleinunternehmer() ? 0.0 : CustomerInvoice::ustSatz();

        return $db->transaction(static function () use ($db, $b, $k, $ust, $daten): int {
            $jahr = (int) date('Y');
            $db->raw("INSERT INTO nummernkreise (betrieb_id, typ, jahr, praefix, letzte_nummer) VALUES (?, 'angebot', ?, 'AN', 0) ON DUPLICATE KEY UPDATE jahr = jahr", [$db->tenant(), $jahr]);
            $db->raw("UPDATE nummernkreise SET letzte_nummer = LAST_INSERT_ID(letzte_nummer + 1) WHERE betrieb_id = ? AND typ = 'angebot' AND jahr = ?", [$db->tenant(), $jahr]);
            $nr = (int) $db->pdo()->lastInsertId();
            if ($nr < 1) { $nr = (int) ($db->rawOne("SELECT letzte_nummer FROM nummernkreise WHERE betrieb_id = ? AND typ = 'angebot' AND jahr = ?", [$db->tenant(), $jahr])['letzte_nummer'] ?? 1); }
            $nummer = sprintf('AN-%d-%04d', $jahr, $nr);

            $netto = $k['netto_monat']; $ustB = round($netto * $ust / 100, 2);
            $id = $db->insert('angebote', ['kunde_id' => (int) $b['kunde_id'], 'besichtigung_id' => (int) $b['id'], 'angebotsnummer' => $nummer, 'datum' => date('Y-m-d'),
                'gueltig_bis' => date('Y-m-d', strtotime('+30 days')), 'netto' => $netto, 'ust_satz' => $ust, 'ust' => $ustB, 'brutto' => round($netto + $ustB, 2), 'status' => 'entwurf']);
            $lu = $db->selectOne('leistungsarten', "kategorie = 'unterhalt' AND aktiv = 1");
            $intervall = self::INTERVALL_TEXT[$b['intervall'] ?? '1x'] ?? $b['intervall'];
            $pos = [];
            $pos[] = ['leistungsart_id' => $lu['id'] ?? null, 'beschreibung' => 'Unterhaltsreinigung ' . $intervall . ', ' . self::zahl($k['stunden_einsatz']) . ' Std je Einsatz, ' . self::zahl($k['stunden_monat']) . ' Std im Monat', 'menge' => 1, 'einheit' => 'Monat',
                'einzelpreis' => round($k['stunden_monat'] * (float) $b['stundensatz'] * (1 - (float) $b['rabatt_prozent'] / 100), 2)];
            if ((float) $b['material_pauschale'] > 0) { $pos[] = ['leistungsart_id' => null, 'beschreibung' => 'Reinigungsmaterial und Verbrauchsmittel', 'menge' => 1, 'einheit' => 'Monat', 'einzelpreis' => (float) $b['material_pauschale']]; }
            foreach ($pos as $i => $p) { $db->insert('angebot_positionen', $p + ['angebot_id' => $id, 'gesamtpreis' => round($p['menge'] * $p['einzelpreis'], 2), 'sortierung' => $i]); }
            $db->update('besichtigungen', (int) $b['id'], ['status' => 'angebot_erstellt', 'stundensatz' => (float) $b['stundensatz'], 'kalk_stunden_einsatz' => $k['stunden_einsatz'], 'kalk_preis_monat' => $netto]);
            return $id;
        });
    }

    public static function status(int $id, string $status): void
    {
        if (!in_array($status, ['entwurf', 'gesendet', 'angenommen', 'abgelehnt', 'abgelaufen'], true)) { throw new RuntimeException('Unbekannter Status.'); }
        $db = Database::get();
        $db->update('angebote', $id, ['status' => $status] + ($status === 'gesendet' ? ['gesendet_am' => date('Y-m-d H:i:s')] : []));
    }

    /**
     * Angenommenes Angebot uebernehmen: Objekt und Objekt-Leistung anlegen,
     * Kunde auf aktiv. Gibt die Objekt-ID zurueck.
     */
    public static function uebernehmen(int $angebotId): int
    {
        $db = Database::get();
        $a = $db->find('angebote', $angebotId);
        if ($a === null) { throw new RuntimeException('Angebot nicht gefunden.'); }
        $b = $a['besichtigung_id'] ? $db->find('besichtigungen', (int) $a['besichtigung_id']) : null;
        if ($b === null) { throw new RuntimeException('Dieses Angebot hat keine Besichtigung — Objekt bitte manuell anlegen.'); }
        $k = $db->find('kunden', (int) $a['kunde_id']);
        $lu = $db->selectOne('leistungsarten', "kategorie = 'unterhalt' AND aktiv = 1");

        return $db->transaction(static function () use ($db, $a, $b, $k, $lu): int {
            $db->update('kunden', (int) $k['id'], ['status' => 'aktiv']);
            $oid = $db->insert('objekte', ['kunde_id' => (int) $k['id'], 'name' => (($b['objektart'] && stripos((string) $k['firmenname'], (string) $b['objektart']) === false) ? $b['objektart'] . ' ' : '') . $k['firmenname'], 'adresse' => $k['adresse'] ?: '—', 'plz' => $k['plz'] ?: '—', 'ort' => $k['ort'] ?: 'Wien',
                'ansprechpartner' => $b['ansprechpartner'], 'telefon' => $b['ansprechpartner_telefon'], 'zugang_info' => trim(($b['zutritt'] ?: '') . ($b['schluessel_abholung'] ? "\nSchlüssel: " . $b['schluessel_abholung'] : '') . ($b['parkmoeglichkeit'] ? "\nParken: " . $b['parkmoeglichkeit'] : '')) ?: null,
                'reinigung_hinweise' => trim(($b['hygiene_info'] ?: '') . ($b['sicherheit_info'] ? "\n" . $b['sicherheit_info'] : '') . ($b['erwartung_info'] ? "\n" . $b['erwartung_info'] : '')) ?: null, 'qr_token' => Crypto::token(16)]);
            $tage = self::wochentageAusText((string) $b['reinigungstage']);
            $kalk = self::kalkulieren($b);
            $turnus = match ($b['intervall']) { '2x-monat' => '14taegig', '1x-monat' => 'monatlich', default => 'woechentlich' };
            $db->insert('objekt_leistungen', ['objekt_id' => $oid, 'leistungsart_id' => $lu['id'] ?? null, 'turnus' => $turnus, 'wochentage' => $tage ?: 21, 'uhrzeit_von' => $b['uhrzeit_von'] ?: '18:00:00', 'uhrzeit_bis' => $b['uhrzeit_bis'] ?: '20:00:00',
                'dauer_soll_minuten' => max(15, (int) round($kalk['stunden_einsatz'] * 60)), 'stundensatz' => (float) $b['stundensatz'] ?: null, 'preis_monat' => (float) $a['netto'], 'gueltig_von' => date('Y-m-d'), 'aktiv' => 1]);
            $db->update('angebote', (int) $a['id'], ['status' => 'angenommen']);
            return $oid;
        });
    }

    private static function wochentageAusText(string $t): int
    {
        $m = 0; $map = ['mo' => 1, 'di' => 2, 'mi' => 4, 'do' => 8, 'fr' => 16, 'sa' => 32, 'so' => 64];
        foreach ($map as $k => $v) { if (stripos($t, $k) !== false) { $m |= $v; } }
        return $m;
    }

    // ---------------------------------------------------------------
    // PDF
    // ---------------------------------------------------------------

    public static function pdf(int $id): string
    {
        $db = Database::get();
        $a = $db->find('angebote', $id);
        if ($a === null) { throw new RuntimeException('Angebot nicht gefunden.'); }
        $dir = self::PDF_DIR . '/' . $db->tenant(); if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
        $pfad = $dir . '/' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) $a['angebotsnummer']) . '.pdf';
        require_once __DIR__ . '/lib/dompdf/autoload.inc.php';
        $opt = new \Dompdf\Options(); $opt->set('isRemoteEnabled', false); $opt->set('defaultFont', 'DejaVu Sans');
        $opt->set('fontDir', __DIR__ . '/lib/dompdf/vendor/dompdf/dompdf/lib/fonts'); $opt->set('fontCache', self::PDF_DIR); $opt->set('chroot', dirname(__DIR__));
        $pdf = new \Dompdf\Dompdf($opt); $pdf->setPaper('A4'); $pdf->loadHtml(self::html($a), 'UTF-8'); $pdf->render();
        file_put_contents($pfad, $pdf->output());
        $db->update('angebote', $id, ['pdf_pfad' => basename($pfad)]);
        return $pfad;
    }

    private static function html(array $a): string
    {
        $db = Database::get();
        $b = $db->rawOne('SELECT * FROM betriebe WHERE id = ?', [$db->tenant()]);
        $lay = $db->rawOne('SELECT * FROM rechnungs_layout WHERE betrieb_id = ?', [$db->tenant()]) ?? [];
        $k = $db->find('kunden', (int) $a['kunde_id']) ?? [];
        $bes = $a['besichtigung_id'] ? $db->find('besichtigungen', (int) $a['besichtigung_id']) : null;
        $pos = $db->select('angebot_positionen', 'angebot_id = ?', [(int) $a['id']], 'ORDER BY sortierung');
        $klein = CustomerInvoice::kleinunternehmer();
        $e = static fn(?string $x): string => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $g = static fn(float $x): string => number_format($x, 2, ',', '.') . ' €';
        $d = static fn(?string $x): string => $x ? date('d.m.Y', strtotime($x)) : '';
        $farbe = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($lay['farbe'] ?? $b['farbe_primaer'] ?? '')) ? ($lay['farbe'] ?? $b['farbe_primaer']) : '#0B5D4A';
        $logo = ''; if (!empty($b['logo_pfad'])) { $lp = dirname(__DIR__) . '/uploads/' . $b['logo_pfad']; if (is_file($lp)) { $logo = '<img src="' . $e($lp) . '" style="max-height:18mm;max-width:60mm">'; } }
        $empf = $e($k['firmenname'] ?? '') . ($k['kontaktperson'] ? '<br>z. H. ' . $e($k['kontaktperson']) : '') . ($k['adresse'] ? '<br>' . $e($k['adresse']) . '<br>' . $e($k['plz'] . ' ' . $k['ort']) : '');
        $fuss = implode(' · ', array_filter([$b['name'], trim(($b['adresse'] ?? '') . ', ' . ($b['plz'] ?? '') . ' ' . ($b['ort'] ?? ''), ', '), $b['telefon'] ?? '', $b['email'] ?? '', ($b['uid_nummer'] ?? '') !== '' ? 'UID ' . $b['uid_nummer'] : '']));
        $zeilen = ''; foreach ($pos as $p) { $zeilen .= '<tr><td>' . $e($p['beschreibung']) . '</td><td class="r">' . $g((float) $p['gesamtpreis']) . '</td></tr>'; }
        $umfang = '';
        if ($bes) {
            $teile = array_filter([
                $bes['gesamtflaeche'] ? self::zahl((float) $bes['gesamtflaeche']) . ' m² Fläche' : '', $bes['anzahl_raeume'] ? (int) $bes['anzahl_raeume'] . ' Räume' : '',
                ((int) $bes['wc'] + (int) $bes['urinale'] + (int) $bes['duschen']) > 0 ? ((int) $bes['wc'] + (int) $bes['urinale'] + (int) $bes['duschen']) . ' Sanitäreinheiten' : '',
                (int) $bes['kueche'] > 0 ? 'Küche' : '', $bes['empfang'] ? 'Empfang' : '', (int) $bes['besprechungsraeume'] > 0 ? (int) $bes['besprechungsraeume'] . ' Besprechungsräume' : '',
                $bes['reinigungstage'] ? 'Reinigungstage ' . $bes['reinigungstage'] : '', $bes['uhrzeit_von'] ? 'ab ' . substr($bes['uhrzeit_von'], 0, 5) . ' Uhr' : '',
            ]);
            $umfang = '<h2>Leistungsumfang</h2><p>' . $e(implode(' · ', $teile)) . '</p><p>Unterhaltsreinigung aller Bodenflächen, Sanitäranlagen, Küche und Gemeinschaftsflächen, Entleerung der Papierkörbe, Reinigung der Griffflächen. Fensterreinigung, Grundreinigung und Sonderleistungen auf Anfrage gesondert.</p>';
        }
        return '<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8"><style>
  @page{margin:20mm 20mm 26mm}body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;color:#13201B;line-height:1.5}
  .absender{font-size:7.5pt;color:#4E5C55;border-bottom:.4pt solid ' . $farbe . ';padding-bottom:2mm;margin-bottom:8mm}.kopf{width:100%}.kopf td{vertical-align:top}
  .meta{font-size:9pt;text-align:right}.meta b{display:inline-block;width:32mm;text-align:left;color:#4E5C55;font-weight:normal}
  h1{font-size:18pt;color:' . $farbe . ';margin:12mm 0 4mm}h2{font-size:11pt;color:' . $farbe . ';margin:6mm 0 2mm}p{margin:0 0 2.5mm}
  table.pos{width:100%;border-collapse:collapse;margin:3mm 0 4mm}table.pos th{text-align:left;font-size:8.5pt;color:#4E5C55;border-bottom:.8pt solid ' . $farbe . ';padding:0 0 2mm;font-weight:normal}table.pos td{padding:2.4mm 0;border-bottom:.3pt solid #DDE3DE}table.pos td.r,table.pos th.r{text-align:right;white-space:nowrap;padding-left:6mm}
  table.summe{width:70mm;margin-left:auto;border-collapse:collapse}table.summe td{padding:1.6mm 0}table.summe tr.ges td{border-top:.8pt solid ' . $farbe . ';font-weight:bold;font-size:11.5pt;padding-top:3mm}
  .klein{font-size:8.5pt;color:#4E5C55}.fuss{position:fixed;bottom:-16mm;left:0;right:0;font-size:7.5pt;color:#4E5C55;border-top:.4pt solid #DDE3DE;padding-top:2mm}
</style></head><body>
  <table class="kopf"><tr><td>' . ($logo ?: '<b style="font-size:12pt;color:' . $farbe . '">' . $e($b['name']) . '</b>') . '</td></tr></table><div class="absender">' . $e($fuss) . '</div>
  <table class="kopf"><tr><td style="font-size:10.5pt;line-height:1.5">' . $empf . '</td><td class="meta"><b>Angebot</b>' . $e($a['angebotsnummer']) . '<br><b>Datum</b>' . $d($a['datum']) . '<br><b>Gültig bis</b>' . $d($a['gueltig_bis']) . '</td></tr></table>
  <h1>Angebot ' . $e($a['angebotsnummer']) . '</h1>
  <p>Vielen Dank für Ihr Interesse. Auf Basis der Besichtigung unterbreiten wir Ihnen folgendes Angebot für die laufende Reinigung Ihrer Räumlichkeiten:</p>
  ' . $umfang . '
  <h2>Preis</h2><table class="pos"><tr><th>Leistung</th><th class="r">monatlich netto</th></tr>' . $zeilen . '</table>
  <table class="summe"><tr><td>Netto pro Monat</td><td class="r">' . $g((float) $a['netto']) . '</td></tr>' . ($klein ? '' : '<tr><td>zzgl. ' . number_format((float) $a['ust_satz'], 0) . ' % USt</td><td class="r">' . $g((float) $a['ust']) . '</td></tr>') . '<tr class="ges"><td>Gesamt pro Monat</td><td class="r">' . $g((float) $a['brutto']) . '</td></tr></table>
  ' . ($klein ? '<p class="klein">Umsatzsteuerbefreit gemäß § 6 Abs. 1 Z 27 UStG.</p>' : '') . '
  <h2>Bedingungen</h2><p>Monatliche Abrechnung im Nachhinein, zahlbar innerhalb von ' . (int) ($k['zahlungsziel_tage'] ?? 14) . ' Tagen. Mindestlaufzeit zwölf Monate, danach monatlich kündbar mit einer Frist von einem Monat. Preise gelten bei gleichbleibendem Leistungsumfang; Anpassungen bei Änderung des Kollektivvertrags werden angekündigt. Reinigungsmaterial ' . ((float) ($bes['material_pauschale'] ?? 0) > 0 || (int) ($bes['material_stellt_kunde'] ?? 0) === 1 ? ((int) ($bes['material_stellt_kunde'] ?? 0) === 1 ? 'wird vom Auftraggeber gestellt.' : 'ist in der Materialpauschale enthalten.') : 'wird gesondert verrechnet.') . '</p>
  <p>Wir freuen uns auf die Zusammenarbeit.</p><p style="margin-top:8mm">' . $e($b['name']) . '</p>
  <div class="fuss">' . $e($fuss) . '</div></body></html>';
    }

    public static function zahl(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
    }
}
