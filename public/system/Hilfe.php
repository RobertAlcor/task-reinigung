<?php
/**
 * Hilfe-Assistent fuer das Buero: beantwortet Fragen zur Bedienung aus einer
 * gepflegten Wissensbasis (Schlagwortsuche mit Gewichtung). Findet er nichts
 * Passendes, zeigt er die Kontaktdaten des Anbieters — E-Mail fuer Fragen,
 * Telefon fuer dringende Faelle. Keine externe KI, keine Kosten, keine
 * Datenweitergabe.
 */

declare(strict_types=1);

namespace App;

final class Hilfe
{
    /** Wissensbasis: frage, antwort, schlagworte, link (optional). */
    public static function wissen(): array
    {
        return [
            ['f' => 'Wie lege ich einen Mitarbeiter an?', 'a' => 'Mitarbeiter → „Mitarbeiter anlegen". Personalnummer, Name, Anstellung, Wochenstunden und eine vierstellige PIN vergeben — die PIN ist der Login für die App. Qualifikationen anhaken, damit der Dienstplan die Person vorschlägt. Sozialversicherungsnummer und IBAN sieht nur der Inhaber.', 'k' => 'mitarbeiter anlegen neu person kraft reinigungskraft einstellen pin', 'l' => '/admin/?s=mitarbeiter&t=neu'],
            ['f' => 'Wie meldet sich ein Mitarbeiter in der App an?', 'a' => 'Adresse der App aufrufen (siehe unten „App-Adresse"), Personalnummer und PIN eingeben. Am Handy „Zum Startbildschirm hinzufügen", dann ist die App wie installiert. Nach fünf falschen PINs sperrt sich der Zugang 15 Minuten; im Büro unter Mitarbeiter → „Jetzt entsperren".', 'k' => 'app anmelden login pin mitarbeiter handy startbildschirm sperre entsperren', 'l' => '/app/'],
            ['f' => 'Wie ändere ich die PIN eines Mitarbeiters?', 'a' => 'Mitarbeiter → Person öffnen → rechts „App-Zugang" → neue PIN setzen. Der Mitarbeiter kann sie in der App unter „Mehr → PIN ändern" auch selbst ändern.', 'k' => 'pin ändern vergessen neu mitarbeiter app zugang'],
            ['f' => 'Wie lege ich einen Kunden und ein Objekt an?', 'a' => 'Kunden → „Kunde anlegen". Dann in der Kundenakte „Objekt anlegen": Adresse, Zugang, Hinweise. Im Objekt „Leistung hinzufügen": Leistungsart, Turnus (z. B. Mo/Mi/Fr), Uhrzeit, Dauer, Preis, Stammteam. Daraus schreibt sich der Dienstplan.', 'k' => 'kunde objekt anlegen neu adresse leistung turnus pauschale', 'l' => '/admin/?s=kunden'],
            ['f' => 'Wie funktioniert der Dienstplan?', 'a' => 'Dienstplan → „Plan aktualisieren" erzeugt aus den Leistungen je Objekt die Einsätze für acht Wochen voraus. Das macht der Cronjob auch automatisch. Einsätze anklicken zum Ändern; rot = Ersatz nötig, gelb = unbesetzt, grün = erledigt.', 'k' => 'dienstplan plan erzeugen einsätze woche generieren aktualisieren', 'l' => '/admin/?s=dienstplan'],
            ['f' => 'Ein Mitarbeiter fällt aus — wie finde ich Ersatz?', 'a' => 'Dienstplan → Einsatz öffnen → rechts „Ersatz einteilen". Die Liste ist sortiert: qualifiziert, kennt das Objekt, laut Plan verfügbar, wenig Wochenstunden. „Einteilen" setzt sofort zu; „Anfragen" schickt „Kannst du einspringen?" in die App — bei Zusage wird automatisch zugeteilt und der Kunde informiert.', 'k' => 'ersatz krank ausfall einspringen anfragen vertretung dienstplan'],
            ['f' => 'Wie stempeln Mitarbeiter ein und aus?', 'a' => 'Im Objekt hängt ein QR-Schild (Objekt → „QR-Schild" → PDF drucken). Der Mitarbeiter scannt beim Kommen und Gehen mit der App oder der Handykamera. Keine Ortung — nur der Code am Objekt zählt (§ 10 AVRAG). Geht auch ohne Empfang, die App überträgt später.', 'k' => 'stempeln qr code schild einstempeln ausstempeln zeiterfassung check-in checkout scannen', 'l' => '/admin/?s=objekte'],
            ['f' => 'Wie korrigiere ich eine Zeit oder trage eine nach?', 'a' => 'Zeiterfassung → Zeile anklicken → „Korrigieren" mit Grund (wird protokolliert). Fehlt ein Einsatz ganz: unten „Nachtragen". Am Monatsende „Festhalten" erzeugt das Monatsblatt als PDF zur Unterschrift; „Export Lohnbüro" liefert die CSV.', 'k' => 'zeit korrigieren nachtragen fehler falsch monatsabschluss festhalten lohnbüro csv', 'l' => '/admin/?s=zeiten'],
            ['f' => 'Wie erstelle ich Rechnungen?', 'a' => 'Rechnungen → „Abrechnungslauf" → Monat wählen → „Entwürfe erzeugen" für alle Kunden aus Pauschalen, Einsätzen, Stunden und Regieleistungen. Entwurf prüfen, Positionen ändern, „Festschreiben" vergibt die Nummer. Danach unveränderbar — Korrektur nur per Storno/Gutschrift (§ 11 UStG, § 132 BAO).', 'k' => 'rechnung erstellen abrechnung monat entwurf festschreiben nummer', 'l' => '/admin/?s=rechnungen&t=lauf'],
            ['f' => 'Ein Kunde zahlt nicht — wie mahne ich?', 'a' => 'Rechnung öffnen → „Zahlungserinnerung erstellen" (freundlich, ohne Zinsen). Bleibt sie unbezahlt: „2. Mahnung erstellen" mit Verzugszinsen und 40 € Betreibungskosten (§ 456, § 458 UGB). Beide als PDF, per E-Mail sendbar. Zinssatz und Spesen unter Rechnungen → Layout → Mahnwesen.', 'k' => 'mahnung mahnen zahlungserinnerung verzugszinsen überfällig nicht bezahlt'],
            ['f' => 'Wie storniere ich eine Rechnung?', 'a' => 'Rechnung öffnen → „Storno". Es entsteht eine Gutschrift mit negativem Betrag und neuer Nummer; die Originalrechnung bleibt erhalten. Enthaltene Regieleistungen werden wieder frei für die nächste Rechnung.', 'k' => 'storno stornieren gutschrift rechnung falsch löschen'],
            ['f' => 'Wie bekommt ein Kunde Zugang zum Portal?', 'a' => 'Kundenakte → Block „Kundenportal" → „Zugang anlegen". Das Startpasswort wird einmalig angezeigt. Der Kunde sieht Termine, Zeiten, Fotos (Mitarbeiter nur mit Vorname), Rechnungen und kann Beanstandungen melden. Adresse: siehe „Portal-Adresse" unten.', 'k' => 'kundenportal portal zugang kunde passwort nachweis fotos', 'l' => '/admin/?s=kunden'],
            ['f' => 'Wie mache ich eine Besichtigung mit Angebot?', 'a' => 'Am Tablet oder Handy die Besichtigungs-App öffnen (Büro-Login). Sechs Schritte: Kunde, Objekt, Räume, Zustand und Rhythmus, Fotos, Kalkulation. „Angebot erstellen" erzeugt das PDF mit Nummer. Im Büro unter Angebote: „Angenommen → Objekt anlegen" übernimmt alles.', 'k' => 'besichtigung angebot kalkulation tablet neukunde interessent', 'l' => '/besichtigung/'],
            ['f' => 'Wie genehmige ich Urlaub oder Krankmeldungen?', 'a' => 'Mitarbeiter → „Abwesenheiten". Anträge aus der App stehen unter „beantragt": Genehmigen oder Ablehnen mit Kommentar. Krankmeldungen haben kein Grund-Feld — das ist Absicht (Art. 9 DSGVO). Das Urlaubskonto führt sich automatisch.', 'k' => 'urlaub krank krankmeldung abwesenheit genehmigen antrag zeitausgleich', 'l' => '/admin/?s=mitarbeiter&t=abwesenheiten'],
            ['f' => 'Wo sehe ich, ob sich ein Objekt rechnet?', 'a' => 'Berichte → „Objekte": Stunden Ist gegen Soll, Umsatz, für den Inhaber Lohnkosten und Deckungsbeitrag je Objekt. Berichte → „Kunden" zeigt Umsatz, offene Beträge und Zahlungsdauer. Alles als CSV exportierbar.', 'k' => 'berichte auswertung deckungsbeitrag umsatz objekt rentabel lohnkosten statistik', 'l' => '/admin/?s=berichte'],
            ['f' => 'Wie bearbeite ich eine Beschwerde?', 'a' => 'Beschwerden → öffnen → Maßnahme eintragen, Mitarbeiter zuordnen (optional), Status auf „erledigt". Meldungen aus dem Kundenportal landen automatisch hier. Berichte → „Beschwerden" zeigt die Auswertung je Mitarbeiter.', 'k' => 'beschwerde reklamation kunde unzufrieden bearbeiten', 'l' => '/admin/?s=beschwerden'],
            ['f' => 'Wie richte ich unsere Website ein?', 'a' => 'Einstellungen → „Website": Texte, Kennzahlen, Fotos, Impressumsangaben, Domain. „Vorschau öffnen" zum Prüfen, dann „Aktiv" setzen. Die Domain muss beim Anbieter auf den Server zeigen — kurz Bescheid geben. Kontaktanfragen von der Website landen unter Kunden → Anfragen.', 'k' => 'website homepage domain einrichten texte fotos vorschau freischalten', 'l' => '/admin/?s=einstellungen&t=website'],
            ['f' => 'Wie lege ich einen zweiten Bürobenutzer an?', 'a' => 'Einstellungen → „Bürobenutzer" → Benutzername und Passwort. Bürobenutzer sehen keine Löhne, Sozialversicherungsnummern, Alarmcodes und keinen Deckungsbeitrag — das bleibt beim Inhaber.', 'k' => 'bürobenutzer benutzer anlegen office zweiter zugang rechte', 'l' => '/admin/?s=einstellungen&t=benutzer'],
            ['f' => 'Passwort vergessen oder Zugang gesperrt?', 'a' => 'Auf der Anmeldeseite „Passwort vergessen?" — der Link kommt per E-Mail an die hinterlegte Adresse. Ein gesperrter Mitarbeiter wird im Büro unter Mitarbeiter → „Jetzt entsperren" freigegeben. Ist der Inhaber ausgesperrt, hilft der Anbieter (Kontakt unten).', 'k' => 'passwort vergessen gesperrt zugang login geht nicht entsperren'],
            ['f' => 'Wie schalte ich die Zwei-Faktor-Anmeldung ein?', 'a' => 'Mein Konto → „Zwei-Faktor-Anmeldung einrichten" → QR mit Google Authenticator, Microsoft Authenticator oder Aegis scannen → Code eingeben. Die acht Backup-Codes sicher ablegen. Für den Inhaber dringend empfohlen.', 'k' => 'zwei-faktor 2fa authenticator sicherheit code backup', 'l' => '/admin/?s=konto'],
            ['f' => 'Wie exportiere ich alle Daten?', 'a' => 'Einstellungen → „Datenexport" → ZIP mit allen Tabellen als CSV (Excel) und allen PDFs. Das ist Ihr Recht auf Datenübertragbarkeit (Art. 20 DSGVO) und die Sicherung bei Vertragsende.', 'k' => 'export daten sichern zip csv excel datenübertragbarkeit kündigung', 'l' => '/admin/?s=einstellungen&t=export'],
            ['f' => 'Wie nutze ich Subunternehmer?', 'a' => 'Mitarbeiter → „Subunternehmer" → anlegen → Leistung übertragen. Die Einsätze dieser Leistung laufen weiter im Dienstplan, sind aber dem Subunternehmer zugeordnet; die Abrechnungsbasis zum Abgleich mit dessen Rechnung steht im Detail.', 'k' => 'subunternehmer fremdfirma übertragen abrechnung', 'l' => '/admin/?s=subunternehmer'],
            ['f' => 'Wie erfasse ich Regieleistungen oder Material?', 'a' => 'Rechnungen → „Regieleistungen" → erfassen: Kunde, Objekt, Beschreibung, Menge, Preis, Fahrtkosten. Kommt beim nächsten Abrechnungslauf automatisch auf die Rechnung. Materialverbrauch trägt der Mitarbeiter im Einsatz ein oder das Büro im Einsatz-Detail.', 'k' => 'regie regieleistung sonderleistung material extra zusätzlich verrechnen', 'l' => '/admin/?s=rechnungen&t=regie'],
            ['f' => 'Wie sehen Kunden die Fotos?', 'a' => 'Mitarbeiter machen in der App Vorher- und Nachher-Fotos (EXIF und Standort werden entfernt). Der Kunde sieht sie im Portal getrennt nach Vorher und Nachher, das Büro im Einsatz-Detail. Nach drei Monaten werden sie automatisch gelöscht. Unter Einstellungen → Betrieb legen Sie fest, ob ein Nachher-Foto Pflicht ist.', 'k' => 'fotos vorher nachher nachweis kunde portal löschung pflicht'],
            ['f' => 'Wie bekommt ein Mitarbeiter seine Einsätze in den Handy-Kalender?', 'a' => 'In der App unter „Mehr → Kalender-Link anzeigen" → „Im Kalender abonnieren". Die Einsätze der nächsten acht Wochen erscheinen im Kalender und aktualisieren sich selbst.', 'k' => 'kalender abonnieren ics termine handy'],
            ['f' => 'Wie bekommen Mitarbeiter Push-Benachrichtigungen?', 'a' => 'In der App unter „Mehr → Benachrichtigungen einschalten". Auf dem iPhone vorher „Zum Home-Bildschirm hinzufügen". Dann kommt bei Einsatzanfragen und Nachrichten eine Meldung, auch bei geschlossener App.', 'k' => 'push benachrichtigung nachricht meldung iphone android'],
        ];
    }

