<?php
/**
 * Rechnungs-PDF und Versand auf Anbieter-Ebene.
 *
 * Pflichtangaben nach § 11 Abs. 1 UStG: Name und Anschrift des
 * Leistenden und des Empfaengers, Menge und Bezeichnung, Leistungszeitraum,
 * Entgelt und Steuersatz, Steuerbetrag, Ausstellungsdatum, fortlaufende
 * Nummer, UID des Leistenden. Bei Kleinunternehmern (§ 6 Abs. 1 Z 27 UStG)
 * kein Steuerausweis, dafuer der Befreiungshinweis.
 *
 * PDFs liegen unter system/rechnungen/ — nicht oeffentlich erreichbar —
 * und werden nur ueber das Dashboard nach Anmeldung ausgeliefert.
 */

declare(strict_types=1);

namespace App;

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use RuntimeException;

final class Invoice
{
    private const PDF_DIR = __DIR__ . '/rechnungen';

    // ---------------------------------------------------------------
    // Anbieter-Stammdaten
    // ---------------------------------------------------------------

    public static function settings(): array
    {
        $rows = Database::get()->rawAll('SELECT schluessel, wert FROM anbieter_einstellungen');
        $s = [];
        foreach ($rows as $r) {
            $s[$r['schluessel']] = (string) $r['wert'];
        }
        return $s;
    }

    public static function saveSettings(array $data): void
    {
        $db = Database::get();
        $erlaubt = ['firma', 'inhaber', 'adresse', 'plz', 'ort', 'telefon', 'email', 'web', 'uid',
                    'kleinunternehmer', 'bank', 'iban', 'bic', 'rechnung_einleitung', 'rechnung_schluss',
                    'rechnung_zahlungsziel_tage', 'produktname', 'gewerbe', 'behoerde', 'kammer', 'abrechnung_automatisch', 'abrechnung_senden'];
        foreach ($erlaubt as $k) {
            if (!array_key_exists($k, $data)) {
                continue;
            }
            $v = trim((string) $data[$k]);
            if ($k === 'kleinunternehmer') {
                $v = $v === '1' ? '1' : '0';
            }
            if ($k === 'iban') {
                $v = strtoupper(str_replace(' ', '', $v));
            }
            $db->raw(
                'INSERT INTO anbieter_einstellungen (schluessel, wert) VALUES (?, ?) ON DUPLICATE KEY UPDATE wert = VALUES(wert)',
                [$k, mb_substr($v, 0, 4000)]
            );
        }
    }

    /** Prueft, ob die Ausstellerdaten fuer eine gueltige Rechnung reichen. */
    public static function settingsComplete(array $s): array
    {
        $fehlt = [];
        foreach (['firma' => 'Firma', 'adresse' => 'Adresse', 'plz' => 'PLZ', 'ort' => 'Ort', 'iban' => 'IBAN'] as $k => $n) {
            if (($s[$k] ?? '') === '') {
                $fehlt[] = $n;
            }
        }
        if (($s['kleinunternehmer'] ?? '0') !== '1' && ($s['uid'] ?? '') === '') {
            $fehlt[] = 'UID (oder Kleinunternehmer aktivieren)';
        }
        return $fehlt;
    }

    // ---------------------------------------------------------------
    // PDF
    // ---------------------------------------------------------------

    public static function pdfPath(array $rechnung): string
    {
        return self::PDF_DIR . '/' . preg_replace('/[^A-Za-z0-9\-]/', '', $rechnung['rechnungsnummer']) . '.pdf';
    }

