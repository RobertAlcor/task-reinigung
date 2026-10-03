# Testplan Takt — vollständiger Durchlauf

Reihenfolge einhalten, jeder Schritt baut auf dem vorigen auf. Rechts neben jedem Punkt steht, was du sehen musst. Stimmt etwas nicht: Screenshot, Adresse und was du gemacht hast notieren.

Alle Zugänge stehen im Kapitel 0. Dauer für alles: etwa drei bis vier Stunden, auf zwei Tage verteilt, weil der Cronjob dazwischen laufen soll.

Du brauchst: PC, Handy (Android oder iPhone), Drucker, eine Authenticator-App (Google Authenticator, Microsoft Authenticator oder Aegis), Zugriff auf dein E-Mail-Postfach.

---

## 0. Zugänge und Adressen

Basisadresse: **https://reinigung.webdesign-alcor.at** — alle Pfade unten hängen daran.

### Dein Anbieter-Zugang
| | |
|---|---|
| Adresse | https://reinigung.webdesign-alcor.at/anbieter/ |
| Benutzername | `ALCOR` |
| Passwort | das beim Setup gewählte: ______________________ |

### Demo-Betrieb „Alcor Facility Management"
Wird im Anbieter-Dashboard mit „Demo-Betrieb anlegen" erzeugt. Existiert schon einer, diesen auf „gekündigt" setzen und neu anlegen. Alle Passwörter sind fest:

| Bereich | Adresse | Benutzername | Passwort / PIN |
|---|---|---|---|
| Büro als Inhaber | https://reinigung.webdesign-alcor.at/admin/ | `demo` | `Demo-Betrieb-2026` |
| Büro als Bürobenutzer | https://reinigung.webdesign-alcor.at/admin/ | `demo-buero` | `Demo-Betrieb-2026` |
| Besichtigung | https://reinigung.webdesign-alcor.at/besichtigung/ | `demo` | `Demo-Betrieb-2026` |
| Mitarbeiter-App | https://reinigung.webdesign-alcor.at/app/ | `1001` Andrea Novak | PIN `1234` |
| | | `1002` Milan Prohaska | PIN `1234` |
| | | `1003` Erol Yildiz | PIN `1234` |
| | | `1004` Fatma Demir | PIN `1234` |
| Kundenportal | https://reinigung.webdesign-alcor.at/portal/ | `kunde@alcor-facility.at` | `Demo-Betrieb-2026` |
| Website-Vorschau | https://reinigung.webdesign-alcor.at/index.php?betrieb=alcor-facility-management | — | angemeldet als Anbieter oder Büro |
| Vertriebsseite | https://reinigung.webdesign-alcor.at/ | — | öffentlich |
| Registrierung | https://reinigung.webdesign-alcor.at/registrieren | — | öffentlich |

Achtung: Kapitel 3.1 (Inhaber-Passwort ändern), 4.1.5 (Kundenportal-Passwort neu), 4.3.4 (PIN 1001 auf 9999) und 6.16 (PIN ändern) verändern diese Zugänge. Neue Werte hier eintragen:

| Zugang | neuer Wert |
|---|---|
| `demo` Passwort | ______________________ |
| Kundenportal Passwort | ______________________ |
| PIN 1001 | ______________________ |

### Was im Demo-Betrieb enthalten ist
- **Mitarbeiter:** 1001 Andrea Novak (Unterhalt, Fenster · 20 Std), 1002 Milan Prohaska (Unterhalt, Grund maschinell · 30 Std), 1003 Erol Yildiz (Unterhalt, Sonder, Hubsteiger · 20 Std), 1004 Fatma Demir (Unterhalt, Haushalt · 15 Std). Alle mit Verfügbarkeit Mo–Fr 6–21 Uhr, SVNR/IBAN leer.
- **Kunden:** Kanzlei Berger & Partner (aktiv, Portalzugang, Zahlungsziel 14 Tage), Ordination Dr. Weiss (aktiv, 30 Tage), Steuerberatung Kern (Interessent, Website-Anfrage, Besichtigung mit Angebot AN-2026-0001).
- **Objekte:** Kanzlei Berger 2. Stock (Unterhalt Mo Mi Fr 18–20, 480 €/Monat, Team Andrea + Milan; Glasreinigung monatlich 90 €/Einsatz — an Subunternehmer Blitz Glasreinigung KG übertragen), Kanzlei Berger Archiv (Unterhalt, 180 €, Erol), Ordination Dr. Weiss (Unterhalt, 650 €, Milan + Fatma).
- **Einsätze:** drei Monate zurück mit Zeiten und Fotos, acht Wochen voraus geplant; einige ausgefallen, einige Verspätungen.
- **Rechnungen:** RE-2026-0001 bezahlt, 0002 bezahlt, 0003 teilbezahlt, 0004 1. Mahnung, 0005 storniert, 0006 Gutschrift; Regieleistung offen; Material mit Verbrauch.
- **Abwesenheiten:** Milan krank 2 Tage (genehmigt), Andrea Urlaub beantragt, Erol Zeitausgleich, Fatma Einschulung und ein abgelehnter Urlaub.
- **Beschwerden:** eine erledigte (Papierkörbe, Milan), eine offene (Boden streifig). Zwei Kundenfeedbacks (5 und 4 Sterne).
- **App:** zwei Nachrichten, eine offene Einsatzanfrage an Fatma (1004).
- **Website:** Texte, sechs Fotos, Impressumsangaben — noch nicht freigeschaltet.
- **Monatsabschluss** des Vormonats festgehalten.

