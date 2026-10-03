<?php
/**
 * Vertragstexte (AV-Vertrag, AGB): versioniert in system/texte/,
 * Platzhalter aus den Anbieter-Einstellungen, Zustimmung mit Hash.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Consent
{
    private const DIR = __DIR__ . '/texte';

    /** Aktuelle Version je Typ — beim Ändern eines Textes hier hochzählen und neue Datei anlegen. */
    private const VERSIONEN = [
        'av_vertrag' => '1.0',
        'agb'        => '1.0',
    ];

    public static function version(string $typ): string
    {
        return self::VERSIONEN[$typ] ?? throw new RuntimeException('Unbekannter Vertragstyp.');
    }

    /** Roher Text mit gefuellten Platzhaltern. */
    public static function text(string $typ, ?string $betriebName = null): string
    {
        $v = self::version($typ);
        $datei = self::DIR . '/' . ($typ === 'av_vertrag' ? 'av-vertrag' : 'agb') . "-{$v}.md";
        if (!is_file($datei)) {
            throw new RuntimeException("Vertragstext fehlt: {$datei}");
        }
        $s = Invoice::settings();
        $inhaber = ($s['inhaber'] ?? '') !== '' ? ', Inh. ' . $s['inhaber'] : '';
        return strtr((string) file_get_contents($datei), [
            '{firma}'        => $s['firma'] ?? '',
            '{inhaber_zeile}' => $inhaber,
            '{adresse}'      => $s['adresse'] ?? '',
            '{plz}'          => $s['plz'] ?? '',
            '{ort}'          => $s['ort'] ?? '',
            '{email}'        => $s['email'] ?? '',
            '{uid}'          => $s['uid'] ?? '',
            '{version}'      => $v,
            '{betrieb_name}' => $betriebName ?? '{betrieb_name}',
        ]);
    }

    public static function hash(string $typ, ?string $betriebName = null): string
    {
        return hash('sha256', self::text($typ, $betriebName));
    }

    /** Einfache Umwandlung des Textes in HTML: Ueberschriften, Absaetze, Listen, Fett. */
    public static function html(string $typ, ?string $betriebName = null): string
    {
        $out = [];
        $inList = false;
        foreach (explode("\n", self::text($typ, $betriebName)) as $zeile) {
            $z = rtrim($zeile);
            if ($z === '') {
                if ($inList) { $out[] = '</ul>'; $inList = false; }
                continue;
            }
            $e = htmlspecialchars($z, ENT_QUOTES, 'UTF-8');
            $e = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $e) ?? $e;

            if (str_starts_with($z, '# '))        { $out[] = '<h2>' . mb_substr($e, 2) . '</h2>'; continue; }
            if (str_starts_with($z, '## '))       { $out[] = '<h3>' . mb_substr($e, 3) . '</h3>'; continue; }
            if ($z === '---')                     { $out[] = '<hr>'; continue; }
            if (str_starts_with($z, '- ')) {
                if (!$inList) { $out[] = '<ul>'; $inList = true; }
                $out[] = '<li>' . mb_substr($e, 2) . '</li>';
                continue;
            }
            if ($inList) { $out[] = '</ul>'; $inList = false; }
            $out[] = '<p>' . $e . '</p>';
        }
        if ($inList) { $out[] = '</ul>'; }
        return implode("\n", $out);
    }

    /** Hat der Betrieb der aktuellen Version zugestimmt? */
    public static function accepted(int $betriebId, string $typ): bool
    {
        $row = Database::get()->rawOne(
            'SELECT id FROM betrieb_zustimmungen WHERE betrieb_id = ? AND typ = ? AND version = ? LIMIT 1',
            [$betriebId, $typ, self::version($typ)]
        );
        return $row !== null;
    }

    public static function missing(int $betriebId): array
    {
        $fehlt = [];
        foreach (array_keys(self::VERSIONEN) as $typ) {
            if (!self::accepted($betriebId, $typ)) {
                $fehlt[] = $typ;
            }
        }
        return $fehlt;
    }

    /** Zustimmung speichern — nur durch die Rolle Inhaber. */
    public static function accept(int $betriebId, int $benutzerId, string $typ, string $betriebName): void
    {
        $db = Database::get();
        $db->raw(
            'INSERT INTO betrieb_zustimmungen (betrieb_id, benutzer_id, typ, version, text_hash, ip_adresse) VALUES (?, ?, ?, ?, ?, ?)',
            [$betriebId, $benutzerId, $typ, self::version($typ), self::hash($typ, $betriebName), Auth::clientIp()]
        );
        if ($typ === 'av_vertrag') {
            $db->raw('UPDATE betriebe SET av_vertrag_akzeptiert_am = NOW() WHERE id = ?', [$betriebId]);
        }
    }
}
