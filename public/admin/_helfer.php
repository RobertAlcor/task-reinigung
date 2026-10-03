<?php
/**
 * Helfer fuer die Buero-Seiten.
 *
 * Eingaben werden gesammelt geprueft; Fehler landen je Feld in $fehler.
 * Nach erfolgreichem POST wird umgeleitet (kein doppeltes Absenden bei
 * Neuladen), die Rueckmeldung wandert ueber die Session.
 */

declare(strict_types=1);

use App\Auth;

// ---------------------------------------------------------------------
// Flash-Meldungen
// ---------------------------------------------------------------------

function flash(string $typ, string $text): void
{
    Auth::startSession();
    $_SESSION['flash'] = ['typ' => $typ, 'text' => $text];
}

function flashLesen(): ?array
{
    Auth::startSession();
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($m) ? $m : null;
}

function weiter(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------------
// Eingaben aus $_POST
// ---------------------------------------------------------------------

/** Sammelt Feldfehler. */
final class Pruefung
{
    public array $fehler = [];

    public function text(string $key, int $max = 255, bool $pflicht = false, ?string $label = null): ?string
    {
        $v = trim((string) ($_POST[$key] ?? ''));
        if ($v === '') {
            if ($pflicht) {
                $this->fehler[$key] = ($label ?? 'Dieses Feld') . ' fehlt.';
            }
            return null;
        }
        if (mb_strlen($v) > $max) {
            $this->fehler[$key] = "Höchstens {$max} Zeichen.";
            return mb_substr($v, 0, $max);
        }
        return $v;
    }

    public function int(string $key, bool $pflicht = false, ?int $min = null, ?int $max = null): ?int
    {
        $v = trim((string) ($_POST[$key] ?? ''));
        if ($v === '') {
            if ($pflicht) {
                $this->fehler[$key] = 'Bitte ausfüllen.';
            }
            return null;
        }
        if (preg_match('/^-?\d+$/', $v) !== 1) {
            $this->fehler[$key] = 'Ganze Zahl erwartet.';
            return null;
        }
        $i = (int) $v;
        if ($min !== null && $i < $min) { $this->fehler[$key] = "Mindestens {$min}."; }
        if ($max !== null && $i > $max) { $this->fehler[$key] = "Höchstens {$max}."; }
        return $i;
    }

    public function decimal(string $key, bool $pflicht = false, ?float $min = null): ?float
    {
        $v = trim((string) ($_POST[$key] ?? ''));
        if ($v === '') {
            if ($pflicht) {
                $this->fehler[$key] = 'Bitte ausfüllen.';
            }
            return null;
        }
        $v = str_replace([' ', '.', ','], ['', '', '.'], $v);   // 1.234,50 → 1234.50
        if (!is_numeric($v)) {
            $this->fehler[$key] = 'Zahl erwartet, z. B. 32,50.';
            return null;
        }
        $f = round((float) $v, 2);
        if ($min !== null && $f < $min) { $this->fehler[$key] = "Mindestens {$min}."; }
        return $f;
    }

    public function email(string $key, bool $pflicht = false): ?string
    {
        $v = $this->text($key, 150, $pflicht, 'E-Mail');
        if ($v !== null && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            $this->fehler[$key] = 'Keine gültige E-Mail-Adresse.';
        }
        return $v !== null ? mb_strtolower($v) : null;
    }

    public function datum(string $key, bool $pflicht = false): ?string
    {
        $v = $this->text($key, 10, $pflicht, 'Datum');
        if ($v === null) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            $this->fehler[$key] = 'Ungültiges Datum.';
            return null;
        }
        return $v;
    }

    public function zeit(string $key, bool $pflicht = false): ?string
    {
        $v = $this->text($key, 8, $pflicht, 'Uhrzeit');
        if ($v === null) {
            return null;
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) !== 1) {
            $this->fehler[$key] = 'Format HH:MM.';
            return null;
        }
        return $v . ':00';
    }

    public function auswahl(string $key, array $erlaubt, bool $pflicht = false): ?string
    {
        $v = $this->text($key, 40, $pflicht);
        if ($v !== null && !in_array($v, $erlaubt, true)) {
            $this->fehler[$key] = 'Ungültige Auswahl.';
            return null;
        }
        return $v;
    }

    public function haken(string $key): int
    {
        return !empty($_POST[$key]) ? 1 : 0;
    }

    /** Bitmaske Wochentage aus Checkboxen tag[]=1..7 */
    public function wochentage(string $key = 'tag'): int
    {
        $m = 0;
        foreach ((array) ($_POST[$key] ?? []) as $t) {
            $t = (int) $t;
            if ($t >= 1 && $t <= 7) {
                $m |= 1 << ($t - 1);
            }
        }
        return $m;
    }

    public function ok(): bool
    {
        return $this->fehler === [];
    }
}