---

## 1. Grundlagen (30 Minuten)

### 1.1 Mailversand
1. `system/config.php`: Mail-Block ausgefüllt? → Ja.
2. `/admin/?vergessen` aufrufen, `office@alcor-facility.at` eingeben → Meldung „E-Mail unterwegs". Keine Mail erwartet (Demo-Adresse), aber keine Fehlermeldung.
3. Anbieter → Einstellungen → E-Mail auf deine echte Adresse setzen. Dann `/admin/?vergessen` mit einer **echten** Adresse eines Testbenutzers: Büro → Einstellungen → Bürobenutzer → `demo-buero` bekommt deine private Adresse als E-Mail (Bearbeiten geht über Mitarbeiter → nein: unter Einstellungen → Bürobenutzer gibt es kein Feld dafür — nutze stattdessen Anbieter → Konto → deine Adresse und teste den Reset beim Anbieter: `/anbieter/` hat keinen Reset). **Einfachster Test:** Büro → Kunden → Kanzlei Berger → Bearbeiten → E-Mail auf deine private Adresse → Speichern → Kundenportal `/portal/?vergessen` mit deiner Adresse → **Mail kommt innerhalb einer Minute.** Link anklicken, neues Passwort setzen, im Portal anmelden.
   Kommt keine Mail: Spam-Ordner prüfen, dann phpMyAdmin → Tabelle `benachrichtigungen` → Spalte `fehler` lesen.

### 1.2 Cronjob
1. Anbieter → Einstellungen → Cronjob-URL kopieren.
2. Im Browser aufrufen → Textausgabe mit „Fertig in … s". Kein Fehler.
3. Bei easyname als Cronjob alle 15 Minuten eintragen.
4. **Am nächsten Tag** prüfen: Büro → Startseite → oben Tagesbericht-Mail an `office@alcor-facility.at` in `benachrichtigungen` (phpMyAdmin) oder besser: Anbieter-E-Mail vorübergehend als Betriebs-E-Mail unter Büro → Einstellungen eintragen, dann kommt der Tagesbericht an dich.

### 1.3 Sicherheit
1. `/system/config.php` im Browser aufrufen → 403 Forbidden.
2. `/system/fotos/` → 403.
3. `/setup.php` → 404 oder „Konfiguration existiert".
4. `/uploads/` → 403 (kein Listing).

### 1.4 Anbieter-Konto
0. Anmeldeseite: Auge neben dem Passwortfeld zeigt/verbirgt das Passwort. „Passwort vergessen?" → E-Mail eingeben → Mail mit Link (nur wenn Mailversand steht und im Konto eine E-Mail hinterlegt ist) → neues Passwort setzen → anmelden. Unter dem Formular: „Reinigungsbetrieb? Zum Büro".
0a. Konto → „QR-Code erzeugen" → mit dem Handy scannen → sofort im Anbieter-Dashboard, ohne Passwort. Am PC: Link aus der Box in einem privaten Fenster öffnen → dasselbe. „Widerrufen" → Link tot.
0b. Benutzer → „Benutzer einladen": Benutzername `partner`, Rolle Tester, Startpasswort → anlegen. In einem privaten Fenster als `partner` anmelden → sieht Betriebe und Anfragen, aber kein „Betrieb anlegen", keine Einstellungen, kein Benutzer-Menü, keinen Demo-Knopf; in der Betriebsansicht keine Verträge/Rechnungen/Status, wohl aber den Schnellzugang. Zurück als Superuser: „Zu Superuser", „Passwort neu" (neues Passwort einmalig sichtbar), „Deaktivieren" → partner kommt nicht mehr rein.
1. Anbieter → Konto → Zwei-Faktor einrichten: QR mit Authenticator scannen, Code eingeben → 8 Backup-Codes. **Notieren.**
2. Abmelden, anmelden → nach dem Passwort kommt die Code-Abfrage → Code aus der App → drin.
3. Abmelden, anmelden, einen Backup-Code eingeben → drin. Denselben Backup-Code noch einmal → abgelehnt.

