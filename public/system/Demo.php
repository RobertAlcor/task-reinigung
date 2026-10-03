<?php
/**
 * Demo-Betrieb mit Beispieldaten — fuer den Anbieter zum Ansehen aller
 * Bereiche und fuer Verkaufsdemos. Alle Zugaenge haben feste Passwoerter,
 * die nach dem Anlegen angezeigt werden.
 */

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

final class Demo
{
    public static function anlegen(string $name = 'Alcor Facility Management'): array
    {
        // Auf Shared Hosting: Zeitlimit anheben, Abbruch durch den Browser ignorieren
        @set_time_limit(300); @ini_set('memory_limit', '256M'); ignore_user_abort(true);
        $db = Database::get();
        $suffix = ''; $login = 'demo';
        while ($db->rawOne('SELECT id FROM benutzer WHERE benutzername = ? LIMIT 1', [$login . $suffix]) !== null) { $suffix = (string) ((int) $suffix + 1); }
        $login .= $suffix; $pw = 'Demo-Betrieb-2026';
        $r = Tenant::create(['name' => $name . ($suffix !== '' ? ' ' . $suffix : ''), 'inhaber_login' => $login, 'email' => 'office@alcor-facility.at', 'tarif' => 'plus', 'test_tage' => 365, 'passwort' => $pw]);
        $B = (int) $r['betrieb_id'];
        $db->setTenant($B);
        $db->raw('UPDATE betriebe SET adresse = ?, plz = ?, ort = ?, telefon = ?, uid_nummer = ? WHERE id = ?', ['Berggasse 14', '1090', 'Wien', '01 890 44 20', 'ATU12345678', $B]);
        $u = $db->rawOne('SELECT id FROM benutzer WHERE betrieb_id = ? AND rolle = ?', [$B, 'inhaber']);
        Consent::accept($B, (int) $u['id'], 'av_vertrag', $name);
        Consent::accept($B, (int) $u['id'], 'agb', $name);
        $db->insert('benutzer', ['rolle' => 'office', 'benutzername' => $login . '-buero', 'passwort_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
        $db->raw("UPDATE rechnungs_layout SET bankname = 'Erste Bank', iban = 'AT611904300234573201', bic = 'GIBAATWWXXX', einleitungstext = 'Wir erlauben uns, folgende Leistungen in Rechnung zu stellen:', schlusstext = 'Vielen Dank für Ihr Vertrauen.' WHERE betrieb_id = ?", [$B]);

        // Mitarbeiter mit PIN 1234
        $qual = []; foreach ($db->select('qualifikationen', '', []) as $q) { $qual[$q['name']] = (int) $q['id']; }
        $ma = [];
        foreach ([['1001', 'Andrea', 'Novak', 20, 14.50, ['Unterhaltsreinigung', 'Fensterreinigung']], ['1002', 'Milan', 'Prohaska', 30, 13.80, ['Unterhaltsreinigung', 'Grundreinigung maschinell']], ['1003', 'Erol', 'Yildiz', 20, 15.20, ['Unterhaltsreinigung', 'Sonderreinigung', 'Hubsteiger']], ['1004', 'Fatma', 'Demir', 15, 14.00, ['Unterhaltsreinigung', 'Haushalt und Bügeln']]] as $x) {
            $mid = $db->insert('mitarbeiter', ['personalnummer' => $x[0], 'vorname' => $x[1], 'nachname' => $x[2], 'anstellung' => 'teilzeit', 'stunden_woche' => $x[3], 'stundenlohn' => $x[4], 'lohngruppe' => '6', 'eintrittsdatum' => date('Y-m-d', strtotime('-18 months')), 'telefon' => '0660 ' . random_int(100000, 999999)]);
            $db->insert('benutzer', ['rolle' => 'mitarbeiter', 'benutzername' => $x[0], 'pin_hash' => password_hash('1234', PASSWORD_DEFAULT), 'mitarbeiter_id' => $mid]);
            foreach ($x[5] as $qn) { if (isset($qual[$qn])) { $db->insert('mitarbeiter_qualifikationen', ['mitarbeiter_id' => $mid, 'qualifikation_id' => $qual[$qn], 'seit' => date('Y-m-d')]); } }
            foreach ([1, 2, 3, 4, 5] as $wt) { $db->insert('mitarbeiter_verfuegbarkeit', ['mitarbeiter_id' => $mid, 'wochentag' => $wt, 'uhrzeit_von' => '06:00:00', 'uhrzeit_bis' => '21:00:00']); }
            $ma[] = $mid;
        }

        // Kunden, Portalzugang, Objekte
        $k1 = $db->insert('kunden', ['firmenname' => 'Kanzlei Berger & Partner', 'status' => 'aktiv', 'kontaktperson' => 'Mag. Sabine Berger', 'email' => 'kunde@alcor-facility.at', 'telefon' => '01 234 56 78', 'adresse' => 'Berggasse 14', 'plz' => '1090', 'ort' => 'Wien', 'uid_nummer' => 'ATU99999999', 'zahlungsziel_tage' => 14, 'quelle' => 'Empfehlung']);
        $db->insert('benutzer', ['rolle' => 'kunde', 'benutzername' => 'kunde@alcor-facility.at', 'email' => 'kunde@alcor-facility.at', 'passwort_hash' => password_hash($pw, PASSWORD_DEFAULT), 'kunde_id' => $k1]);
        $k2 = $db->insert('kunden', ['firmenname' => 'Ordination Dr. Weiss', 'status' => 'aktiv', 'kontaktperson' => 'Dr. Thomas Weiss', 'email' => 'ordination@example.at', 'adresse' => 'Währinger Straße 88', 'plz' => '1180', 'ort' => 'Wien', 'zahlungsziel_tage' => 30]);
        $k3 = $db->insert('kunden', ['firmenname' => 'Steuerberatung Kern', 'status' => 'interessent', 'kontaktperson' => 'Lukas Kern', 'email' => 'kern@example.at', 'quelle' => 'Website']);
        $lu = (int) $db->selectOne('leistungsarten', "kategorie = 'unterhalt'")['id']; $lg = (int) $db->selectOne('leistungsarten', "kategorie = 'fenster'")['id'];
        $objs = [];
        foreach ([[$k1, 'Kanzlei Berger, 2. Stock', 'Berggasse 14', '1090', 480, 120, [0, 1], 'Stiege 2, 2. Stock links, Schlüssel beim Portier', 'Parkett nur nebelfeucht, Serverraum nicht betreten'], [$k1, 'Kanzlei Berger, Archiv', 'Berggasse 14', '1090', 180, 60, [2], 'Kellergeschoß, Schlüssel Nr. 14', null], [$k2, 'Ordination Dr. Weiss', 'Währinger Straße 88', '1180', 650, 150, [1, 3], 'Code am Eingang, Übergabe durch Ordinationshilfe', 'Hygieneplan liegt in der Küche aus, Desinfektion aller Griffflächen']] as $o) {
            $oid = $db->insert('objekte', ['kunde_id' => $o[0], 'name' => $o[1], 'adresse' => $o[2], 'plz' => $o[3], 'ort' => 'Wien', 'qr_token' => Crypto::token(16), 'zugang_info' => $o[7], 'reinigung_hinweise' => $o[8], 'ansprechpartner' => 'Empfang', 'telefon' => '01 234 56 78']);
            $ol = $db->insert('objekt_leistungen', ['objekt_id' => $oid, 'leistungsart_id' => $lu, 'turnus' => 'woechentlich', 'wochentage' => 21, 'uhrzeit_von' => '18:00:00', 'uhrzeit_bis' => '20:00:00', 'dauer_soll_minuten' => $o[5], 'preis_monat' => $o[4], 'gueltig_von' => date('Y-m-01', strtotime('-6 months'))]);
            $rf = 1; foreach ($o[6] as $i) { $db->insert('objekt_leistung_mitarbeiter', ['objekt_leistung_id' => $ol, 'mitarbeiter_id' => $ma[$i], 'reihenfolge' => $rf++]); }
            $objs[] = [$oid, $ol, $o];
        }
        $db->insert('objekt_leistungen', ['objekt_id' => $objs[0][0], 'leistungsart_id' => $lg, 'turnus' => 'monatlich', 'wochentage' => 2, 'uhrzeit_von' => '08:00:00', 'uhrzeit_bis' => '10:00:00', 'dauer_soll_minuten' => 120, 'preis_einheit' => 90, 'gueltig_von' => date('Y-m-01', strtotime('-6 months'))]);

        // Einsaetze der letzten drei Monate mit Zeiten und Fotos-Zaehlern, danach Plan generieren
        mt_srand(42);
        $start = new DateTimeImmutable('first day of -3 months'); $gestern = new DateTimeImmutable('yesterday');
        for ($d = $start; $d <= $gestern; $d = $d->modify('+1 day')) {
            if (!in_array((int) $d->format('N'), [1, 3, 5], true)) { continue; }
            foreach ($objs as [$oid, $ol, $o]) {
                $status = mt_rand(1, 40) === 1 ? 'ausgefallen' : 'erledigt';
                $e = $db->insert('einsaetze', ['objekt_id' => $oid, 'objekt_leistung_id' => $ol, 'leistungsart_id' => $lu, 'datum' => $d->format('Y-m-d'), 'uhrzeit_von' => '18:00:00', 'uhrzeit_bis' => '20:00:00', 'dauer_soll_minuten' => $o[5], 'status' => $status]);
                foreach ($o[6] as $i) {
                    $db->insert('einsatz_mitarbeiter', ['einsatz_id' => $e, 'mitarbeiter_id' => $ma[$i]]);
                    if ($status !== 'erledigt') { continue; }
                    $spaet = mt_rand(1, 9) === 1 ? mt_rand(16, 35) : mt_rand(-2, 8); $min = (int) round($o[5] / count($o[6]) + mt_rand(-12, 15));
                    $ein = $d->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', 18 + intdiv(max(0, $spaet), 60), max(0, $spaet) % 60);
                    $db->insert('zeiterfassung', ['einsatz_id' => $e, 'mitarbeiter_id' => $ma[$i], 'checkin_zeit' => $ein, 'checkin_methode' => 'qr', 'checkout_zeit' => (new DateTimeImmutable($ein))->modify("+{$min} minutes")->format('Y-m-d H:i:s'), 'minuten_brutto' => $min, 'minuten_netto' => $min]);
                }
            }
        }
        Schedule::generate();

        // Rechnungen der letzten zwei vollen Monate, aeltere bezahlt
        foreach ([2, 1] as $mo) {
            $m = new DateTimeImmutable("first day of -{$mo} months");
            foreach ([$k1, $k2] as $kid) {
                try {
                    $rid = CustomerInvoice::entwurfErzeugen($kid, (int) $m->format('Y'), (int) $m->format('n'));
                    $datum = $m->modify('last day of this month')->modify('+2 days')->format('Y-m-d');
                    CustomerInvoice::festschreiben($rid, $datum);
                    if ($mo === 2) { CustomerInvoice::bezahlt($rid, (new DateTimeImmutable($datum))->modify('+11 days')->format('Y-m-d'), null); }
                } catch (\Throwable) {}
            }
        }
        // Abwesenheiten, Beschwerde, Feedback, Anfrage, Regie
        $db->insert('abwesenheiten', ['mitarbeiter_id' => $ma[1], 'typ' => 'krank', 'datum_von' => date('Y-m-d', strtotime('-20 days')), 'datum_bis' => date('Y-m-d', strtotime('-19 days')), 'tage' => 2, 'status' => 'genehmigt']);
        $db->insert('abwesenheiten', ['mitarbeiter_id' => $ma[0], 'typ' => 'urlaub', 'datum_von' => date('Y-m-d', strtotime('+12 days')), 'datum_bis' => date('Y-m-d', strtotime('+16 days')), 'tage' => 5, 'status' => 'beantragt']);
        $db->insert('beschwerden', ['kunde_id' => $k2, 'objekt_id' => $objs[2][0], 'datum' => date('Y-m-d', strtotime('-9 days')), 'kategorie' => 'vergessen', 'beschreibung' => 'Papierkörbe im Wartezimmer wurden nicht geleert.', 'status' => 'erledigt', 'massnahmen' => 'Nachreinigung am Folgetag, Team informiert.', 'mitarbeiter_id' => $ma[1]]);
        $db->insert('kundenfeedback', ['kunde_id' => $k1, 'objekt_id' => $objs[0][0], 'bewertung' => 5, 'kommentar' => 'Sehr zuverlässig, immer dieselben Leute.', 'kontaktperson' => 'Mag. Sabine Berger', 'datum' => date('Y-m-d', strtotime('-30 days'))]);
        $db->insert('anfragen', ['name' => 'Lukas Kern', 'firma' => 'Steuerberatung Kern', 'email' => 'kern@example.at', 'telefon' => '0664 1234567', 'leistung' => 'Unterhaltsreinigung', 'nachricht' => 'Wir suchen eine Reinigung für 220 m² Büro im 3. Bezirk, dreimal wöchentlich abends.', 'status' => 'neu', 'kunde_id' => $k3]);
        $db->insert('regieleistungen', ['kunde_id' => $k1, 'objekt_id' => $objs[0][0], 'leistungsart_id' => $lu, 'datum' => date('Y-m-d', strtotime('-4 days')), 'beschreibung' => 'Sonderreinigung Küche nach Feier', 'menge' => 2, 'einheit' => 'Std', 'einzelpreis' => 38, 'fahrtkosten' => 12]);
        // Weitere Abwesenheiten (alle Arten), Nachrichten, offene Beschwerde, zweites Feedback
        $db->insert('abwesenheiten', ['mitarbeiter_id' => $ma[2], 'typ' => 'zeitausgleich', 'datum_von' => date('Y-m-d', strtotime('-6 days')), 'datum_bis' => date('Y-m-d', strtotime('-6 days')), 'tage' => 1, 'status' => 'genehmigt']);
        $db->insert('abwesenheiten', ['mitarbeiter_id' => $ma[3], 'typ' => 'einschulung', 'datum_von' => date('Y-m-d', strtotime('-40 days')), 'datum_bis' => date('Y-m-d', strtotime('-40 days')), 'tage' => 1, 'status' => 'genehmigt']);
        $db->insert('abwesenheiten', ['mitarbeiter_id' => $ma[3], 'typ' => 'urlaub', 'datum_von' => date('Y-m-d', strtotime('+25 days')), 'datum_bis' => date('Y-m-d', strtotime('+29 days')), 'tage' => 5, 'status' => 'abgelehnt', 'kommentar_office' => 'Zu viele gleichzeitig weg, bitte eine Woche später.']);
        $db->insert('beschwerden', ['kunde_id' => $k1, 'objekt_id' => $objs[0][0], 'datum' => date('Y-m-d', strtotime('-2 days')), 'kategorie' => 'qualitaet', 'beschreibung' => 'Boden im Besprechungsraum streifig, Fenster innen mit Schlieren.', 'status' => 'offen']);
        $db->insert('kundenfeedback', ['kunde_id' => $k2, 'objekt_id' => $objs[2][0], 'bewertung' => 4, 'kommentar' => 'Zuverlässig, Hygienevorgaben werden eingehalten.', 'kontaktperson' => 'Dr. Thomas Weiss', 'datum' => date('Y-m-d', strtotime('-15 days'))]);
        $db->insert('app_nachrichten', ['mitarbeiter_id' => $ma[0], 'typ' => 'info', 'titel' => 'Neue Schlüssel für Kanzlei Berger', 'nachricht' => 'Ab Montag liegt der Schlüssel beim Portier, nicht mehr im Tresor.']);
        $db->insert('app_nachrichten', ['mitarbeiter_id' => $ma[1], 'typ' => 'info', 'titel' => 'Willkommen in der App', 'nachricht' => 'Bitte PIN unter „Mehr“ ändern.']);

        // Einsatzfotos zu den letzten erledigten Einsaetzen (Platzhalterbilder, EXIF-frei)
        $fotoDir = __DIR__ . '/fotos/' . $B;
        foreach ($db->rawAll("SELECT e.id, e.datum, em.mitarbeiter_id FROM einsaetze e JOIN einsatz_mitarbeiter em ON em.einsatz_id = e.id WHERE e.betrieb_id = ? AND e.status = 'erledigt' AND e.datum >= DATE_SUB(CURDATE(), INTERVAL 10 DAY) GROUP BY e.id ORDER BY e.datum DESC LIMIT 6", [$B]) as $ef) {
            $dir = $fotoDir . '/' . $ef['id']; if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
            foreach (['vorher' => [190, 200, 190], 'nachher' => [225, 238, 228]] as $typ => $rgb) {
                $img = imagecreatetruecolor(1200, 900); $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]); imagefilledrectangle($img, 0, 0, 1199, 899, $c);
                $t = imagecolorallocate($img, 80, 95, 88); imagestring($img, 5, 40, 40, strtoupper($typ) . ' - Demo ' . $ef['datum'], $t);
                for ($i = 0; $i < 6; $i++) { $g = imagecolorallocate($img, $rgb[0] - 20 - $i * 3, $rgb[1] - 20 - $i * 3, $rgb[2] - 20 - $i * 3); imagefilledrectangle($img, 100 + $i * 170, 300, 220 + $i * 170, 700, $g); }
                $name = date('Ymd-His') . '-' . $typ . '-' . bin2hex(random_bytes(3)) . '.jpg'; imagejpeg($img, $dir . '/' . $name, 80); imagedestroy($img);
                $db->insert('einsatz_fotos', ['einsatz_id' => (int) $ef['id'], 'mitarbeiter_id' => (int) $ef['mitarbeiter_id'], 'typ' => $typ, 'pfad' => $B . '/' . $ef['id'] . '/' . $name, 'dateigroesse' => filesize($dir . '/' . $name), 'aufgenommen_am' => $ef['datum'] . ($typ === 'vorher' ? ' 18:03:00' : ' 19:55:00'), 'loeschen_am' => date('Y-m-d', strtotime($ef['datum'] . ' +3 months'))]);
            }
        }

        // Subunternehmer mit uebertragener Leistung (Glasreinigung)
        $sub = $db->insert('subunternehmer', ['firmenname' => 'Blitz Glasreinigung KG', 'ansprechpartner' => 'Ali Demir', 'telefon' => '0660 555 12 34', 'email' => 'office@blitz-glas.at', 'stundensatz' => 24.50, 'uid_nummer' => 'ATU55555555', 'adresse' => 'Triester Straße 10', 'plz' => '1100', 'ort' => 'Wien']);
        $olGlas = $db->rawOne('SELECT id FROM objekt_leistungen WHERE betrieb_id = ? AND leistungsart_id = ? LIMIT 1', [$B, $lg]);
        if ($olGlas) { $db->insert('subunternehmer_objekte', ['subunternehmer_id' => $sub, 'objekt_leistung_id' => (int) $olGlas['id'], 'aktiv' => 1]); $db->raw("UPDATE einsaetze SET subunternehmer_id = ? WHERE objekt_leistung_id = ? AND betrieb_id = ? AND datum >= CURDATE() AND status = 'geplant'", [$sub, (int) $olGlas['id'], $B]); }

        // Material mit Verbrauch
        $mat = $db->insert('material', ['name' => 'Allzweckreiniger 5 l', 'einheit' => 'Stk', 'einkaufspreis' => 9.80, 'verkaufspreis' => 14.90]);
        $db->insert('material', ['name' => 'Mikrofasertücher 10er', 'einheit' => 'Pkg', 'einkaufspreis' => 6.50, 'verkaufspreis' => 9.90]);
        $letzter = $db->rawOne("SELECT id FROM einsaetze WHERE betrieb_id = ? AND objekt_id = ? AND status = 'erledigt' ORDER BY datum DESC LIMIT 1", [$B, $objs[0][0]]);
        if ($letzter) { $db->insert('einsatz_material', ['einsatz_id' => (int) $letzter['id'], 'material_id' => $mat, 'menge' => 1]); }

        // Offene Einsatzanfrage an Fatma fuer einen kommenden Einsatz ohne sie
        $kommend = $db->rawOne("SELECT e.id FROM einsaetze e WHERE e.betrieb_id = ? AND e.objekt_id = ? AND e.datum > CURDATE() AND e.status = 'geplant' ORDER BY e.datum LIMIT 1", [$B, $objs[1][0]]);
        if ($kommend) { $db->insert('einsatz_anfragen', ['einsatz_id' => (int) $kommend['id'], 'mitarbeiter_id' => $ma[3], 'nachricht' => 'Erol hat Urlaub beantragt — kannst du das Archiv übernehmen?', 'gesendet_von' => (int) $u['id']]); }

        // Rechnungen: eine mit Teilzahlung, eine mit Mahnstufe, eine storniert
        $rechs = $db->select('rechnungen', "status <> 'entwurf'", [], 'ORDER BY id');
        if (count($rechs) >= 4) {
            try { CustomerInvoice::bezahlt((int) $rechs[2]['id'], date('Y-m-d', strtotime('-5 days')), round((float) $rechs[2]['brutto'] / 2, 2)); } catch (\Throwable) {}
            $db->update('rechnungen', (int) $rechs[3]['id'], ['faellig_am' => date('Y-m-d', strtotime('-20 days')), 'status' => 'mahnung_1']);
            try {
                $rid = CustomerInvoice::entwurfErzeugen($k1, (int) date('Y', strtotime('-4 months')), (int) date('n', strtotime('-4 months')));
            } catch (\Throwable) { $rid = null; }
            if ($rid === null) {
                $rid = $db->insert('rechnungen', ['kunde_id' => $k1, 'leistungszeitraum_von' => date('Y-m-01', strtotime('-4 months')), 'leistungszeitraum_bis' => date('Y-m-t', strtotime('-4 months')), 'ust_satz' => 20, 'status' => 'entwurf']);
                $db->insert('rechnung_positionen', ['rechnung_id' => $rid, 'beschreibung' => 'Grundreinigung Kanzlei (Sonderauftrag)', 'menge' => 1, 'einheit' => 'Pauschale', 'einzelpreis' => 380, 'gesamtpreis' => 380]); CustomerInvoice::summen($rid);
            }
            try { CustomerInvoice::festschreiben($rid, date('Y-m-d', strtotime('-100 days'))); CustomerInvoice::stornieren($rid); } catch (\Throwable) {}
        }
        // Monatsabschluss des Vormonats festhalten
        foreach ($ma as $mid) {
            $vm = new DateTimeImmutable('first day of last month');
            $ist = (int) ($db->rawOne("SELECT IFNULL(SUM(minuten_netto),0) s FROM zeiterfassung WHERE mitarbeiter_id = ? AND DATE_FORMAT(checkin_zeit,'%Y-%m') = ?", [$mid, $vm->format('Y-m')])['s'] ?? 0);
            $stdW = (float) $db->find('mitarbeiter', $mid)['stunden_woche'];
            $db->raw('INSERT IGNORE INTO stundenkonto (betrieb_id, mitarbeiter_id, jahr, monat, soll_minuten, ist_minuten, differenz_minuten) VALUES (?, ?, ?, ?, ?, ?, ?)', [$B, $mid, (int) $vm->format('Y'), (int) $vm->format('n'), (int) round($stdW * 60 * 4.33), $ist, $ist - (int) round($stdW * 60 * 4.33)]);
        }
        // Website-Fotos (Platzhalter) fuer Titelbild, Leistungen, Team
        $webDir = dirname(__DIR__) . '/uploads/betriebe/' . $B . '/web'; if (!is_dir($webDir)) { @mkdir($webDir, 0755, true); }
        $sort = 1;
        foreach ([['hero', [180, 200, 190], 1800, 800, 'Unser Team im Einsatz'], ['leistungen', [200, 215, 205], 1200, 800, 'Unterhaltsreinigung'], ['leistungen', [190, 205, 200], 1200, 800, 'Grundreinigung'], ['leistungen', [210, 220, 212], 1200, 800, 'Glasreinigung'], ['team', [205, 200, 190], 800, 1000, 'Andrea N. · Teamleitung'], ['team', [195, 205, 195], 800, 1000, 'Milan P. · Objektbetreuer']] as [$bereich, $rgb, $w, $h, $alt]) {
            $img = imagecreatetruecolor($w, $h); imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]));
            imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), (int) ($w / 3), (int) ($h / 3), imagecolorallocate($img, $rgb[0] - 25, $rgb[1] - 25, $rgb[2] - 25));
            $name = 'web-demo-' . $sort . '.jpg'; imagejpeg($img, $webDir . '/' . $name, 82); imagedestroy($img);
            $db->insert('website_fotos', ['bereich' => $bereich, 'pfad' => 'betriebe/' . $B . '/web/' . $name, 'alt_text' => $alt, 'sortierung' => $sort++]);
        }

        // Besichtigung mit Angebot fuer den Interessenten
        $bes = $db->insert('besichtigungen', ['kunde_id' => $k3, 'benutzer_id' => (int) $u['id'], 'ansprechpartner' => 'Lukas Kern', 'objektart' => 'Büro', 'gesamtflaeche' => 220, 'anzahl_raeume' => 8, 'stockwerke' => 1, 'wc' => 2, 'kueche' => 1, 'besprechungsraeume' => 1, 'empfang' => 1, 'gesamteindruck' => 3, 'intervall' => '3x', 'reinigungstage' => 'Mo Mi Fr', 'uhrzeit_von' => '18:00:00', 'uhrzeit_bis' => '20:00:00', 'stundensatz' => 32, 'status' => 'abgeschlossen']);
        try { $aid = Offer::ausBesichtigung($bes); Offer::pdf($aid); Offer::status($aid, 'gesendet'); } catch (\Throwable) {}
        // Website-Texte
        $db->raw("INSERT INTO website_config (betrieb_id, aktiv, seo_titel, seo_beschreibung, texte, oeffnungszeiten) VALUES (?, 0, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE seo_titel = VALUES(seo_titel)", [$B, 'Büroreinigung Wien – ' . $name, 'Unterhaltsreinigung für Büros und Ordinationen in Wien mit festem Team und Fotonachweis.', json_encode(['zahl_1' => '64', 'zahl_2' => '12', 'bezirke' => '1010, 1030, 1090, 1180, 1190', 'ueber_text' => 'Seit 2018 betreuen wir Büros, Ordinationen und Kanzleien in Wien. Feste Teams je Objekt, Nachweis mit Fotos und Zeiten, eine Ansprechperson im Büro.', 'rechtsform' => 'e.U.', 'inhaber' => 'Robert Alchimowicz', 'firmenbuch' => 'FN 123456a', 'firmenbuchgericht' => 'Handelsgericht Wien'], JSON_UNESCAPED_UNICODE), 'werktags 7–17 Uhr']);

        $einsaetze = $db->count('einsaetze');
        return ['betrieb_id' => $B, 'name' => $name . ($suffix !== '' ? ' ' . $suffix : ''), 'slug' => $db->rawOne('SELECT slug FROM betriebe WHERE id = ?', [$B])['slug'], 'inhaber' => $login, 'buero' => $login . '-buero', 'passwort' => $pw, 'pin' => '1234', 'kunde' => 'kunde@alcor-facility.at', 'einsaetze' => $einsaetze];
    }
}