// ---------------------------------------------------------------------
// Ausgabe
// ---------------------------------------------------------------------

/** Wert fuer ein Formularfeld: erst POST (bei Fehler), dann Datensatz. */
function wert(string $key, ?array $satz = null, string $default = ''): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && array_key_exists($key, $_POST)) {
        return (string) $_POST[$key];
    }
    return (string) ($satz[$key] ?? $default);
}

function feldFehler(array $fehler, string $key): string
{
    return isset($fehler[$key]) ? '<div class="fehler-feld">' . e($fehler[$key]) . '</div>' : '';
}

function geld(float|int|string|null $v): string
{
    return $v === null || $v === '' ? '—' : number_format((float) $v, 2, ',', '.') . ' €';
}

function datumDe(?string $d): string
{
    return $d ? date('d.m.Y', strtotime($d)) : '—';
}

function zeitKurz(?string $t): string
{
    return $t ? substr($t, 0, 5) : '';
}

const WOCHENTAGE = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

function wochentageText(int $maske): string
{
    $t = [];
    foreach (WOCHENTAGE as $n => $k) {
        if ($maske & (1 << ($n - 1))) {
            $t[] = $k;
        }
    }
    return $t === [] ? '—' : implode(' ', $t);
}

const TURNUS = ['woechentlich' => 'wöchentlich', '14taegig' => '14-tägig', 'monatlich' => 'monatlich', 'einmalig' => 'einmalig'];
const ANSTELLUNG = ['geringfuegig' => 'geringfügig', 'teilzeit' => 'Teilzeit', 'vollzeit' => 'Vollzeit'];
const KUNDENSTATUS = ['interessent' => 'Interessent', 'aktiv' => 'aktiv', 'inaktiv' => 'inaktiv'];

// ---------------------------------------------------------------------
// PDF aus HTML ausliefern (dompdf), fuer Formulare und Auswertungen
// ---------------------------------------------------------------------

function pdfAusgeben(string $html, string $dateiname, string $papier = 'A4'): never
{
    require_once __DIR__ . '/../system/lib/dompdf/autoload.inc.php';
    $cache = __DIR__ . '/../system/rechnungen';
    if (!is_dir($cache)) {
        @mkdir($cache, 0750, true);
    }
    $opt = new Dompdf\Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('fontDir', __DIR__ . '/../system/lib/dompdf/vendor/dompdf/dompdf/lib/fonts');
    $opt->set('fontCache', $cache);
    $opt->set('chroot', __DIR__ . '/../system');
    $pdf = new Dompdf\Dompdf($opt);
    $pdf->setPaper($papier);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->render();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $dateiname) . '.pdf"');
    echo $pdf->output();
    exit;
}

/** Gemeinsames Stylesheet fuer Formular-PDFs. */
function pdfStil(): string
{
    return '<style>
      @page{margin:20mm 20mm 22mm}
      body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;color:#13201B;line-height:1.5}
      h1{font-size:16pt;color:#0B5D4A;margin:0 0 3mm}
      h2{font-size:11.5pt;margin:6mm 0 2mm;color:#0B5D4A}
      p{margin:0 0 2.5mm}
      ul{margin:0 0 3mm 5mm;padding:0}li{margin-bottom:1mm}
      .kopf{font-size:8pt;color:#4E5C55;border-bottom:.4pt solid #0B5D4A;padding-bottom:2mm;margin-bottom:8mm}
      .klein{font-size:8.5pt;color:#4E5C55}
      .unterschrift{margin-top:14mm;display:table;width:100%}
      .unterschrift div{display:table-cell;width:48%;border-top:.5pt solid #13201B;padding-top:2mm;font-size:8.5pt;color:#4E5C55}
      .unterschrift div.abstand{width:4%;border:0}
      .feld{border-bottom:.5pt solid #13201B;display:inline-block;min-width:60mm}
      .box{border:.5pt solid #D6DDD8;padding:3mm 4mm;margin:3mm 0}
      .haken{display:inline-block;width:3.5mm;height:3.5mm;border:.6pt solid #13201B;vertical-align:middle;margin-right:2mm}
    </style>';
}