---

## 2. Anbieter-Dashboard (20 Minuten)

1. Betriebe: Demo-Betrieb sichtbar mit Status „Test bis …", Tarif, Preis.
2. Betrieb öffnen → Kennzahlen (4 Mitarbeiter, 3 Objekte, 3 Kunden, Einsätze 30 Tage). Die fünf Bereichs-Links oben funktionieren.
3. Vertrag: Preis auf 149, Website-Modul an mit 49, Einrichtung einmalig 290, Zahlungsweise jährlich, nächste Abrechnung heute → Speichern.
4. „Rechnung für nächsten Zeitraum erstellen" → Rechnung AR-… mit drei Positionen: Einrichtung 290, Software 11 × 149 = 1.639, Website 11 × 49 = 539. Netto 2.468.
5. PDF öffnen → Pflichtangaben nach § 11 UStG (deine Firma, UID, Nummer, Datum, Leistungszeitraum, Netto, USt, Brutto).
6. „Senden" → nur wenn Mailer eingerichtet; sonst überspringen.
7. Als bezahlt buchen → Status bezahlt. Stornieren → Gegenrechnung mit neuer Nummer und negativem Betrag.
8. Noch einmal „Rechnung erstellen" → jetzt ohne Einrichtungsposition (nur einmal verrechnet).
9. Status/Tarif ändern: Status auf „aktiv" → Speichern. Inhaber-Passwort zurücksetzen → neues Passwort wird angezeigt (Demo-Passwort wird damit ungültig! Notieren oder Schritt am Ende machen).
10. Notiz zum Betrieb speichern.
11. Schnellzugang: „QR-Code und Link erzeugen" → QR-Bild und Link erscheinen. Adresse in einem privaten Browserfenster öffnen (ohne angemeldet zu sein) → landet direkt im Büro, ohne Login. Zurück im Anbieter-Dashboard, Seite neu aufrufen → Link steht noch da, das QR-Bild nicht mehr (Sicherheit). „Widerrufen" → derselbe Link führt jetzt zur normalen Anmeldeseite.
12. Anfragen: Eintrag „Selbstregistrierung" oder leer — später wieder.
13. Einstellungen: alle Felder ausfüllen (Firma, Inhaber, Adresse, UID, IBAN, Produktname, Gewerbe, Behörde, Kammer). Der rote Punkt am Menüpunkt verschwindet.
14. Übersicht: Diagramme „Umsatz je Monat" und „Betriebe neu/gekündigt" zeigen Balken.
15. Einstellungen → „Fällige Abo-Rechnungen automatisch erstellen" anhaken → Speichern. Beim Demo-Betrieb Vertrag: nächste Abrechnung = heute, Status aktiv. Cron-URL aufrufen → Ausgabe „Abo-Rechnung RE-A-… für Alcor Facility Management". Haken danach wieder entfernen.

---

## 3. Büro — Einrichtung (30 Minuten)

Anmelden als `demo`.

### 3.1 Konto
0. Rechts unten „Hilfe" → Panel mit Themenliste. „kunde zahlt nicht" eingeben → Mahn-Antwort aufgeklappt mit „Dorthin →". Unsinn eingeben („hubsteiger mond") → „Dazu habe ich keine Antwort" mit deiner E-Mail und „Dringend: Telefon". Esc schließt.
1. Mein Konto → Passwort ändern (altes `Demo-Betrieb-2026`, neues eigenes). **Merken.**
2. Zwei-Faktor einrichten wie beim Anbieter. Abmelden, anmelden mit Code.
3. Zwei-Faktor abschalten (Passwort eingeben) → beim nächsten Login keine Code-Abfrage.

### 3.2 Einstellungen → Betrieb
1. Logo hochladen (ein PNG oder JPG) → erscheint als Vorschau. Hausfarbe ändern (z. B. Blau) → Speichern.
2. Startseite neu laden → Menü-Akzente in der neuen Farbe? (Nein — die Hausfarbe wirkt auf Nachweise, Rechnungen, Website, nicht aufs Büro. Nur prüfen, dass gespeichert.)

### 3.3 Einstellungen → Leistungskatalog
1. Sechs Leistungsarten mit Qualifikation. Eine neue anlegen: „Teppichreinigung", Kategorie Sonder, 45 €, 240 Min, Qualifikation „Grundreinigung maschinell" → erscheint in der Liste.
2. Unten Qualifikationen: „Bügeln" hinzufügen → in der Liste mit 0 Mitarbeitern.