    /** Erzeugt das PDF und gibt den Pfad zurueck. Bestehende Datei wird nicht ueberschrieben. */
    public static function render(int $rechnungId, bool $force = false): string
    {
        $db = Database::get();
        $r  = $db->rawOne(
            'SELECT r.*, b.name AS betrieb_name, v.rechnungsadresse, v.uid_nummer AS betrieb_uid, v.rechnungs_email
               FROM anbieter_rechnungen r
               JOIN betriebe b ON b.id = r.betrieb_id
          LEFT JOIN anbieter_vertraege v ON v.betrieb_id = r.betrieb_id
              WHERE r.id = ?', [$rechnungId]
        );
        if ($r === null) {
            throw new RuntimeException('Rechnung nicht gefunden.');
        }

        $path = self::pdfPath($r);
        if (is_file($path) && !$force) {
            return $path;
        }

        $s = self::settings();
        $fehlt = self::settingsComplete($s);
        if ($fehlt !== []) {
            throw new RuntimeException('Ausstellerdaten unvollständig: ' . implode(', ', $fehlt) . '. Unter Einstellungen ergänzen.');
        }

        if (!is_dir(self::PDF_DIR) && !mkdir(self::PDF_DIR, 0750, true)) {
            throw new RuntimeException('Ordner system/rechnungen konnte nicht angelegt werden.');
        }

        $html = self::html($r, $s);

        require_once __DIR__ . '/lib/dompdf/autoload.inc.php';
        $opt = new Options();
        $opt->set('isRemoteEnabled', false);
        $opt->set('defaultFont', 'DejaVu Sans');
        $opt->set('fontDir', __DIR__ . '/lib/dompdf/vendor/dompdf/dompdf/lib/fonts');
        $opt->set('fontCache', self::PDF_DIR);       // beschreibbar, gesperrt
        $opt->set('chroot', __DIR__);
        $pdf = new Dompdf($opt);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        if (file_put_contents($path, $pdf->output()) === false) {
            throw new RuntimeException('PDF konnte nicht gespeichert werden.');
        }
        $db->raw('UPDATE anbieter_rechnungen SET pdf_pfad = ? WHERE id = ?', [basename($path), $rechnungId]);
        return $path;
    }