    /** Suche: Treffer nach Gewicht, hoechstens $max. Leere Liste = nichts Brauchbares. */
    public static function suchen(string $frage, int $max = 4): array
    {
        $w = mb_strtolower(trim($frage));
        $w = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $w) ?? $w;
        $woerter = array_values(array_filter(preg_split('/\s+/', $w) ?: [], static fn($x) => mb_strlen($x) >= 3));
        if ($woerter === []) { return []; }
        $stopp = ['wie', 'kann', 'ich', 'das', 'die', 'der', 'ein', 'eine', 'und', 'für', 'mit', 'was', 'wo', 'ist', 'man', 'mein', 'meine', 'einen', 'nicht', 'bei', 'zum', 'zur', 'oder', 'auf', 'den', 'dem', 'des'];
        $woerter = array_values(array_diff($woerter, $stopp));
        if ($woerter === []) { return []; }
        $treffer = [];
        foreach (self::wissen() as $i => $e) {
            $text = mb_strtolower($e['f'] . ' ' . $e['k'] . ' ' . $e['a']);
            $score = 0;
            foreach ($woerter as $wo) {
                $stamm = mb_substr($wo, 0, max(4, (int) (mb_strlen($wo) * 0.75)));
                if (str_contains(mb_strtolower($e['f']), $wo)) { $score += 5; }
                elseif (str_contains(mb_strtolower($e['k']), $wo)) { $score += 4; }
                elseif (str_contains(mb_strtolower($e['k']), $stamm) || str_contains(mb_strtolower($e['f']), $stamm)) { $score += 2; }
                elseif (str_contains($text, $stamm)) { $score += 1; }
            }
            if ($score >= 3) { $treffer[] = ['score' => $score, 'i' => $i] + $e; }
        }
        usort($treffer, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($treffer, 0, $max);
    }
}
