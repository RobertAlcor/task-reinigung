<?php
/**
 * Einsatzfotos.
 *
 * Fotos aus Kundenraeumen liegen NICHT im oeffentlichen uploads/, sondern
 * unter system/fotos/<betrieb>/<einsatz>/ und werden nur nach Anmeldung
 * ausgeliefert. Jedes Bild wird mit GD neu kodiert: entfernt EXIF
 * (inklusive GPS-Koordinaten des Handys) und alles Eingebettete.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Photo
{
    private const DIR      = __DIR__ . '/fotos';
    private const MAX_EDGE = 1600;     // laengste Kante in Pixel
    private const QUALITY  = 82;       // JPEG

    /**
     * Speichert ein hochgeladenes Foto zu einem Einsatz.
     * @return array{id:int, pfad:string, bytes:int}
     */
    public static function store(array $file, int $einsatzId, int $mitarbeiterId, string $typ, int $retentionMonths): array
    {
        $db = Database::get();
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Kein gültiges Foto empfangen.');
        }
        if ((int) $file['size'] > 12 * 1024 * 1024) {
            throw new RuntimeException('Foto größer als 12 MB.');
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Nur JPEG, PNG oder WebP.');
        }
        $src = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => @imagecreatefrompng($file['tmp_name']),
            default      => @imagecreatefromwebp($file['tmp_name']),
        };
        if ($src === false) {
            throw new RuntimeException('Foto konnte nicht gelesen werden.');
        }

        // EXIF-Orientierung beruecksichtigen (Handys speichern quer und drehen per Tag)
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file['tmp_name']);
            $o = (int) ($exif['Orientation'] ?? 1);
            $deg = match ($o) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if ($deg !== 0) {
                $rot = imagerotate($src, $deg, 0);
                if ($rot !== false) { imagedestroy($src); $src = $rot; }
            }
        }

        $w = imagesx($src); $h = imagesy($src);
        $scale = min(1.0, self::MAX_EDGE / max($w, $h));
        if ($scale < 1.0) {
            $nw = (int) round($w * $scale); $nh = (int) round($h * $scale);
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        $dir = self::DIR . '/' . $db->tenant() . '/' . $einsatzId;
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new RuntimeException('Fotoordner nicht anlegbar.');
        }
        $name = date('Ymd-His') . '-' . $typ . '-' . bin2hex(random_bytes(4)) . '.jpg';
        $path = $dir . '/' . $name;
        if (!imagejpeg($src, $path, self::QUALITY)) {
            imagedestroy($src);
            throw new RuntimeException('Foto konnte nicht gespeichert werden.');
        }
        imagedestroy($src);
        $bytes = (int) filesize($path);

        $rel = $db->tenant() . '/' . $einsatzId . '/' . $name;
        $id = $db->insert('einsatz_fotos', [
            'einsatz_id'     => $einsatzId,
            'mitarbeiter_id' => $mitarbeiterId,
            'typ'            => $typ,
            'pfad'           => $rel,
            'dateigroesse'   => $bytes,
            'aufgenommen_am' => date('Y-m-d H:i:s'),
            'loeschen_am'    => date('Y-m-d', strtotime("+{$retentionMonths} months")),
        ]);
        return ['id' => $id, 'pfad' => $rel, 'bytes' => $bytes];
    }

    /** Liefert ein Foto aus, wenn es zum Mandanten gehoert. */
    public static function serve(int $fotoId): never
    {
        $db = Database::get();
        $f = $db->find('einsatz_fotos', $fotoId);
        if ($f === null || (int) $f['geloescht'] === 1) {
            http_response_code(404);
            exit('Nicht gefunden.');
        }
        $path = self::DIR . '/' . $f['pfad'];
        if (!is_file($path) || str_contains($f['pfad'], '..')) {
            http_response_code(404);
            exit('Nicht gefunden.');
        }
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /** Fotos loeschen, deren Frist abgelaufen ist. Fuer den Cronjob. Gibt die Anzahl zurueck. */
    public static function purgeExpired(): int
    {
        $db = Database::get();
        $rows = $db->rawAll('SELECT id, pfad FROM einsatz_fotos WHERE geloescht = 0 AND loeschen_am <= CURDATE() LIMIT 500');
        $n = 0;
        foreach ($rows as $r) {
            $path = self::DIR . '/' . $r['pfad'];
            if (!str_contains($r['pfad'], '..') && is_file($path)) {
                @unlink($path);
            }
            $db->raw('UPDATE einsatz_fotos SET geloescht = 1 WHERE id = ?', [(int) $r['id']]);
            $n++;
        }
        return $n;
    }
}