    private static function html(array $r, array $s): string
    {
        $klein = ($s['kleinunternehmer'] ?? '0') === '1';
        $pos   = json_decode((string) $r['positionen'], true) ?: [];
        $storno = $r['storno_von_id'] !== null;

        $d = static fn(?string $x): string => $x ? date('d.m.Y', strtotime($x)) : '';
        $g = static fn(float $x): string => number_format($x, 2, ',', '.') . ' €';
        $e = static fn(?string $x): string => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');

        $empfaenger = $r['rechnungsadresse'] !== null && $r['rechnungsadresse'] !== ''
            ? $e($r['rechnungsadresse'])
            : $e($r['betrieb_name']);
        $empfaenger = nl2br(str_replace(', ', "\n", $empfaenger));

        $zeilen = '';
        foreach ($pos as $p) {
            if ((float) $p['betrag'] === 0.0 && str_starts_with((string) $p['text'], 'Storno')) {
                $zeilen .= '<tr><td colspan="2" class="hinweis">' . $e($p['text']) . '</td></tr>';
                continue;
            }
            $zeilen .= '<tr><td>' . $e($p['text']) . '</td><td class="r">' . $g((float) $p['betrag']) . '</td></tr>';
        }

        $titel = $storno ? 'Gutschrift / Storno' : 'Rechnung';
        $ustZeile = $klein
            ? ''
            : '<tr><td>zzgl. ' . number_format((float) $r['ust_satz'], 0) . ' % USt</td><td class="r">' . $g((float) $r['ust']) . '</td></tr>';
        $ustHinweis = $klein
            ? '<p class="klein">Umsatzsteuerbefreit gemäß § 6 Abs. 1 Z 27 UStG (Kleinunternehmerregelung).</p>'
            : '';

        $uidEmpf = ($r['betrieb_uid'] ?? '') !== '' ? '<br>UID: ' . $e($r['betrieb_uid']) : '';
        $uidAus  = ($s['uid'] ?? '') !== '' ? 'UID ' . $e($s['uid']) : '';

        $kopfZeile = implode(' · ', array_filter([
            $e($s['firma']), $e($s['adresse']) . ', ' . $e($s['plz']) . ' ' . $e($s['ort']),
        ]));
        $fussTeile = array_filter([
            $e($s['firma']) . ($s['inhaber'] !== '' ? ', Inh. ' . $e($s['inhaber']) : ''),
            $e($s['adresse']) . ', ' . $e($s['plz']) . ' ' . $e($s['ort']),
            $s['telefon'] !== '' ? 'Tel. ' . $e($s['telefon']) : '',
            $e($s['email']), $e($s['web']), $uidAus,
        ]);
        $bank = implode(' · ', array_filter([
            $s['bank'] !== '' ? $e($s['bank']) : '',
            'IBAN ' . $e(chunk_split($s['iban'], 4, ' ')),
            $s['bic'] !== '' ? 'BIC ' . $e($s['bic']) : '',
        ]));

        $implodeFuss = implode(' · ', $fussTeile) . '<br>' . $bank;

        $zahlung = $storno
            ? 'Der Betrag wird mit offenen Forderungen verrechnet oder rückerstattet.'
            : 'Zahlbar bis ' . $d($r['faellig_am']) . ' ohne Abzug auf das unten angeführte Konto. Verwendungszweck: ' . $e($r['rechnungsnummer']) . '.';

        return <<<HTML
<!DOCTYPE html>
<html lang="de-AT"><head><meta charset="utf-8">
<style>
  @page { margin: 22mm 20mm 26mm 20mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #13201B; line-height: 1.45; }
  .absender { font-size: 7.5pt; color: #5C6B64; border-bottom: 0.4pt solid #0B5D4A; padding-bottom: 2mm; margin-bottom: 10mm; }
  .kopf { width: 100%; }
  .kopf td { vertical-align: top; }
  .empf { font-size: 10.5pt; line-height: 1.5; }
  .meta { font-size: 9pt; text-align: right; }
  .meta b { display: inline-block; width: 38mm; text-align: left; color: #5C6B64; font-weight: normal; }
  h1 { font-size: 18pt; color: #0B5D4A; margin: 14mm 0 5mm; letter-spacing: -0.02em; }
  .einleitung { margin-bottom: 5mm; }
  table.pos { width: 100%; border-collapse: collapse; margin: 4mm 0 5mm; }
  table.pos th { text-align: left; font-size: 8.5pt; color: #5C6B64; border-bottom: 0.8pt solid #0B5D4A; padding: 0 0 2mm; font-weight: normal; }
  table.pos td { padding: 2.6mm 0; border-bottom: 0.3pt solid #DDE3DE; vertical-align: top; }
  table.pos td.r, th.r { text-align: right; white-space: nowrap; }
  table.pos td.hinweis { color: #5C6B64; font-size: 9pt; }
  table.summe { width: 70mm; margin-left: auto; border-collapse: collapse; }
  table.summe td { padding: 1.6mm 0; }
  table.summe tr.ges td { border-top: 0.8pt solid #0B5D4A; font-weight: bold; font-size: 11.5pt; padding-top: 3mm; }
  .zahlung { margin-top: 8mm; }
  .klein { font-size: 8.5pt; color: #5C6B64; }
  .schluss { margin-top: 7mm; }
  .fuss { position: fixed; bottom: -16mm; left: 0; right: 0; font-size: 7.5pt; color: #5C6B64; border-top: 0.4pt solid #DDE3DE; padding-top: 2mm; line-height: 1.5; }
</style></head>
<body>
  <div class="absender">{$kopfZeile}</div>
  <table class="kopf"><tr>
    <td class="empf">{$empfaenger}{$uidEmpf}</td>
    <td class="meta">
      <b>Rechnungsnummer</b>{$e($r['rechnungsnummer'])}<br>
      <b>Rechnungsdatum</b>{$d($r['rechnungsdatum'])}<br>
      <b>Leistungszeitraum</b>{$d($r['zeitraum_von'])} – {$d($r['zeitraum_bis'])}<br>
      <b>Fällig</b>{$d($r['faellig_am'])}
    </td>
  </tr></table>

  <h1>{$titel} {$e($r['rechnungsnummer'])}</h1>
  <p class="einleitung">{$e($s['rechnung_einleitung'])}</p>

  <table class="pos">
    <tr><th>Leistung</th><th class="r">Betrag netto</th></tr>
    {$zeilen}
  </table>

  <table class="summe">
    <tr><td>Netto</td><td class="r">{$g((float) $r['netto'])}</td></tr>
    {$ustZeile}
    <tr class="ges"><td>Gesamt</td><td class="r">{$g((float) $r['brutto'])}</td></tr>
  </table>
  {$ustHinweis}

  <p class="zahlung">{$zahlung}</p>
  <p class="klein">{$bank}</p>
  <p class="schluss">{$e($s['rechnung_schluss'])}</p>

  <div class="fuss">{$implodeFuss}</div>
</body></html>
HTML
        ;
    }

    // ---------------------------------------------------------------
    // Versand
    // ---------------------------------------------------------------

    public static function send(int $rechnungId, array $mailConfig): void
    {
        $db = Database::get();
        $r  = $db->rawOne(
            'SELECT r.*, b.name AS betrieb_name, v.rechnungs_email, b.email AS betrieb_email
               FROM anbieter_rechnungen r
               JOIN betriebe b ON b.id = r.betrieb_id
          LEFT JOIN anbieter_vertraege v ON v.betrieb_id = r.betrieb_id
              WHERE r.id = ?', [$rechnungId]
        );
        if ($r === null) {
            throw new RuntimeException('Rechnung nicht gefunden.');
        }
        $an = $r['rechnungs_email'] ?: $r['betrieb_email'];
        if (!$an || filter_var($an, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Keine gültige Rechnungs-E-Mail beim Betrieb hinterlegt.');
        }
        if (($mailConfig['user'] ?? '') === '' || ($mailConfig['from_email'] ?? '') === '') {
            throw new RuntimeException('Mailversand ist nicht konfiguriert (system/config.php, Abschnitt mail).');
        }

        $pdf = self::render($rechnungId);
        $s   = self::settings();
        $storno = $r['storno_von_id'] !== null;
        $betreff = ($storno ? 'Gutschrift ' : 'Rechnung ') . $r['rechnungsnummer'] . ' – ' . $s['firma'];

        $text = "Guten Tag,\n\n"
              . ($storno
                  ? "anbei die Gutschrift {$r['rechnungsnummer']}.\n\n"
                  : "anbei die Rechnung {$r['rechnungsnummer']} über " . number_format((float) $r['brutto'], 2, ',', '.') . " € "
                    . "für den Zeitraum " . date('d.m.Y', strtotime($r['zeitraum_von'])) . " bis " . date('d.m.Y', strtotime($r['zeitraum_bis'])) . ".\n"
                    . "Zahlbar bis " . date('d.m.Y', strtotime($r['faellig_am'])) . ", Verwendungszweck {$r['rechnungsnummer']}.\n\n")
              . "Mit freundlichen Grüßen\n" . ($s['inhaber'] !== '' ? $s['inhaber'] . "\n" : '') . $s['firma'];

        require_once __DIR__ . '/lib/phpmailer/Exception.php';
        require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/lib/phpmailer/SMTP.php';

        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host       = $mailConfig['host'];
            $m->Port       = (int) $mailConfig['port'];
            $m->SMTPAuth   = true;
            $m->SMTPSecure = $mailConfig['secure'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $m->Username   = $mailConfig['user'];
            $m->Password   = $mailConfig['password'];
            $m->CharSet    = 'UTF-8';
            $m->setFrom($mailConfig['from_email'], $mailConfig['from_name'] ?: $s['firma']);
            if ($s['email'] !== '') {
                $m->addReplyTo($s['email'], $s['firma']);
            }
            $m->addAddress($an, $r['betrieb_name']);
            $m->Subject = $betreff;
            $m->Body    = $text;
            $m->addAttachment($pdf, basename($pdf));
            $m->send();

            $db->raw('INSERT INTO anbieter_mails (betrieb_id, rechnung_id, empfaenger, betreff, status) VALUES (?, ?, ?, ?, \'gesendet\')',
                [(int) $r['betrieb_id'], $rechnungId, $an, $betreff]);
        } catch (MailException $e) {
            $db->raw('INSERT INTO anbieter_mails (betrieb_id, rechnung_id, empfaenger, betreff, status, fehler) VALUES (?, ?, ?, ?, \'fehler\', ?)',
                [(int) $r['betrieb_id'], $rechnungId, $an, $betreff, mb_substr($m->ErrorInfo, 0, 255)]);
            throw new RuntimeException('Versand fehlgeschlagen: ' . $m->ErrorInfo);
        }
    }

    /** Letzter Versandstatus je Rechnung, fuer die Anzeige. */
    public static function mailStatus(array $rechnungIds): array
    {
        if ($rechnungIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($rechnungIds), '?'));
        $rows = Database::get()->rawAll(
            "SELECT rechnung_id, status, gesendet_am, empfaenger FROM anbieter_mails WHERE rechnung_id IN ({$in}) ORDER BY id",
            array_map('intval', $rechnungIds)
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['rechnung_id']] = $r;   // letzter Eintrag gewinnt
        }
        return $out;
    }
}
