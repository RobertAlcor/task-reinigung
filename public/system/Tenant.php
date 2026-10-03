<?php
/**
 * Betriebe (Mandanten) anlegen und verwalten — Anbieter-Ebene.
 *
 * Beim Anlegen entstehen zugleich Inhaber-Zugang, Leistungskatalog,
 * Benachrichtigungsvorlagen, Nummernkreise und Rechnungslayout, damit
 * ein Betrieb sofort arbeiten kann.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Tenant
{
    /**
     * Legt einen Betrieb samt Inhaber-Zugang an.
     *
     * @return array{betrieb_id:int, inhaber_login:string, passwort:string}
     */
    public static function create(array $data): array
    {
        $db  = Database::get();
        $pdo = $db->pdo();

        $name  = trim((string) ($data['name'] ?? ''));
        $login = trim((string) ($data['inhaber_login'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $tarif = (string) ($data['tarif'] ?? 'basis');
        $testTage = (int) ($data['test_tage'] ?? 30);

        if ($name === '') {
            throw new RuntimeException('Firmenname fehlt.');
        }
        if (preg_match('/^[a-zA-Z0-9._@+-]{3,80}$/', $login) !== 1) {
            throw new RuntimeException('Inhaber-Benutzername: 3 bis 80 Zeichen, nur Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich, @.');
        }
        if (!in_array($tarif, ['basis', 'plus', 'pro'], true)) {
            throw new RuntimeException('Unbekannter Tarif.');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Ungültige E-Mail-Adresse.');
        }

        // Benutzernamen sind je Betrieb eindeutig, der Inhaber-Login aber
        // besser global — sonst gibt es beim Login Mehrdeutigkeit.
        if ($db->rawOne('SELECT id FROM benutzer WHERE benutzername = ? LIMIT 1', [$login]) !== null) {
            throw new RuntimeException('Dieser Benutzername ist bereits vergeben.');
        }

        $slug = self::slug($name);
        if ($db->rawOne('SELECT id FROM betriebe WHERE slug = ? LIMIT 1', [$slug]) !== null) {
            $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        }

        $tarifRow = Billing::tarif($tarif);
        $maxMitarbeiter = (int) ($tarifRow['max_mitarbeiter'] ?? 10);
        $passwort = !empty($data['passwort']) ? (string) $data['passwort'] : self::password();

        $pdo->beginTransaction();
        try {
            $db->raw(
                'INSERT INTO betriebe (name, slug, email, tarif, max_mitarbeiter, status, testphase_bis)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $name, $slug, $email !== '' ? $email : null, $tarif, $maxMitarbeiter,
                    $testTage > 0 ? 'test' : 'aktiv',
                    $testTage > 0 ? date('Y-m-d', strtotime("+{$testTage} days")) : null,
                ]
            );
            $betriebId = (int) $pdo->lastInsertId();
            $db->setTenant($betriebId);

            $db->insert('benutzer', [
                'rolle'         => 'inhaber',
                'benutzername'  => $login,
                'email'         => $email !== '' ? $email : null,
                'passwort_hash' => password_hash($passwort, PASSWORD_DEFAULT),
            ]);

            self::seedDefaults($db, $betriebId);

            // Vertrag: Abrechnung beginnt nach der Testphase, sonst sofort
            $beginn = $testTage > 0 ? date('Y-m-d', strtotime("+{$testTage} days")) : date('Y-m-d');
            Billing::initVertrag($betriebId, $tarif, $beginn, $email !== '' ? $email : null);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['betrieb_id' => $betriebId, 'inhaber_login' => $login, 'passwort' => $passwort];
    }

    /** Status setzen: test, aktiv, gekuendigt. */
    public static function setStatus(int $betriebId, string $status): void
    {
        if (!in_array($status, ['test', 'aktiv', 'gesperrt', 'gekuendigt'], true)) {
            throw new RuntimeException('Unbekannter Status.');
        }
        $db = Database::get();
        // Vertragsende merken (Beginn der 30-Tage-Loeschfrist), bei Reaktivierung zuruecksetzen
        $db->raw('UPDATE betriebe SET status = ?, gekuendigt_am = ' . ($status === 'gekuendigt' ? 'IFNULL(gekuendigt_am, CURDATE())' : 'NULL') . ' WHERE id = ?', [$status, $betriebId]);

        // Bei Kuendigung alle App-Tokens des Betriebs entwerten
        if ($status === 'gekuendigt') {
            $db->raw('UPDATE api_tokens SET widerrufen = 1 WHERE betrieb_id = ?', [$betriebId]);
        }
    }

    /**
     * Betriebsdaten endgueltig loeschen (AV-Vertrag: 30 Tage nach Vertragsende).
     * Loescht alle Tabellen mit betrieb_id, Fotos, Rechnungs- und Angebots-PDFs, Uploads.
     * Der Betriebsstamm bleibt als Huelle (Name, Zeitraum, geloescht_am) — die
     * Anbieter-Rechnungen an den Betrieb muessen 7 Jahre erhalten bleiben (§ 132 BAO).
     */
    public static function purge(int $betriebId): array
    {
        $db = Database::get(); $pdo = $db->pdo();
        $b = $db->rawOne('SELECT * FROM betriebe WHERE id = ?', [$betriebId]);
        if ($b === null) { throw new RuntimeException('Betrieb nicht gefunden.'); }
        if ($b['status'] !== 'gekuendigt') { throw new RuntimeException('Nur gekündigte Betriebe können gelöscht werden.'); }
        if (!empty($b['geloescht_am'])) { throw new RuntimeException('Bereits gelöscht.'); }
        @set_time_limit(300);
        // Dateien
        $ordner = [__DIR__ . '/fotos/' . $betriebId, __DIR__ . '/rechnungen/' . $betriebId, __DIR__ . '/angebote/' . $betriebId, dirname(__DIR__) . '/uploads/betriebe/' . $betriebId];
        $dateien = 0;
        foreach ($ordner as $o) { if (is_dir($o)) { $dateien += self::rmdirRek($o); } }
        // Tabellen mit betrieb_id, in Abhaengigkeitsreihenfolge (Fremdschluessel)
        $tabellen = ['einsatz_material', 'einsatz_fotos', 'einsatz_nachweise', 'zeiterfassung', 'einsatz_anfragen', 'einsatz_mitarbeiter', 'einsaetze',
            'mahnungen', 'rechnung_positionen', 'rechnungen', 'regieleistungen', 'angebot_positionen', 'angebote', 'besichtigung_fotos', 'besichtigungen',
            'beschwerden', 'kundenfeedback', 'anfragen', 'kunden_notizen', 'subunternehmer_objekte', 'subunternehmer', 'objekt_leistung_mitarbeiter', 'objekt_leistungen', 'objekt_zugang', 'objekte', 'kunden',
            'push_abos', 'anbieter_schnellzugang', 'mitarbeiter_qualifikationen', 'mitarbeiter_verfuegbarkeit', 'mitarbeiter_sensibel', 'abwesenheiten', 'stundenkonto', 'aenderungsanfragen', 'app_nachrichten', 'mitarbeiter',
            'api_tokens', 'passwort_reset_betrieb', 'benutzer', 'material', 'leistungsarten', 'qualifikationen', 'nummernkreise', 'rechnungs_layout', 'benachrichtigungen', 'benachrichtigungs_regeln', 'einstellungen', 'website_fotos', 'website_config', 'betrieb_zustimmungen', 'audit_log'];
        $zeilen = 0;
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tabellen as $t) {
                if ($t === 'passwort_reset_betrieb') { $zeilen += $db->raw('DELETE r FROM passwort_reset r JOIN benutzer b ON b.id = r.benutzer_id WHERE b.betrieb_id = ?', [$betriebId])->rowCount(); continue; }
                try { $zeilen += $db->raw("DELETE FROM `{$t}` WHERE betrieb_id = ?", [$betriebId])->rowCount(); } catch (\Throwable) {}
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $db->raw("UPDATE betriebe SET geloescht_am = NOW(), email = NULL, telefon = NULL, adresse = NULL, plz = NULL, ort = NULL, uid_nummer = NULL, logo_pfad = NULL WHERE id = ?", [$betriebId]);
            $pdo->commit();
        } catch (\Throwable $e) { $pdo->rollBack(); $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); throw $e; }
        return ['zeilen' => $zeilen, 'dateien' => $dateien];
    }

    private static function rmdirRek(string $dir): int
    {
        $n = 0;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $p = $dir . '/' . $f;
            if (is_dir($p)) { $n += self::rmdirRek($p); } elseif (@unlink($p)) { $n++; }
        }
        @rmdir($dir);
        return $n;
    }

    /** Tarif und Mitarbeiterlimit aendern. */
    public static function setTarif(int $betriebId, string $tarif): void
    {
        if (!in_array($tarif, ['basis', 'plus', 'pro'], true)) {
            throw new RuntimeException('Unbekannter Tarif.');
        }
        $t = Billing::tarif($tarif);
        $max = (int) ($t['max_mitarbeiter'] ?? 10);
        Database::get()->raw('UPDATE betriebe SET tarif = ?, max_mitarbeiter = ? WHERE id = ?', [$tarif, $max, $betriebId]);
        // Preis im Vertrag auf den neuen Tarif setzen
        Billing::vertrag($betriebId);
        Database::get()->raw('UPDATE anbieter_vertraege SET preis_monat = ? WHERE betrieb_id = ?', [$t['preis_monat'] ?? 0, $betriebId]);
    }

    /** Testphase verlaengern. */
    public static function extendTrial(int $betriebId, int $tage): void
    {
        Database::get()->raw(
            'UPDATE betriebe SET testphase_bis = DATE_ADD(GREATEST(IFNULL(testphase_bis, CURDATE()), CURDATE()), INTERVAL ? DAY), status = \'test\' WHERE id = ?',
            [max(1, $tage), $betriebId]
        );
    }

    /** Neues Inhaber-Passwort setzen (z. B. bei Verlust). Gibt es im Klartext zurueck — einmalig. */
    public static function resetOwnerPassword(int $betriebId): ?string
    {
        $db = Database::get();
        $u = $db->rawOne(
            "SELECT id FROM benutzer WHERE betrieb_id = ? AND rolle = 'inhaber' ORDER BY id LIMIT 1",
            [$betriebId]
        );
        if ($u === null) {
            return null;
        }
        $pw = self::password();
        $db->raw(
            'UPDATE benutzer SET passwort_hash = ?, fehlversuche = 0, gesperrt_bis = NULL WHERE id = ?',
            [password_hash($pw, PASSWORD_DEFAULT), (int) $u['id']]
        );
        return $pw;
    }

    // ---------------------------------------------------------------

    /** Startdaten, ohne die ein Betrieb nicht arbeiten kann. */
    private static function seedDefaults(Database $db, int $betriebId): void
    {
        // Qualifikationen — was eine Kraft koennen muss, damit sie fuer eine Leistung eingeteilt werden kann
        $quali = [];
        foreach ([['Unterhaltsreinigung', 'Grundeinschulung, Farbcodes, Arbeitssicherheit'], ['Fensterreinigung', 'Glas innen und außen, Leiter bis 3 m'], ['Grundreinigung maschinell', 'Einscheibenmaschine, Beschichtung'],
                  ['Haushalt und Bügeln', 'Privathaushalte, Bügeln, Wäsche'], ['Ordination und Hygiene', 'Desinfektion, Hygieneplan nach Vorgabe'], ['Sonderreinigung', 'Bau- und Wasserschadenreinigung'], ['Hubsteiger', 'Bedienung Hubarbeitsbühne mit Nachweis']] as $i => $q) {
            $quali[$q[0]] = $db->insert('qualifikationen', ['name' => $q[0], 'beschreibung' => $q[1], 'sortierung' => $i + 1]);
        }
        $katalog = [
            ['Unterhaltsreinigung', 'unterhalt', 32.00, 120, 1, 'Unterhaltsreinigung'],
            ['Grundreinigung',      'grund',     38.00, 480, 2, 'Grundreinigung maschinell'],
            ['Glasreinigung',       'fenster',   36.00, 180, 3, 'Fensterreinigung'],
            ['Sonderreinigung',     'sonder',    42.00, 240, 4, 'Sonderreinigung'],
            ['Haushaltsreinigung',  'haushalt',  30.00, 180, 5, 'Haushalt und Bügeln'],
            ['Regiestunde',         'regie',     38.00,  60, 6, null],
        ];
        foreach ($katalog as $k) {
            $db->insert('leistungsarten', [
                'name' => $k[0], 'kategorie' => $k[1], 'standard_stundensatz' => $k[2],
                'standard_dauer_minuten' => $k[3], 'sortierung' => $k[4], 'qualifikation_id' => $k[5] !== null ? $quali[$k[5]] : null,
            ]);
        }

        // Vorlagen: Abwesenheitsgruende werden nie genannt
        $regeln = [
            ['planaenderung', 1, 1, 'Terminänderung {objekt}',      "Guten Tag,\n\nder Reinigungstermin für {objekt} am {datum} wurde auf {neuer_termin} verschoben.\n\nMit freundlichen Grüßen"],
            ['ausfall',       1, 1, 'Terminverschiebung {objekt}',  "Guten Tag,\n\nder Termin am {datum} für {objekt} entfällt. Wir melden uns mit einem Ersatztermin.\n\nMit freundlichen Grüßen"],
            ['ersatz',        1, 1, 'Ersatzkraft für {objekt}',     "Guten Tag,\n\nam {datum} kommt für {objekt} eine Ersatzkraft. Zeiten und Leistungen bleiben unverändert.\n\nMit freundlichen Grüßen"],
            ['erledigt',      0, 1, 'Reinigung {objekt} erledigt',  "Die Reinigung für {objekt} am {datum} wurde abgeschlossen."],
            ['beschwerde',    0, 1, 'Neue Beschwerde {objekt}',     "Für {objekt} wurde eine Beschwerde erfasst."],
            ['rechnung',      1, 1, 'Rechnung {rechnungsnummer}',   "Guten Tag,\n\nanbei die Rechnung {rechnungsnummer} für den Zeitraum {zeitraum}.\n\nMit freundlichen Grüßen"],
        ];
        foreach ($regeln as $r) {
            $db->insert('benachrichtigungs_regeln', [
                'ereignis' => $r[0], 'kunde_informieren' => $r[1], 'office_informieren' => $r[2],
                'vorlage_betreff' => $r[3], 'vorlage_text' => $r[4],
            ]);
        }

        $jahr = (int) date('Y');
        foreach ([['rechnung', 'RE'], ['angebot', 'AN'], ['gutschrift', 'GS']] as $nk) {
            $db->insert('nummernkreise', ['typ' => $nk[0], 'jahr' => $jahr, 'praefix' => $nk[1], 'letzte_nummer' => 0]);
        }

        $db->raw(
            'INSERT INTO rechnungs_layout (betrieb_id, zahlungsbedingungen) VALUES (?, ?)',
            [$betriebId, 'Zahlbar innerhalb von 14 Tagen ohne Abzug.']
        );
    }

    private static function slug(string $name): string
    {
        $s = mb_strtolower($name);
        $s = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-') ?: 'betrieb';
    }

    /** Lesbares Startpasswort: 3 Wörter und eine Zahl, leicht am Telefon durchzugeben. */
    private static function password(): string
    {
        $woerter = ['Besen', 'Eimer', 'Fenster', 'Boden', 'Küche', 'Lampe', 'Tisch', 'Stuhl', 'Türe', 'Regal',
                    'Wagen', 'Wasser', 'Tuch', 'Schlüssel', 'Garten', 'Berg', 'Fluss', 'Wald', 'Stein', 'Wiese'];
        $w = [];
        for ($i = 0; $i < 3; $i++) {
            $w[] = $woerter[random_int(0, count($woerter) - 1)];
        }
        return implode('-', $w) . '-' . random_int(10, 99);
    }
}