### 3.4 Einstellungen → Benachrichtigungen
1. Vorlage „Reinigung erledigt": Haken „Kunde per E-Mail" setzen → Speichern. (Wird in Kapitel 6 gebraucht.)

### 3.5 Einstellungen → Formulare
1. „Kundenhinweis Fotodokumentation" → PDF öffnet, mit Betriebsname und Adresse.
2. „Vereinbarung App-Nutzung" → PDF öffnet, zwei Seiten.

### 3.6 Einstellungen → Bürobenutzer
1. `demo-buero` vorhanden. Neuen Benutzer `test-office` mit Passwort anlegen → in Liste. Deaktivieren → grau. Aktivieren.

### 3.7 Einstellungen → Verträge
1. AV-Vertrag und AGB aufklappbar, Zustimmungsprotokoll mit Datum, Version, Benutzer.

### 3.8 Rechnungen → Layout
1. Bank, IBAN, BIC ausgefüllt. USt 20 %. „Kleinunternehmer" testweise anhaken → Speichern → Rechnung-PDF (Kapitel 8) zeigt Hinweis § 6 Abs. 1 Z 27 UStG. Danach wieder aushaken.

---

## 4. Büro — Stammdaten (40 Minuten)

### 4.1 Kunden
1. Liste: 2 aktive, Filter „Interessenten" → Steuerberatung Kern.
2. Kanzlei Berger öffnen: Fakten, 2 Objekte, Verlauf. Notiz schreiben → erscheint oben im Verlauf mit deinem Namen.
3. Bearbeiten: Zahlungsziel auf 21 → Speichern → im Detail „21 Tage".
4. Leistungsnachweis: Vormonat wählen → PDF mit allen Einsätzen des Monats, Summe Stunden, volle Namen.
5. Kundenportal-Block: Zugang existiert. „Passwort neu" → neues Passwort angezeigt. **Notieren**, das alte gilt nicht mehr.
6. Anfragen (Knopf oben): Eintrag von Lukas Kern, Status neu. „Kunde öffnen" → Steuerberatung Kern. Status auf „kontaktiert" setzen.
7. Neuen Kunden anlegen: „Ordination Dr. Huber", Adresse, E-Mail → angelegt.

### 4.2 Objekte
1. Liste: 3 Objekte mit Leistungen und Stammteam.
2. Kanzlei Berger 2. Stock öffnen: Zugang, Hinweise, Schlüssel und Alarmcode sichtbar (nur als Inhaber).
3. Leistungen: Unterhalt Mo Mi Fr 18–20, Pauschale 480, Team Andrea und Milan. Glasreinigung monatlich, 90 € je Einsatz.
4. „Ändern" bei Unterhalt: Mitarbeiter Erol zusätzlich anhaken → speichern → Team zeigt drei Namen.
5. QR-Schild → PDF A5 → **ausdrucken** (wird in Kapitel 6 gebraucht).
6. „Code erneuern" **nicht** jetzt (sonst gilt der Ausdruck nicht mehr). Am Ende von Kapitel 6 einmal testen.
7. Neues Objekt für Dr. Huber anlegen mit Alarmcode 4711 → Detail zeigt 4711 im Inhaber-Block.
8. Leistung dazu: Unterhalt, Di Do, 17–19, 90 Min, 320 € Pauschale, Team Fatma.
9. Neue Leistung „Beenden" → grau, „beendet" mit Datum.

