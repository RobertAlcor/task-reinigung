<?php
/**
 * Vertragsurkunde je Kunde: unterschriftsreifer Einzelvertrag mit den
 * Daten aus anbieter_vertraege, gefolgt von AGB und AV-Vertrag als
 * Anlagen. Damit liegt ein vollstaendiges, unterschriebenes Dokument vor
 * (Beweissicherung), waehrend die Zustimmung in der Software weiterhin
 * beim ersten Login protokolliert wird.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Contract
{
    private const DIR = __DIR__ . '/vertraege';

    /** Erzeugt (oder liefert) die PDF-Urkunde fuer einen Betrieb. */
    public static function pdf(int $betriebId, bool $neu = false): string
    {
        $db = Database::get();
        $b  = $db->rawOne('SELECT * FROM betriebe WHERE id = ?', [$betriebId]);
        if ($b === null) { throw new RuntimeException('Betrieb nicht gefunden.'); }
        $v = Billing::vertrag($betriebId);
        $s = Invoice::settings();
        foreach (['firma', 'adresse', 'plz', 'ort'] as $pflicht) {
            if (($s[$pflicht] ?? '') === '') { throw new RuntimeException('Bitte zuerst die eigenen Firmendaten vollständig ausfüllen (Einstellungen).'); }
        }

        if (!is_dir(self::DIR) && !mkdir(self::DIR, 0750, true)) { throw new RuntimeException('Ordner für Verträge nicht anlegbar.'); }
        $pfad = self::DIR . '/Vertrag-' . $betriebId . '-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $b['slug']) . '.pdf';
        if (is_file($pfad) && !$neu) { return $pfad; }

        require_once __DIR__ . '/lib/dompdf/autoload.inc.php';
        $opt = new \Dompdf\Options();
        $opt->set('isRemoteEnabled', false);
        $opt->set('defaultFont', 'DejaVu Sans');
        $opt->set('fontDir', __DIR__ . '/lib/dompdf/vendor/dompdf/dompdf/lib/fonts');
        $opt->set('fontCache', self::DIR);
        $opt->set('chroot', dirname(__DIR__));
        $pdf = new \Dompdf\Dompdf($opt);
        $pdf->setPaper('A4');
        $pdf->loadHtml(self::html($b, $v, $s), 'UTF-8');
        $pdf->render();
        file_put_contents($pfad, $pdf->output());
        return $pfad;
    }

    private static function html(array $b, array $v, array $s): string
    {
        $e = static fn(?string $x): string => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $g = static fn(float $x): string => number_format($x, 2, ',', '.') . ' €';
        $d = static fn(?string $x): string => $x ? date('d.m.Y', strtotime($x)) : '—';

        $tarif = Billing::tarif((string) $b['tarif']);
        $tarifName = $tarif['name'] ?? ucfirst((string) $b['tarif']);
        $maxMa = (int) ($tarif['max_mitarbeiter'] ?? $b['max_mitarbeiter']);
        $preis = (float) $v['preis_monat'];
        $webPreis = (int) $v['website_modul'] === 1 ? (float) $v['website_preis_monat'] : 0.0;
        $einrichtung = (float) ($v['einrichtung_einmalig'] ?? 0);
        $rabatt = (float) ($v['rabatt_prozent'] ?? 0);
        $jaehrlich = ($v['zahlungsweise'] ?? 'monatlich') === 'jaehrlich';
        $monatlich = $preis + $webPreis;
        $monatlichNachRabatt = $rabatt > 0 ? round($monatlich * (1 - $rabatt / 100), 2) : $monatlich;
        $ust = (float) ($v['ust_satz'] ?? 20);
        $jahresbetrag = $jaehrlich ? round($monatlichNachRabatt * 11, 2) : 0.0;

        // Leistungszeilen
        $pos = '<tr><td>Softwarenutzung, Tarif ' . $e($tarifName) . ' (bis ' . $maxMa . ' Reinigungskräfte)</td><td class="r">' . $g($preis) . ' / Monat</td></tr>';
        if ($webPreis > 0) { $pos .= '<tr><td>Website-Modul unter eigener Domain</td><td class="r">' . $g($webPreis) . ' / Monat</td></tr>'; }
        if ($rabatt > 0) { $pos .= '<tr><td>Vereinbarter Nachlass ' . number_format($rabatt, 2, ',', '') . ' %</td><td class="r">− ' . $g(round($monatlich - $monatlichNachRabatt, 2)) . ' / Monat</td></tr>'; }
        $pos .= '<tr class="summe"><td><b>Laufendes Entgelt netto</b></td><td class="r"><b>' . $g($monatlichNachRabatt) . ' / Monat</b></td></tr>';
        if ($jaehrlich) { $pos .= '<tr><td>Zahlungsweise jährlich: 12 Monate Laufzeit, 11 Monate verrechnet</td><td class="r">' . $g($jahresbetrag) . ' / Jahr</td></tr>'; }
        if ($einrichtung > 0) { $pos .= '<tr><td>Einrichtung und Datenübernahme, einmalig</td><td class="r">' . $g($einrichtung) . '</td></tr>'; }

        $zahlungsart = ['ueberweisung' => 'Überweisung nach Rechnungslegung', 'lastschrift' => 'SEPA-Lastschrift', 'bar' => 'Barzahlung'][$v['zahlungsart'] ?? 'ueberweisung'] ?? 'Überweisung';
        $inhaberZeile = ($s['inhaber'] ?? '') !== '' ? ', Inh. ' . $s['inhaber'] : '';

        $kopf = '<!DOCTYPE html><html lang="de-AT"><head><meta charset="utf-8"><style>
  @page{margin:22mm 20mm 20mm}
  body{font-family:"DejaVu Sans",sans-serif;font-size:9.6pt;line-height:1.5;color:#13201B}
  h1{font-size:17pt;color:#0B5D4A;margin:0 0 2mm}
  h2{font-size:11.5pt;color:#0B5D4A;margin:7mm 0 2mm;page-break-after:avoid}
  h3{font-size:10pt;margin:4mm 0 1.5mm;page-break-after:avoid}
  p{margin:0 0 2.5mm}
  .unter{color:#4E5C55;font-size:9pt;margin-bottom:6mm}
  .parteien{width:100%;border-collapse:collapse;margin:5mm 0 3mm}
  .parteien td{width:50%;vertical-align:top;padding:4mm;border:.5pt solid #DDE3DE;background:#F7F9F8}
  .parteien .rolle{font-size:8pt;color:#4E5C55;text-transform:uppercase;letter-spacing:.06em;margin-bottom:1.5mm}
  table.pos{width:100%;border-collapse:collapse;margin:3mm 0}
  table.pos td{padding:2mm 0;border-bottom:.3pt solid #DDE3DE;vertical-align:top}
  table.pos td.r{text-align:right;white-space:nowrap}
  table.pos tr.summe td{border-top:.8pt solid #0B5D4A;border-bottom:0;padding-top:2.5mm;font-size:10.5pt}
  dl{margin:2mm 0}dt{float:left;width:52mm;color:#4E5C55;clear:left;padding:1.2mm 0}dd{margin-left:54mm;padding:1.2mm 0}
  .box{background:#F3F5F3;border-radius:2mm;padding:4mm 5mm;margin:4mm 0}
  .unterschrift{width:100%;border-collapse:collapse;margin-top:14mm;page-break-inside:avoid}
  .unterschrift td{width:50%;padding:0 6mm 0 0;vertical-align:bottom}
  .linie{border-bottom:.6pt solid #13201B;height:16mm}
  .beschriftung{font-size:8.5pt;color:#4E5C55;padding-top:1.5mm}
  .anlage{page-break-before:always}
  .klein{font-size:8.5pt;color:#4E5C55}
  ol,ul{margin:0 0 2.5mm 0;padding-left:6mm}li{margin-bottom:1.2mm}
</style></head><body>';

        $vertrag = '
<h1>Vertrag über die Nutzung der Software</h1>
<div class="unter">Software as a Service für Reinigungsbetriebe · Vertrag Nr. ' . $e((string) $b['id']) . '-' . date('Y') . '</div>

<table class="parteien"><tr>
  <td><div class="rolle">Anbieter</div><b>' . $e($s['firma'] . $inhaberZeile) . '</b><br>' . $e($s['adresse']) . '<br>' . $e($s['plz'] . ' ' . $s['ort']) . '<br>'
    . (($s['uid'] ?? '') !== '' ? 'UID ' . $e($s['uid']) . '<br>' : '') . $e($s['email'] ?? '') . (($s['telefon'] ?? '') !== '' ? '<br>' . $e($s['telefon']) : '') . '</td>
  <td><div class="rolle">Kunde</div><b>' . $e($b['name']) . '</b><br>' . $e($b['adresse'] ?? '') . '<br>' . $e(trim(($b['plz'] ?? '') . ' ' . ($b['ort'] ?? ''))) . '<br>'
    . (($b['uid_nummer'] ?? '') !== '' ? 'UID ' . $e($b['uid_nummer']) . '<br>' : '') . $e($b['email'] ?? '') . (($b['telefon'] ?? '') !== '' ? '<br>' . $e($b['telefon']) : '') . '</td>
</tr></table>

<h2>1. Vertragsgegenstand</h2>
<p>Der Anbieter stellt dem Kunden die webbasierte Software zur Verwaltung von Reinigungsbetrieben für die Dauer dieses Vertrags über das Internet zur Nutzung bereit (Software as a Service). Der Funktionsumfang ergibt sich aus dem vereinbarten Tarif und der Leistungsbeschreibung nach Punkt 2 der beigefügten Allgemeinen Geschäftsbedingungen (Anlage 1).</p>
<p>Die Software wird auf Servern in Österreich betrieben. Die Verarbeitung personenbezogener Daten im Auftrag des Kunden richtet sich nach der beigefügten Vereinbarung zur Auftragsverarbeitung gemäß Art. 28 DSGVO (Anlage 2).</p>

<h2>2. Vereinbarter Leistungsumfang und Entgelt</h2>
<table class="pos">' . $pos . '</table>
<p class="klein">Alle Beträge verstehen sich netto zuzüglich ' . number_format($ust, 0) . ' % Umsatzsteuer. Das Entgelt gilt für den gesamten Betrieb; es fallen keine Kosten je Benutzer, je Kunde oder je Objekt an.</p>

<h2>3. Laufzeit, Abrechnung und Kündigung</h2>
<dl>
  <dt>Vertragsbeginn</dt><dd>' . $d($v['vertragsbeginn'] ?? null) . '</dd>
  <dt>Mindestlaufzeit</dt><dd>12 Monate ab Vertragsbeginn</dd>
  <dt>Verlängerung</dt><dd>danach auf unbestimmte Zeit, monatlich kündbar zum Monatsletzten</dd>
  <dt>Zahlungsweise</dt><dd>' . ($jaehrlich ? 'jährlich im Voraus' : 'monatlich im Voraus') . '</dd>
  <dt>Zahlungsart</dt><dd>' . $e($zahlungsart) . '</dd>
  <dt>Zahlungsziel</dt><dd>' . (int) ($s['rechnung_zahlungsziel_tage'] ?? 14) . ' Tage ab Rechnungsdatum</dd>
  <dt>Nächste Abrechnung</dt><dd>' . $d($v['naechste_abrechnung'] ?? null) . '</dd>
</dl>
<p>Die Kündigung bedarf der Schriftform oder der Erklärung per E-Mail an die oben angeführte Adresse des Anbieters. Das Recht zur Kündigung aus wichtigem Grund bleibt für beide Teile unberührt.</p>

<h2>4. Daten des Kunden</h2>
<p>Sämtliche im Rahmen der Nutzung eingegebenen Daten bleiben Daten des Kunden. Der Kunde kann diese jederzeit selbst vollständig als strukturierte Datei exportieren (Art. 20 DSGVO). Nach Vertragsende werden die Daten des Kunden binnen 30 Tagen gelöscht, sofern keine gesetzliche Aufbewahrungspflicht entgegensteht; Einzelheiten regelt Punkt 8 der Allgemeinen Geschäftsbedingungen sowie Punkt 11 der Vereinbarung zur Auftragsverarbeitung.</p>

<h2>5. Pflichten des Kunden</h2>
<p>Der Kunde ist für die Rechtmäßigkeit der von ihm eingegebenen Daten verantwortlich, insbesondere für die Erfüllung seiner arbeitsrechtlichen Aufzeichnungspflichten (§ 26 AZG) und seiner Informationspflichten gegenüber den eigenen Mitarbeitern und Kunden. Zugangsdaten sind geheim zu halten und dürfen nicht an Dritte außerhalb des eigenen Betriebs weitergegeben werden.</p>

<h2>6. Vertragsbestandteile</h2>
<p>Bestandteil dieses Vertrags sind:</p>
<ul>
  <li><b>Anlage 1:</b> Allgemeine Geschäftsbedingungen in der Fassung ' . $e(Consent::version('agb')) . '</li>
  <li><b>Anlage 2:</b> Vereinbarung zur Auftragsverarbeitung nach Art. 28 DSGVO in der Fassung ' . $e(Consent::version('av_vertrag')) . '</li>
</ul>
<p>Bei Widersprüchen gehen die Bestimmungen dieser Vertragsurkunde den Anlagen vor. Änderungen und Ergänzungen bedürfen der Schriftform; das gilt auch für ein Abgehen von diesem Formerfordernis.</p>

<h2>7. Schlussbestimmungen</h2>
<p>Es gilt österreichisches Recht unter Ausschluss der Verweisungsnormen des internationalen Privatrechts und des UN-Kaufrechts. Der Vertrag richtet sich ausschließlich an Unternehmer im Sinne des § 1 UGB; das Konsumentenschutzgesetz findet keine Anwendung. Als Gerichtsstand wird das für ' . $e($s['ort'] ?: 'Wien') . ' sachlich zuständige Gericht vereinbart.</p>
<p>Sollte eine Bestimmung unwirksam sein, bleibt der übrige Vertrag wirksam. An die Stelle der unwirksamen Bestimmung tritt eine wirksame, die dem wirtschaftlichen Zweck am nächsten kommt.</p>

<div class="box"><b>Hinweis zur Testphase</b><br><span class="klein">' . ((string) $b['status'] === 'test' && $b['testphase_bis']
    ? 'Die kostenlose Testphase läuft bis ' . $d($b['testphase_bis']) . '. Das entgeltliche Vertragsverhältnis beginnt mit dem oben genannten Vertragsbeginn. Wird vor Ablauf der Testphase erklärt, die Software nicht weiter zu nutzen, entsteht kein Entgeltanspruch.'
    : 'Eine allfällige Testphase ist abgeschlossen; es gilt der oben genannte Vertragsbeginn.') . '</span></div>

<table class="unterschrift"><tr>
  <td><div class="linie"></div><div class="beschriftung">Ort, Datum · Anbieter<br>' . $e($s['firma']) . '</div></td>
  <td><div class="linie"></div><div class="beschriftung">Ort, Datum · Kunde<br>' . $e($b['name']) . '</div></td>
</tr></table>
<p class="klein" style="margin-top:8mm">Diese Urkunde wurde am ' . date('d.m.Y') . ' erstellt. Zwei gleichlautende Ausfertigungen; jeder Teil erhält eine.</p>
';

        $anlage1 = '<div class="anlage"><h1>Anlage 1: Allgemeine Geschäftsbedingungen</h1><div class="unter">Fassung ' . $e(Consent::version('agb')) . ' · Bestandteil des Vertrags Nr. ' . $e((string) $b['id']) . '-' . date('Y') . '</div>' . Consent::html('agb', (string) $b['name']) . '</div>';
        $anlage2 = '<div class="anlage"><h1>Anlage 2: Vereinbarung zur Auftragsverarbeitung</h1><div class="unter">Art. 28 DSGVO · Fassung ' . $e(Consent::version('av_vertrag')) . ' · Bestandteil des Vertrags Nr. ' . $e((string) $b['id']) . '-' . date('Y') . '</div>' . Consent::html('av_vertrag', (string) $b['name'])
            . '<table class="unterschrift"><tr>
  <td><div class="linie"></div><div class="beschriftung">Ort, Datum · Auftragsverarbeiter<br>' . $e($s['firma']) . '</div></td>
  <td><div class="linie"></div><div class="beschriftung">Ort, Datum · Verantwortlicher<br>' . $e($b['name']) . '</div></td>
</tr></table></div>';

        return $kopf . $vertrag . $anlage1 . $anlage2 . '</body></html>';
    }
}