### 4.3 Mitarbeiter
1. Liste: 4 aktive, Spalte App „noch nie" oder Datum.
2. Andrea öffnen: Qualifikationen, Abwesenheiten (Urlaubskonto), App-Zugang, Verfügbarkeit Mo–Fr, Stammobjekte, Beschwerden, Stunden.
3. Bearbeiten: SVNR `1234010180` und IBAN `AT611904300234573201` eintragen (Haken „Diese Angaben ändern") → Detail zeigt sie im Inhaber-Block.
4. PIN neu setzen: `9999` → Meldung. (Andrea hat ab jetzt PIN 9999.)
5. Verfügbarkeit: Sa 08–12 hinzufügen → erscheint.
6. Datenschutzinformation → PDF mit Name.
7. Neuen Mitarbeiter anlegen: 1005 Test Person, 10 Std, PIN 5555, Qualifikation Unterhalt → angelegt.
8. Bearbeiten → Austrittsdatum gestern → Speichern → Status „ausgeschieden", Filter „Alle" zeigt ihn grau.
9. Abwesenheiten (Knopf): Andreas Urlaubsantrag „beantragt" → Genehmigen → grün. Fatmas abgelehnter sichtbar. Neu eintragen: Milan krank heute → 1 Tag, kein Grund-Feld.
10. Subunternehmer (Knopf): Blitz Glasreinigung KG → Detail: übertragene Glasleistung, Abrechnungsbasis. „Beenden" → Zuordnung weg. Erneut übertragen.

---

## 5. Dienstplan und Ersatz (30 Minuten)

1. Dienstplan: aktuelle Woche, Mo/Mi/Fr je drei Einsätze, vergangene grün „erledigt".
2. „Plan aktualisieren" → „Plan ist aktuell" oder Anzahl neuer Einsätze (Dr. Huber-Objekt!). Zweimal drücken → beim zweiten Mal 0 neue.
3. Nächste Woche → Einsätze mit Team. Einen Montag-Einsatz Kanzlei Berger öffnen.
4. Rechts „Ersatz einteilen": Liste mit Qualifikation, „x / y Std diese Woche", verfügbar. Milan abhaken (Team), Fatma anhaken → Speichern → „Konflikt" falls Fatma parallel Ordination hat; sonst gespeichert mit „*" (Ersatz).
5. Kundenbenachrichtigung: phpMyAdmin `benachrichtigungen` → neuer Eintrag „Ersatzkraft für …" an berger — **ohne Grund**.
6. „Anfragen" bei Erol → „Anfrage gesendet", Status „Anfrage offen". (App-Antwort in Kapitel 6.)
7. Einsatz „ausgefallen" setzen → rot; Kunde bekommt „Termin entfällt".
8. Einsatz anlegen (manuell): morgen 10–12 Kanzlei Berger Archiv, Glasreinigung → erscheint; Ersatzvorschlag zeigt nur Andrea (Fensterreinigung) oben, andere „ohne Qualifikation".
9. Filter „Alle Mitarbeiter" → Andrea → nur ihre Einsätze.
10. Startseite „Heute": Einsätze heute, offene Einsatzanfrage sichtbar, Handlungsbedarf (offene Beschwerde, Urlaubsantrag falls noch offen).

---

## 6. Mitarbeiter-App am Handy (40 Minuten)

Handy, Browser, `/app/` — als Lesezeichen auf den Startbildschirm legen (Android: Menü → „Zum Startbildschirm", iPhone: Teilen → „Zum Home-Bildschirm").

1. Sprache: Handy auf Deutsch → App deutsch. Unter „Mehr" auf Türkisch umstellen → alles türkisch. Zurück auf Deutsch.
2. Login `1001` / `9999` (neue PIN aus 4.3). Falsche PIN → Fehlermeldung. Fünf Fehlversuche → 15 Minuten Sperre (Büro → Andrea → „Jetzt entsperren").
3. Plan: heutige und kommende Einsätze, mit Kollegen.
4. Einsatz von heute öffnen → Adresse, Zugang, Hinweise, Soll-Minuten, Route öffnen (Karten-App).
5. Fotos: zwei Bereiche VORHER und NACHHER, Vorher ist vor dem Einstempeln grün umrandet. „Vorher-Foto aufnehmen" → Kamera → Bild → „Foto übertragen", Vorschaubild erscheint im Vorher-Bereich, Zähler „1 Foto".
6. **„Ankommen — Code scannen"** → Kamera → ausgedrucktes QR-Schild → „Eingestempelt", Uhrzeit. Jetzt ist der Nachher-Bereich grün umrandet.
7. Vor dem Ausstempeln: Büro → Einstellungen → Betrieb → „Fotos in der App" auf **Pflicht** stellen. In der App „Fertig — ausstempeln" → Meldung „Bitte zuerst mindestens ein Nachher-Foto" — kein Ausstempeln. „Nachher-Foto aufnehmen" → Vorschau im Nachher-Bereich → jetzt Ausstempeln möglich. Danach Einstellung auf „Hinweis" zurück.
7. Notiz „Seife fehlt" → speichern.
8. **Flugmodus ein.** „Fertig — ausstempeln" → Pause 10 → „Ausgestempelt — wird übertragen". Gelbe Leiste „Offline · 1 gespeichert".
9. **Flugmodus aus** → Leiste verschwindet, Einsatz zeigt „Erledigt … 0:xx Std".
10. Büro → Zeiterfassung: Einsatz mit Kommen, Gehen, Pause 10. Büro → Dienstplan → Einsatz öffnen → Abschnitt „Fotos" mit Spalten Vorher und Nachher, Uhrzeit und Name; Klick öffnet groß.
11. QR-Schild mit der normalen Handykamera scannen (nicht in der App) → öffnet `/app/?c=…` → stempelt direkt ein (nächster Einsatz an diesem Objekt) oder meldet „kein Einsatz eingeteilt".
12. „Frei": Urlaub in drei Wochen beantragen → „Gesendet". Krankmeldung für morgen → kein Grund-Feld. Büro → Abwesenheiten → beide „beantragt", genehmigen → App zeigt „genehmigt", Nachricht unter „Mehr → Nachrichten".
13. „Zeiten": Monatsstunden und letzte Einsätze mit Pause.
14. „Mehr → Anfragen": Login als `1004` / `1234` → offene Anfrage „Kannst du einspringen?" → **Ja** → „du bist eingeteilt", Einsatz im Plan. Büro → Einsatz → „hat zugesagt", Fatma zugeteilt mit *.
15. Login als `1003` / `1234` → Anfrage aus 5.6 → **Nein** mit Text „Bin beim Arzt" → Büro zeigt „hat abgesagt · Bin beim Arzt", Startseite auch.
16. PIN ändern unter „Mehr" → abmelden, mit neuer PIN anmelden.
17. „Mehr → Benachrichtigungen einschalten" (Android/Chrome direkt; iPhone erst nach „Zum Home-Bildschirm") → Erlaubnis geben → „Eingeschaltet". Büro → Dienstplan → Einsatz → „Anfragen" an diesen Mitarbeiter → innerhalb von Sekunden erscheint eine Meldung am Handy „Kannst du einspringen?". Antippen → App öffnet die Anfragen.
18. „Mehr → Kalender-Link anzeigen" → „Im Kalender abonnieren" → Handy-Kalender fragt nach Abo → Einsätze der nächsten acht Wochen erscheinen.
19. Zum Schluss: Büro → Objekt → „Code erneuern" → altes Schild scannen → „Code gehört zu keinem Objekt". Neues Schild drucken.

---

## 7. Kundenportal (20 Minuten)

Anmelden `kunde@alcor-facility.at` mit dem Passwort aus 4.1.5 (oder Demo-Passwort, falls nicht zurückgesetzt).

1. Übersicht: nächste Reinigungen, zuletzt erledigt, offene Rechnungen (Summe).
2. Nachweise: Monat mit Liste, Zeiten „18:02 – 20:11". Einsatz öffnen → Fotos vorher/nachher, Team als „Andrea N." — **kein Nachname**. Foto anklicken → groß.
3. „Monat als PDF" → Leistungsnachweis mit Initialen.
4. Rechnungen: Liste mit Status, überfällige rot. PDF öffnen.
5. Objekte: Kanzlei-Objekte mit Turnus. **Ordination Dr. Weiss darf nicht erscheinen.**
6. Melden: Beanstandung zu einem Einsatz → „eingegangen". Feedback 5 Sterne → „Danke".
7. Büro → Beschwerden → neue Beschwerde vom Portal; Feedback-Tab → 5 Sterne.
8. Konto: Passwort ändern → abmelden → mit neuem anmelden.
9. Abmelden. `/portal/?vergessen` → Ablauf wie 1.1.

---

## 8. Rechnungen und Regie (30 Minuten)

0. Büro am Tablet: dieselben Seiten wie am PC — Menü oben, Tabellen scrollen seitlich, kein Abschneiden.
1. Rechnungen → Liste: Kennzahlen offen/überfällig/Entwürfe/Umsatz. Filter „Alle": RE-0001 bezahlt, 0003 teilbezahlt, 0004 1. Mahnung, 0005 storniert, 0006 Gutschrift.
2. RE-0003 öffnen → Rest buchen (Betrag leer) → bezahlt.
3. RE-0004 (1. Mahnung, überfällig) → „2. Mahnung erstellen" → Mahnliste zeigt Verzugstage, Zinsen, 40 € Betreibungskosten, Gesamt. „PDF" öffnet die Mahnung mit Inkasso-Hinweis. Bei eingerichtetem Mailversand „Per E-Mail senden". Vorher unter Layout → Mahnwesen den Zinssatz auf Basiszins + 9,2 setzen.
4. Regieleistungen: offene „Sonderreinigung Küche". Neue erfassen: Kanzlei Berger, „Nacharbeit Fenster", 2 Std, 40 → 80 €.
5. Abrechnungslauf: Vormonat → Vorschau je Kunde mit Pauschale, Glas je Einsatz, Regie. „Entwurf erzeugen" bei einem Kunden ohne Rechnung → Entwurf.
   Hinweis: Für Monate, die schon eine Rechnung haben, erscheint „RE-… öffnen" statt Entwurf.
6. Entwurf öffnen: Position hinzufügen „Zusatz Kaffeeküche", 1, 25 → Summe steigt. Position ändern, löschen. Vorschau-PDF mit Wasserzeichen ENTWURF.
7. Festschreiben → Nummer RE-2026-0007, keine Bearbeitung mehr. PDF ohne Wasserzeichen, mit Logo und Hausfarbe aus 3.2.
8. Per E-Mail senden → wenn Mailer läuft und Kundenadresse deine ist: Mail mit PDF-Anhang.
9. Teilzahlung 100 → teilbezahlt. Storno → Gutschrift RE-2026-0008 negativ, Original storniert, Regie wieder offen.
10. Layout: Kleinunternehmer an → neuen Entwurf festschreiben → PDF ohne USt-Zeile mit § 6-Hinweis. Wieder aus.

---

## 9. Besichtigung und Angebot (20 Minuten)

Tablet oder Handy, `/besichtigung/`, Login `demo`.

1. Start: Liste mit vorhandener Besichtigung (Steuerberatung Kern, Angebot).
2. „Neue Besichtigung": Neuen Interessenten anlegen (Firma, Kontakt, Adresse) → vorausgewählt. Weiter.
3. Objekt: Kanzlei, 180 m², 6 Räume (Zähler). Weiter.
4. Räume: 2 WC, 1 Küche, 1 Besprechung, Empfang. Weiter.
5. Zustand: Gesamteindruck 2 (schlecht) → Preis in der Fußleiste steigt (Faktor 1,3). Intervall 3×, Mo Mi Fr. Zutritt-Felder. Weiter.
6. Fotos: „Foto aufnehmen" → gespeichert, Zähler. Hygiene-Text. Weiter.
7. Kalkulation: Stundensatz 32, Rabatt 5 → Monatsnetto. „Angebot erstellen" → AN-2026-0002, PDF öffnen: Leistungsumfang, Preis, Bedingungen.
8. Büro → Angebote: AN-0002 Entwurf. „Angenommen → Objekt" → Objekt angelegt mit Leistung Mo Mi Fr 18–20, Pauschale = Angebotsnetto, Kunde aktiv. Dienstplan → Plan aktualisieren → Einsätze für dieses Objekt (ohne Team, gelb).
9. Zurück zur Besichtigungsliste → Status „Angebot".

---

## 10. Beschwerden, Berichte, Export (25 Minuten)

1. Beschwerden: offene (Boden streifig) → Detail → Maßnahme „Nachreinigung 15.9., Kunde informiert", Mitarbeiter Milan, Status erledigt.
2. Neue Beschwerde erfassen mit Einsatz-Auswahl (nach Objektwahl lädt die Liste).
3. Kundenfeedback-Tab: Durchschnitt, Liste.
4. Übersicht-Tab: je Mitarbeiter Einsätze/Verspätet/Nachträge/Beschwerden, je Objekt.
5. Berichte → Übersicht: drei Diagramme mit Balken, Umsatz je Stunde, offene Forderungen gestaffelt.
6. Kunden: Umsatz, Anteil-Balken, Ø Zahlungsdauer in Tagen.
7. Objekte: Std Ist/Soll mit Abweichung, Umsatz, **Lohnkosten und Deckungsbeitrag** (Inhaber).
8. **Abmelden, als `demo-buero` anmelden** → Berichte → Objekte: **keine** Lohn-Spalten. Mitarbeiter → Andrea: keine SVNR, kein Lohn. Einstellungen: Betrieb nicht änderbar, kein Tab Bürobenutzer/Export/Website. Objekte → kein Alarmcode. Wieder als `demo` anmelden.
9. Mitarbeiter-Tab, Abwesenheiten-Tab (Krankenquote, Mo/Fr-Beginn, Rechtshinweis), Beschwerden-Tab (je 100 Einsätze, Klick auf Namen → Einzelfälle), Offene Posten.
10. Monat/Jahr umstellen → Zahlen ändern sich. „Als CSV" → Datei öffnet in Excel mit Umlauten.
11. Zeiterfassung: Monatsstand, „Festhalten" beim Vormonat → PDF zur Unterschrift. „Neu berechnen". Korrigieren mit Grund. Nachtragen für einen Einsatz ohne Zeit. Export Lohnbüro CSV.
12. Einstellungen → Datenexport → ZIP: Ordner daten/ mit CSVs, rechnungen/, angebote/, LIESMICH.txt. `mitarbeiter.csv` enthält SVNR und IBAN entschlüsselt.

---

## 11. Website (20 Minuten)

1. Einstellungen → Website: Texte vorbefüllt, Fotos (6). Kennzahl 3 ausfüllen, FAQ ergänzen, Domain `test-alcor-facility.at` eintragen, **Aktiv** anhaken → Speichern.
2. „Vorschau öffnen" → Startseite mit Wochenraster, Titelbild, Kennzahlen, drei Leistungen mit Fotos, Nachweis-Block, FAQ, Kontakt. Leiste oben „Vorschau — Website ist aktiv". Menüpunkte Leistungen, Über uns (Team-Fotos, Bezirke), Kontakt, Impressum (alle Pflichtangaben), Datenschutz.
3. Kontaktformular in der Vorschau absenden → „Anfrage eingegangen" → Büro → Kunden → Anfragen: neuer Eintrag.
4. Handy-Ansicht der Vorschau: alles lesbar, kein horizontales Scrollen.
5. Google Analytics-ID `G-TEST123` eintragen → Vorschau zeigt Zustimmungsbanner; „Nein" → kein Banner mehr (localStorage). ID wieder entfernen.
6. Abmelden → Vorschau-Adresse ohne Login → **nicht** sichtbar (Vertriebsseite erscheint).
7. Echte Domain (optional): bei easyname eine Subdomain als Alias auf `/html/reinigung/public/` legen, im Website-Tab als Domain eintragen → unter dieser Adresse erscheint die Betriebs-Website ohne Vorschauleiste.

---

## 12. Vertriebsseite und Registrierung (15 Minuten)

1. `/` → Vertriebsseite: Preise 89/149/249, Website 49, Einrichtung 290, Screenshots laden, Rechtsblock, FAQ, Footer korrekt, „handprogrammiert von Webdesign ALCOR".
1a. Bewegung: Beim Laden treten Überschrift, Text und Bild im Hero-Bereich nacheinander auf. Langsam scrollen → Karten, Modul-Zeilen, Rechts-Kacheln, Preiskarten und die vier Schritte erscheinen gestaffelt beim Erreichen; die Linie zwischen den vier Schritten zieht sich auf. FAQ-Frage anklicken → klappt auf, „+" dreht sich zu „×"; zweite Frage anklicken → erste klappt zu. Ganz unten grünes Band „Bereit loszulegen?" mit großem Knopf. Alles wirkt flüssig, nichts ruckelt oder verdeckt Text.
2. Handy-Ansicht.
3. Demo-Anfrage absenden → Anbieter → Anfragen: Eintrag „neu". Status auf „kontaktiert".
4. „Kostenlos testen" → Registrierung mit einer **zweiten** E-Mail-Adresse von dir, Tarif Plus → „Ihr Testzugang steht". Mail mit Zugangsdaten kommt.
5. „Jetzt ins Büro" → Login → AV-Vertrag und AGB bestätigen → leeres Büro mit Leistungskatalog und Qualifikationen. Anbieter → Betriebe: neuer Betrieb „Test bis …", Anfragen: „Selbstregistrierung".
6. Dieselbe Adresse noch einmal registrieren → „schon einen Zugang".
7. Impressum und Datenschutz der Vertriebsseite: deine Daten aus den Anbieter-Einstellungen.

---

## 13. Zweiter Tag (10 Minuten)

1. Cron ist über Nacht gelaufen: Anbieter → Cronjob-URL aufrufen → Ausgabe zeigt „Tagesbericht … eingereiht" nur beim ersten Lauf des Tages, „Sicherung: db-….sql.gz".
2. FileZilla → `system/backups/` → eine Datei von heute. Herunterladen, entpacken, in einem Texteditor öffnen → SQL mit deinen Tabellen.
3. Büro → Dienstplan → Woche 8 voraus → Einsätze vorhanden.
4. Anbieter → registrierter Testbetrieb aus 12.4: Status auf „gekündigt" setzen → dessen Login zeigt „Zugang beendet".
5. Löschung nach Vertragsende: Anbieter → Übersicht zeigt beim gekündigten Testbetrieb „Löschung in 30 Tagen fällig". Betrieb öffnen → „Betriebsdaten endgültig löschen" → ohne Bestätigungswort → abgelehnt; mit `LÖSCHEN` → Meldung mit Anzahl Datensätze und Dateien. Dessen Login → Anmeldung nicht mehr möglich. Deine Rechnungen an ihn bleiben sichtbar.
6. Testphase-Sperre simulieren: phpMyAdmin → `betriebe` → beim Demo-Betrieb `testphase_bis` auf gestern setzen, `status` bleibt `test` → Cron-URL aufrufen → Status `gesperrt` → Büro-Login zeigt „Testphase beendet" mit deinen Kontaktdaten. Danach im Anbieter-Dashboard Status auf „aktiv".

---

## Wenn etwas nicht funktioniert

- Seite „Es ist ein Fehler aufgetreten": `config.php` → `'debug' => true`, neu laden, Fehlertext notieren, wieder `false`.
- Mail kommt nicht: `benachrichtigungen` → Spalte `fehler`.
- App lädt nicht: Browser-Cache leeren oder Seite im privaten Fenster öffnen (Service Worker).
- Login gesperrt: 15 Minuten warten oder im Büro entsperren.

Nach dem Durchlauf: Liste der Punkte, die nicht wie beschrieben liefen — mit Kapitelnummer. Dann Bedienungsanleitung und Assistent.
