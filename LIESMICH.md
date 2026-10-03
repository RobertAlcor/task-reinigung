# Reinigungsverwaltung — Fundament

## Verzeichnisse

```
public/          ← Stammordner der Domain
  system/        ← Konfiguration, Klassen, Zugangsdaten — per .htaccess gesperrt
  api/           ← zentraler API-Einstiegspunkt
  setup.php      ← Einrichtung im Browser, danach löschen
```

easyname erlaubt PHP nur Zugriff innerhalb des Stammordners
(open_basedir). Deshalb liegt `system/` im Webroot und wird doppelt
gesperrt: durch `system/.htaccess` und durch eine Regel in der
`.htaccess` des Stammordners.

## Einrichten

1. `schema_v2.sql` in die leere Datenbank importieren.
2. Danach in dieser Reihenfolge: `schema_anbieter.sql`, `schema_anbieter_abrechnung.sql`, `schema_anbieter_einstellungen.sql`, `schema_zustimmungen.sql`, `schema_vertrieb.sql`, `schema_2fa.sql`, `schema_reset.sql`, `schema_qualifikationen.sql`, `schema_preise.sql`, `schema_loeschung.sql`, `schema_mahnungen.sql`, `schema_push.sql`, `schema_schnellzugang.sql`, `schema_anbieter_benutzer.sql`.
3. Inhalt von `public/` in den Stammordner hochladen.
4. `https://DOMAIN/setup.php` aufrufen — legt DEINEN Anbieter-Zugang an.
5. Angezeigten Schlüssel sichern, `setup.php` löschen.
6. Unter `https://DOMAIN/anbieter/` anmelden und den ersten Betrieb anlegen.

Den Verschlüsselungsschlüssel sichern. Geht er verloren, sind SVNR,
IBAN und Alarmcodes nicht mehr lesbar.

## Was das Fundament leistet

- **Database** — PDO, ausschließlich Prepared Statements. Jede Abfrage
  filtert automatisch nach `betrieb_id`; die kommt aus der geprüften
  Sitzung, nie aus der Anfrage. Tabellen- und Spaltennamen gegen
  Positivliste geprüft.
- **Crypto** — AES-256-GCM für SVNR, IBAN, Alarmcode, Schlüsselinfo.
  Manipulierte Werte werden beim Entschlüsseln erkannt.
- **Auth** — vier Rollen. Büro und Kundenportal über Session,
  Mitarbeiter-App über Bearer-Token. Sperre nach fünf Fehlversuchen.
  Tokens nur als Hash gespeichert. CSRF-Schutz für Formulare.
- **Http** — ein Einstiegspunkt für die gesamte API. Ohne Eintrag in
  der Routenliste gibt es keinen Zugang; jede Route außer Login und
  Statusabfrage verlangt Anmeldung und Rolle. CORS nur auf die eigene
  Domain.
- **.htaccess** — Verzeichnislisten aus, Uploads nicht ausführbar,
  Sicherheitskopfzeilen, Authorization-Weiterleitung für Tokens.

## Ebenen

- **Anbieter** (`/anbieter/`) — du. Sieht alle Betriebe, legt sie an,
  setzt Status und Tarif, verlängert Testphasen, setzt Inhaber-Passwörter
  zurück, führt Notizen. Verwaltet je Betrieb den Vertrag (Preis, Rabatt,
  Website-Modul, Zahlungsweise, USt) und erstellt daraus Abo-Rechnungen;
  bucht Zahlungen, storniert per Gegenrechnung. Übersicht zeigt
  Monatsumsatz, offene und überfällige Beträge. Sieht keine Betriebsdaten
  im Detail.

**Rechnungen:** Unter Einstellungen die Ausstellerdaten eintragen (Firma,
Anschrift, UID oder Kleinunternehmer, IBAN). Dann je Rechnung PDF ansehen
oder per Mail senden. PDFs liegen in `system/rechnungen/` — gesperrt,
Auslieferung nur nach Anmeldung. Für den Versand in `system/config.php`
den Abschnitt `mail` ausfüllen (SMTP-Postfach bei easyname).

Bibliotheken in `system/lib/`: dompdf 3.1.0 (PDF), PHPMailer 6.9.3 (Mail).
Benötigte PHP-Erweiterungen: pdo_mysql, openssl, mbstring, dom, gd, exif, zip.

Tarifpreise stehen in der Tabelle `anbieter_tarife` und sind dort änderbar.
Rechnungen sind nach Ausstellung unveränderbar; Korrektur nur per Storno.
Nach einem Storno das Feld „Nächste Abrechnung" im Vertrag prüfen, wenn
der Zeitraum neu abgerechnet werden soll.
- **Betrieb** — der Reinigungsbetrieb mit Inhaber, Office, Mitarbeitern
  und Kunden. Jeder sieht nur seinen eigenen Betrieb.

## Büro-Dashboard (`/admin/`)

Login für Inhaber und Büro. Beim ersten Login muss der Inhaber AV-Vertrag
und AGB bestätigen — gespeichert mit Version, Hash des Textes, Zeitpunkt,
Benutzer und IP in `betrieb_zustimmungen`. Bürobenutzer sehen bis dahin
nur einen Hinweis.

Vertragstexte liegen in `system/texte/` (Markdown, versioniert). Platzhalter
werden aus den Anbieter-Einstellungen gefüllt — die müssen davor ausgefüllt
sein. Wird ein Text geändert: neue Datei mit neuer Versionsnummer anlegen und
in `system/Consent.php` die Version hochzählen; alle Betriebe müssen dann
erneut zustimmen.

Seiten liegen in `admin/seiten/`. Vorhanden:

- **Heute** — Einsätze des Tages, fehlende Check-ins, Ersatzbedarf, offene Punkte
- **Kunden** — Liste, Detail, Verlauf, Website-Anfragen übernehmen
- **Objekte** — Leistungen mit Turnus und Stammteam, Zugangsdaten
  verschlüsselt (nur Inhaber), QR-Schild als PDF
- **Mitarbeiter** — Stammdaten, SVNR/IBAN verschlüsselt (nur Inhaber),
  App-Zugang mit PIN, Verfügbarkeit, Abwesenheiten mit Genehmigung,
  Urlaubskonto, Datenschutzinformation nach Art. 13 DSGVO als PDF
- **Dienstplan** — Wochenansicht, „Plan aktualisieren" erzeugt Einsätze aus
  den Objekt-Leistungen für acht Wochen (idempotent), Ersatzvorschlag aus
  Verfügbarkeit und Abwesenheit, Konfliktprüfung, Kundenbenachrichtigung
  ohne Nennung des Grundes
- **Zeiterfassung** — Monatsstand Soll/Ist je Mitarbeiter, Korrektur nur
  mit Grund, Nachtrag bei vergessenem Check-in, Monatsabschluss mit PDF zur
  Unterschrift, CSV-Export fürs Lohnbüro
- **Beschwerden** — Erfassung mit Bezug zu Objekt, Einsatz und
  Mitarbeiter, Bearbeitung mit Maßnahmen, Kundenfeedback, Faktenübersicht
  je Mitarbeiter und Objekt (Einsätze, Verspätungen, Nachträge,
  Beschwerden) — ohne Punktwert, ohne Krankenstand
- **Einstellungen** — Betriebsdaten mit Logo (wird mit GD neu gezeichnet
  und verkleinert), Leistungskatalog, Benachrichtigungsvorlagen mit
  Platzhaltern, Formularmuster als PDF (Kundenhinweis Fotodokumentation,
  Vereinbarung App-Nutzung auf dem Privathandy), Bürobenutzer (nur
  Inhaber), Verträge mit Zustimmungsprotokoll
- **Mein Konto** — Passwort ändern

- **Rechnungen** — Abrechnungslauf je Monat: Vorschau aus Pauschalen,
  erledigten Einsätzen, Stunden nach Aufwand, offenen Regieleistungen und
  Material; Entwurf mit editierbaren Positionen; Festschreiben vergibt die
  fortlaufende Nummer (Nummernkreis je Jahr) und macht die Rechnung
  unveränderbar; PDF mit Logo, Hausfarbe und Layouttexten; Versand per
  Mail; Teil- und Vollzahlung; Mahnstufen; Storno mit Gutschrift.
  Regieleistungen erfassen. Layout und USt/Kleinunternehmer je Betrieb.

- **Berichte** — Übersicht mit Umsatz, Stunden und Einsätzen je Monat
  (12 Monate, SVG-Balken ohne JavaScript), Umsatz je Stunde, offene
  Forderungen nach Fälligkeit; Kunden (Umsatz, Anteil, offen, mittlere
  Zahlungsdauer, Beschwerden); Objekte (Einsätze, Stunden Ist/Soll mit
  Abweichung, Umsatz, für den Inhaber Lohnkosten × 1,30 und
  Deckungsbeitrag); Mitarbeiter (Stunden Ist/Soll, Verspätungen, Nachträge,
  Urlaub); Abwesenheiten (Krankenstandstage, Meldungen, eintägige,
  Mo/Fr-Beginn, Krankenquote je Mitarbeiter und Betrieb, Verlauf je Monat,
  Urlaub, Zeitausgleich — mit Einordnung und Rechtshinweis: Dauer und
  Häufigkeit sind zulässig, der Grund wird nie gespeichert, kein Score);
  Beschwerden je Mitarbeiter nach Kategorie, je 100 Einsätze, mit
  Einzelfallliste; offene Posten. Zeitraum Monat oder Jahr, jede Tabelle
  als CSV. Im Mitarbeiter-Detail zusätzlich die Beschwerden der letzten
  zwölf Monate.
  Bürobenutzer sehen keine Lohndaten — auch nicht im CSV.
- **Leistungsnachweis** je Kunde und Monat als PDF: alle Einsätze mit
  Zeiten, Dauer, Team und Fotoanzahl, Summe der Stunden; im Büro (Kundenakte,
  volle Namen) und im Kundenportal (Vorname und Initial).
- **Subunternehmer** (über Mitarbeiter erreichbar) — Stammdaten,
  Einkaufssatz, Übertragung ganzer Objekt-Leistungen; geplante Einsätze
  wandern zum Subunternehmer, das Stammteam wird abgezogen; monatliche
  Abrechnungsbasis aus erledigten Einsätzen × Soll-Dauer × Satz
- **Datenexport** (Einstellungen, nur Inhaber) — ZIP mit allen Tabellen
  als CSV (UTF-8, Semikolon) und allen Rechnungs- und Angebots-PDFs;
  verschlüsselte Felder entschlüsselt; jeder Export im Protokoll.
  Benötigt die PHP-Erweiterung zip.

Damit ist das Büro vollständig.

## Mitarbeiter-App (`/app/`)

Progressive Web App ohne App-Store: Mitarbeiter öffnen die Adresse am
Handy und legen sie auf den Startbildschirm. Login mit Personalnummer und
PIN, Token 30 Tage.

- Einsatzplan (gestern bis 14 Tage voraus), Einsatzdetails mit Zugang und
  Hinweisen, Route öffnen
- Check-in/Check-out per QR-Scan (Kamera; BarcodeDetector, sonst jsQR)
  oder Code-Eingabe. Das QR-Schild verweist auf `/app/?c=<token>` — ein
  Scan mit der Handykamera öffnet die App und stempelt direkt ein oder aus.
- Fotos vorher/nachher: am Gerät auf 1600 px verkleinert, am Server mit GD
  neu kodiert (EXIF und GPS entfernt), Ablage unter `system/fotos/` —
  nicht öffentlich, Auslieferung nur nach Anmeldung über `/api/fotos/<id>`
- Offline: App-Hülle im Service Worker, Plan im Speicher, Check-ins,
  Check-outs, Notizen und Fotos in einer IndexedDB-Warteschlange; Abgleich
  bei Verbindung. Gerätezeit wird übernommen, wenn sie in der
  Vergangenheit und höchstens 24 Stunden alt ist.
- Abwesenheit beantragen (Krankmeldung ohne Grund), Urlaubskonto,
  Stundenübersicht, Nachrichten vom Büro, PIN ändern
- Keine Ortung, kein Zugriff auf Kontakte oder Fotos des Geräts
- Fotos in zwei abgegrenzten Bereichen VORHER und NACHHER mit Zähler und
  Vorschaubildern (Server liefert kleine Vorschauen, offline aufgenommene
  Fotos erscheinen sofort). Vor dem Check-in ist Vorher hervorgehoben, danach
  Nachher. Betriebseinstellung „Fotos in der Mitarbeiter-App": freiwillig /
  Hinweis beim Ausstempeln ohne Nachher-Foto / Nachher-Foto Pflicht.
  Büro (Einsatz) und Kundenportal zeigen die Fotos getrennt nach Vorher und
  Nachher.
- Sieben Sprachen: Deutsch, Englisch, Türkisch, Kroatisch/Bosnisch/Serbisch,
  Rumänisch, Polnisch, Ungarisch (`app/i18n.js`). Beim ersten Start nach
  der Handysprache, umschaltbar unter „Mehr", wird am Gerät gemerkt.
  Rechtstexte bleiben Deutsch.

## White-Label-Website (`/index.php`, Kundendomains)

`public/index.php` ist der Front-Controller: Passt der aufgerufene Host zu
`website_config.domain` eines freigeschalteten Betriebs, wird dessen
Website ausgeliefert (Startseite, /leistungen, /ueber-uns, /kontakt,
/impressum, /datenschutz — Rewrite in `.htaccess`). Andernfalls die
Vertriebsseite des Anbieters (folgt) bzw. ein Platzhalter.

Einrichtung je Betrieb: im Büro unter Einstellungen → Website Texte,
Kennzahlen, Leistungen mit Texten, FAQ, Impressumsangaben (§ 5 ECG,
§ 14 UGB, § 25 MedienG), Fotos (Titelbild, Leistungen, Team), Domain und
Freischaltung. Vorschau über den Knopf „Vorschau öffnen" — nur für
Angemeldete des Betriebs oder den Anbieter. Der Anbieter richtet die
Kundendomain bei easyname als Alias-Domain auf den Stammordner `public/`
ein; der Betrieb setzt beim Registrar den A-Record.

Technik: Richtung „Takt" (Bricolage Grotesque + DM Sans über Bunny
Fonts), Hausfarbe je Betrieb, Scroll-Einblendungen nur mit JavaScript
und nicht bei reduzierter Bewegung, JSON-LD LocalBusiness, canonical,
Kontaktformular mit Honeypot, Mindestzeit und Ratenlimit (3 pro IP und
10 Minuten), keine Cookies; Google Analytics nur optional mit
Zustimmungsbanner. Datenschutzerklärung wird aus den Betriebsdaten
erzeugt (Hosting easyname, Kontaktformular, Bunny Fonts, ggf. GA).
Footer-Vermerk „handprogrammiert von Webdesign ALCOR".

## Qualifikationen und Einsatzanfragen

- **Qualifikationen** je Betrieb (Einstellungen → Leistungskatalog, unten):
  Standard beim Anlegen: Unterhaltsreinigung, Fensterreinigung,
  Grundreinigung maschinell, Haushalt und Bügeln, Ordination und Hygiene,
  Sonderreinigung, Hubsteiger — frei erweiterbar. Beim Mitarbeiter angehakt;
  bei der Leistungsart als Voraussetzung hinterlegt. Der Ersatzvorschlag im
  Dienstplan reiht qualifizierte Kräfte zuerst und markiert die anderen,
  zeigt geplante Wochenstunden gegen die Sollstunden und die Verfügbarkeit.
- **Preismodelle** je Objekt-Leistung: Pauschale pro Monat, Preis je
  Einsatz oder Stundensatz; Regieleistungen mit eigenem Satz. Kategorien:
  Unterhalt, Grund, Fenster, Sonder, Haushalt, Regie.
- **Einsatzanfrage**: Im Dienstplan „Anfragen" schickt die Frage in die App
  („Kannst du einspringen?"). Der Mitarbeiter antwortet mit Ja oder Nein
  und optional einem Satz. Bei Zusage wird er nach Konfliktprüfung
  automatisch als Ersatz zugeteilt, weitere offene Anfragen zu diesem
  Einsatz werden zurückgezogen und die Betroffenen informiert, der Kunde
  bekommt die Ersatz-Benachrichtigung. Antworten stehen im Einsatz, auf der
  Startseite und gehen als Mail ans Büro. Anfragen zu vergangenen Einsätzen
  verfallen automatisch.

## Gesamtdurchlauf

`gesamt.sh` (Entwicklungsumgebung) spielt gegen eine leere Datenbank den
kompletten Weg durch: setup.php im Browser → Anbieter-Einstellungen →
Betrieb anlegen → erster Inhaber-Login mit Zustimmung → Passwort und
Zwei-Faktor → Betriebsdaten, Bürobenutzer, Mitarbeiter → Besichtigung →
Angebot → Übernahme als Objekt → Stammteam, QR-Schild → Dienstplan →
App-Check-in mit Foto und Check-out → Urlaubsantrag und Genehmigung →
Kundenportal mit Nachweis und Beschwerde → Monatsabschluss, CSV →
Abrechnungslauf, Rechnung, PDF, Rechnung im Portal → Website unter
Kundendomain → Vertriebsseite → Abo-Rechnung des Anbieters → Datenexport
→ Cronjob. 23 Schritte, alle bestanden (Stand 12.09.2026).

## Passwort vergessen, Testphase, Sicherung

- **Passwort vergessen** für Büro (`/admin/?vergessen`) und Kundenportal
  (`/portal/?vergessen`): Link per Mail, Token nur als Hash gespeichert,
  eine Stunde gültig, einmal verwendbar, Antwort verrät nicht, ob die
  Adresse existiert, höchstens drei Anfragen je Stunde. Setzt konfigurierten
  Mailversand voraus; sonst Hinweis auf Anbieter bzw. Betrieb.
- **Testphase**: Der Cron setzt Betriebe mit abgelaufener Testphase auf
  `gesperrt`; das Büro zeigt dann eine Hinweisseite mit den Kontaktdaten
  des Anbieters, Daten bleiben erhalten. Der Anbieter schaltet im Dashboard
  wieder frei (Status auf aktiv).
- **Löschfristen** laut Datenschutzerklärung im Cron: Website-Anfragen ohne
  Kunde nach 6 Monaten, Vertriebsanfragen (neu, kontaktiert, abgelehnt) nach
  12 Monaten, Reset-Tokens nach 7 Tagen.
- **Sicherung**: täglich ein gzip-SQL-Dump aller Tabellen unter
  `system/backups/` (nicht öffentlich), 14 Tage aufbewahrt. Ohne mysqldump,
  läuft auf Shared Hosting. Zusätzlich empfohlen: das easyname-Backup und
  gelegentlich einen Dump per FileZilla herunterladen.
- **Kunde informieren „Reinigung erledigt"**: wird jetzt beim letzten
  Check-out eines Einsatzes ausgelöst, wenn die Vorlage unter Einstellungen →
  Benachrichtigungen für Kunden aktiviert ist (Standard: aus).

## Vertragsurkunde je Kunde

Anbieter → Betrieb → „Vertrag als PDF zum Unterschreiben"
(`system/Contract.php`): erzeugt aus den Vertragsdaten einen
unterschriftsreifen Einzelvertrag — Parteien mit UID, Leistungsumfang
und Entgelt aus dem Tarif (inkl. Website-Modul, Rabatt, Einrichtung,
Jahreszahlung), Laufzeit und Kündigung, Datenrückgabe nach Art. 20
DSGVO, Pflichten des Kunden (§ 26 AZG), Gerichtsstand, Unterschriftsfeld
— gefolgt von den AGB als Anlage 1 und dem AV-Vertrag nach Art. 28 DSGVO
als Anlage 2, dieser mit eigenem Unterschriftsfeld. Die Textfassungen
kommen aus `system/texte/`, dieselben, die der Betrieb beim ersten Login
in der Software bestätigt; die Urkunde dient der zusätzlichen
Beweissicherung auf Papier. Ablage in `system/vertraege/`.

## Mailversand im Anbieter-Dashboard

Anbieter → Einstellungen → „Mailversand": SMTP-Server, Port,
Verschlüsselung, Benutzer, Passwort, Absender. Werte liegen in
`anbieter_einstellungen` (`mail_*`) und überschreiben den `mail`-Block
in `config.php`, der nur noch als Fallback dient. „Speichern und Testmail
senden" prüft die Verbindung sofort und zeigt den SMTP-Fehler im Klartext.
Das Passwort wird maskiert angezeigt und nur überschrieben, wenn ein neues
eingetippt wird.

## Anmeldeseiten

Ein Design für Büro, Anbieter und Kundenportal (auch Passwort vergessen,
Neues Passwort, Zwei-Faktor): Wortmarke mit Bereichsangabe, Titel,
Untertitel, 48-px-Felder, Auge zum Passwort-Anzeigen rechts im Feld,
voller Knopf, Links darunter (Passwort vergessen, Wechsel zum anderen
Bereich). Der CSS/JS-Block steht in jedem Einstiegspunkt vor `</head>`.

## Anbieter-Benutzer, Rollen, Passwort vergessen, QR-Login

- **Rollen**: `superuser` (voller Zugriff — der beim Setup angelegte
  Benutzer) und `tester` (sieht alles, darf nichts verwalten: keine
  Betriebe anlegen, keine Verträge, Preise, Rechnungen, Status,
  Einstellungen, Löschung, keine Benutzerverwaltung; darf Betriebe ansehen,
  Anfragen lesen und den Schnellzugang von Testbetrieben nutzen). Die
  Sperre greift serverseitig bei jeder Aktion, nicht nur in der Anzeige.
- **Benutzer** (Menüpunkt, nur Superuser): einladen mit Benutzername,
  Name, E-Mail, Startpasswort und Rolle; Rolle wechseln, Passwort neu
  setzen (setzt Zwei-Faktor zurück, Passwort einmalig sichtbar),
  deaktivieren/aktivieren. Das eigene Konto kann man nicht ändern.
- **Passwort vergessen** auf der Anbieter-Anmeldeseite: Link per Mail an
  die im Konto hinterlegte Adresse, eine Stunde gültig, einmal verwendbar
  (`passwort_reset_anbieter`). Voraussetzung: Mailversand eingerichtet
  und E-Mail beim Benutzer eingetragen. Notfall ohne Mail: in phpMyAdmin
  `UPDATE anbieter_benutzer SET passwort_hash = '<Hash>', fehlversuche = 0,
  gesperrt_bis = NULL WHERE benutzername = 'ALCOR';` — den Hash liefert
  `php -r "echo password_hash('NeuesPasswort', PASSWORD_DEFAULT);"` oder
  jeder Online-bcrypt-Generator.
- **Anmelden per QR-Code** (Konto): jeder Anbieter-Benutzer erzeugt sich
  einen persönlichen QR-Code bzw. Link (`/anbieter/?zugang=…`), 30 Tage
  gültig, mehrfach nutzbar, widerrufbar; ersetzt Benutzername und Passwort,
  Zwei-Faktor bleibt. Wird nur einmal nach dem Erzeugen angezeigt.
- **Passwort anzeigen**: jedes Passwortfeld in Büro, Anbieter, Portal,
  Besichtigung und Registrierung hat ein Auge zum Ein-/Ausblenden.
- Beide Anmeldeseiten verweisen aufeinander („Sie sind der Anbieter?" /
  „Reinigungsbetrieb? Zum Büro").

## Hilfe-Assistent im Büro

Schwebender „Hilfe"-Knopf rechts unten auf jeder Büroseite. Öffnet eine
Suche über eine gepflegte Wissensbasis (`system/Hilfe.php`, rund 25
Themen: Mitarbeiter, App, PIN, Kunden/Objekte, Dienstplan, Ersatz,
Stempeln, Zeiten, Rechnungen, Mahnungen, Storno, Portal, Besichtigung,
Abwesenheiten, Berichte, Beschwerden, Website, Bürobenutzer, Passwort,
Zwei-Faktor, Export, Subunternehmer, Regie, Fotos, Kalender, Push). Treffer
mit „Dorthin →"-Link. Kein Treffer: E-Mail-Adresse für Fragen und
Telefonnummer für dringende Fälle aus den Anbieter-Einstellungen. Ohne
Treffer-Liste zeigt er alle Themen. Keine externe KI, keine Kosten, keine
Datenweitergabe; Endpunkt `/admin/?s=hilfe&frage=…` (nur angemeldet).

## Direktzugang des Superusers (Büro, App, Portal ohne Login)

Anbieter → Betrieb → „Direkt öffnen — ohne Login" (nur Superuser, bei
jedem Betrieb): „Büro als Inhaber", „App" als wählbarer Mitarbeiter,
„Portal" als wählbarer Kunde — öffnet sich im neuen Tab, sofort
angemeldet. Technik: einmaliges Token (`anbieter_direktzugang`, 2 Minuten,
einmal verwendbar), eingelöst in `/admin/?direkt=`, `/portal/?direkt=`
und `/app/?direkt=` (die App tauscht es über `POST api/app/direkt` gegen
ein App-Token mit 24 h Laufzeit). Jeder Zugriff steht im `audit_log`
des Betriebs; im Büro erscheint oben ein roter Balken „Anbieter-Ansicht
(Name)" mit „Beenden". Die Besichtigung nutzt dieselbe Büro-Sitzung.
Tester sehen diesen Block nicht; für sie bleibt der Schnellzugang der
Testbetriebe.

## Schnellzugang ohne Passwort (Anbieter → Testbetrieb)

Nur für Betriebe im Status „Test". Im Anbieter-Dashboard in der
Betriebsansicht: „QR-Code und Link erzeugen" — öffnet das Büro dieses
Betriebs direkt als Inhaber, ohne Benutzername oder Passwort. Gedacht
zum eigenen schnellen Testen (Scannen mit dem Handy) und zum Einladen
einer Kollegin oder eines Kollegen zum Mittesten, ohne eine zweite
Identität anzulegen oder ein Passwort weiterzugeben. Eigenschaften:
7 Tage gültig, mehrfach und von mehreren Personen gleichzeitig nutzbar,
jede Nutzung wird gezählt und im `audit_log` vermerkt. Bleibt Zwei-Faktor
für den Inhaber aktiviert, wird trotzdem der Code verlangt — nur das
Passwort entfällt. Der Zugang verfällt automatisch, sobald der Betrieb
auf „Aktiv" wechselt (Prüfung bei jeder Nutzung, nicht nur bei der
Erzeugung), und ist jederzeit über „Widerrufen" sofort sperrbar. Der
QR-Code selbst wird aus Sicherheitsgründen nur einmal direkt nach dem
Erzeugen angezeigt; danach nur noch der Link. Abgelaufene Zugänge räumt
der Cron nach 7 Tagen weg. Auf der Büro-Login-Seite weist ein Hinweis
„Sie sind der Anbieter?" auf `/anbieter/` hin, um genau diese Verwechslung
(ALCOR-Zugang bei einer Betriebs-Anmeldung) zu vermeiden.

## Push-Benachrichtigungen und Kalender-Abo (App)

- **Web Push** ohne Fremdbibliothek: VAPID-Schlüsselpaar (EC P-256) wird
  beim ersten Bedarf erzeugt und in `anbieter_einstellungen` gespeichert;
  JWT-Signatur ES256 über OpenSSL (`system/Push.php`). Gesendet wird ohne
  Nutzlast — der Service Worker holt beim Eintreffen die Kurzmeldung über
  `POST api/app/push/aktuell` (Identität = geheimer Abo-Endpoint) und zeigt
  sie an; beim Push-Dienst liegen keine Inhalte. Auslöser: neue
  App-Nachrichten und offene Einsatzanfragen (Cron alle 15 Minuten,
  Einsatzanfragen aus dem Dienstplan sofort). Abos in `push_abos`; 404/410
  vom Push-Dienst löscht das Abo. Unter „Mehr" ein- und ausschaltbar; nach
  dem ersten Login wird einmal leise um Erlaubnis gefragt. iPhone: nur nach
  „Zum Home-Bildschirm" (iOS 16.4+), die App weist darauf hin. Klick auf
  die Meldung öffnet Anfragen bzw. Nachrichten.
- **Kalender-Abo**: „Mehr → Kalender-Link anzeigen" liefert einen
  persönlichen ICS-Feed (`/api/kalender/<token>.ics`, Token in
  `benutzer.kalender_token`), abonnierbar in Apple/Google/Outlook-Kalender
  (webcal-Link), zwei Wochen zurück und acht voraus, Europe/Vienna.

## Mahnwesen

Rechnungen → Detail: „Zahlungserinnerung erstellen" (Stufe 1, ohne
Zinsen, Spesen laut Layout, meist 0) und danach „2. Mahnung erstellen"
(Verzugszinsen nach § 456 UGB für die Verzugstage, Betreibungskosten nach
§ 458 UGB, Standard 40 €, Inkasso-Hinweis). Zinssatz, Spesen und
Zahlungsfrist unter Rechnungen → Layout → Mahnwesen; der Zinssatz für
Unternehmergeschäfte ist Basiszinssatz + 9,2 Punkte, für Verbraucher 4 %.
Jede Mahnung liegt als PDF vor und kann per E-Mail gesendet werden; die
Mahnliste steht im Rechnungsdetail, Tabelle `mahnungen`.

## Automatische Abo-Rechnungen (Anbieter)

Anbieter → Einstellungen → „Fällige Abo-Rechnungen automatisch erstellen"
(und optional sofort senden). Der Cron erstellt dann täglich für aktive
Betriebe mit fälligem Abrechnungsdatum die Rechnung und meldet sie in der
Ausgabe. Ohne Haken bleibt alles manuell.

## Büro auf Tablet und Handy

Ab 1100 px Breite scrollen Tabellen innerhalb der Seite, Spalten stehen
untereinander, die Seitenleiste wird zur Kopfzeile; ab 700 px Kennzahlen
einspaltig. Geprüft auf 1024×768, 820×1180 und 390×844 ohne Überlauf.

## Löschung nach Vertragsende

Status „gekündigt" setzt `gekuendigt_am`. Die Anbieter-Übersicht zeigt
laufende und abgelaufene 30-Tage-Fristen (AV-Vertrag); in der
Betriebsansicht löscht „Endgültig löschen" (Bestätigungswort LÖSCHEN)
alle Daten des Betriebs in allen Tabellen samt Fotos, PDFs und Uploads.
Erhalten bleiben der Betriebsstamm ohne Kontaktdaten und die Rechnungen
des Anbieters an den Betrieb (§ 132 BAO, 7 Jahre). Vorher hat der Betrieb
den Datenexport im Büro. Reaktivierung (Status aktiv) löscht die Frist.

Datei-Backup: Der Cron sichert nur die Datenbank. Fotos (`system/fotos`)
und Uploads sind nicht enthalten — easyname-Backup nutzen und monatlich
per FTP sichern; Rechnungs-PDFs lassen sich aus der Datenbank neu erzeugen.

## Demo-Betrieb

Im Anbieter-Dashboard „Demo-Betrieb anlegen": Alcor Facility Management mit
vier Mitarbeitern (PIN 1234), zwei Kunden mit Objekten und Stammteams,
Kundenportal-Zugang, drei Monate Einsätze mit Zeiten, Plan für acht
Wochen voraus, Rechnungen (teils bezahlt), Regieleistung, Krankenstand,
Urlaubsantrag, Beschwerde, Feedback, Website-Anfrage, Besichtigung mit
Angebot, Website-Texte. Alle Zugänge werden nach dem Anlegen angezeigt:
Inhaber `demo`, Bürobenutzer `demo-buero`, Kunde
`kunde@alcor-facility.at`, Passwort jeweils `Demo-Betrieb-2026`.
Mehrfach anlegbar (demo1, demo2 …). Nur für Ansicht und Vorführung.

## Vertriebsseite: Animationen und Aufbau

Reihenfolge: Hero → drei Probleme → Module → So läuft der Start → Recht →
Preise → FAQ (10 Fragen, Akkordeon) → Abschluss-Band → Demo-Formular.
Bewegung (`vertrieb/vertrieb.css`): gestaffeltes Eintreten im Hero beim
Laden, dekorative driftende Blobs, sanfte Parallax auf dem Hero-Bild,
Scroll-Fortschrittsbalken, Kopfzeilen-Schatten beim Scrollen, gestaffelte
Reveals für Karten, Modul-Zeilen, Rechts-Kacheln, Preiskarten und Schritte,
Verbindungslinie zwischen den Schritten, schwebende Handy-Mockups,
Hover-Akzente, pulsierende Favoriten-Karte, wandernde Punkte im
Abschluss-Band. Alles unter `prefers-reduced-motion` abgeschaltet. Anker
landen dank `scroll-padding-top` unter der festen Kopfzeile. Preiskarten
mit Tagline je Tarif, Preis je Kraft, Icon-Liste, Zusatzkacheln.

### Bildmaterial
`vertrieb/img/robert.webp` (Porträt, textfrei, aus dem gelieferten
Bildmaterial geschnitten) im Abschnitt „Wer dahinter steht" zwischen Recht
und Preise; `og.jpg` als Vorschaubild für geteilte Links (og:image,
Twitter Card); Wortmarke mit Blatt als Inline-SVG in Kopf und Fuß. Die
Werbebanner mit eingebranntem Text sind für Social Media, Anzeigen und
Print gedacht, nicht für die Website (Text nicht responsiv, nicht
indexierbar; die gezeigten Dashboards sind Illustrationen, die Website
zeigt das echte Programm). Die Crowdfunding-Motive („Unterstütze Takt")
gehören nicht auf die Vertriebsseite.

## Selbstregistrierung (`/registrieren`)

Interessenten legen sich den Testzugang selbst an: Betrieb, Name, E-Mail
(= Benutzername), Passwort, Tarif. Der Betrieb entsteht sofort mit 30
Tagen Testphase, Leistungskatalog, Qualifikationen, Vorlagen; der Cron
sperrt nach Ablauf. Zugangsdaten und Adressen per Mail, Anbieter bekommt
Mail und einen Eintrag unter Anfragen (Status Testphase). Schutz:
Honeypot, Mindestzeit, höchstens drei Testzugänge je IP und Stunde,
höchstens 20 neue Testbetriebe je Tag, keine doppelten E-Mail-Adressen.
Beim ersten Login bestätigt der Inhaber AV-Vertrag und AGB wie gewohnt.
„Kunden-Login" in der Navigation führt bestehende Kunden zum Büro; die
Büro-Anmeldeseite verweist Interessenten auf die Registrierung.

## Preise (Stand 09/2026)

Basis 89 € (bis 10 Kräfte), Plus 149 € (bis 25), Pro 249 € (bis 50), darüber
auf Anfrage; Website-Modul 49 €/Monat; Einrichtung 290 € einmalig bei Basis
und Plus (Pro inklusive); Jahreszahlung mit einem geschenkten Monat (11
verrechnet). Tarife liegen in `anbieter_tarife`, der Preis je Betrieb im
Vertrag (`anbieter_vertraege`) — bestehende Verträge behalten ihren Preis.
Einrichtungsgebühr im Vertrag eintragen; sie erscheint auf der nächsten
Rechnung und wird dann als verrechnet markiert.

## Zwei-Faktor-Anmeldung

Für Büro-Benutzer (Mein Konto) und den Anbieter (Konto): TOTP nach
RFC 6238, kompatibel mit Google Authenticator, Microsoft Authenticator,
Aegis, 1Password. Einrichtung per QR-Code, Bestätigung mit einem Code,
acht Backup-Codes (je einmal gültig, gehasht gespeichert). Das Geheimnis
liegt verschlüsselt in der Datenbank. Nach dem Passwort ist die Sitzung
unvollständig, bis der Code stimmt (fünf Minuten Frist); ein Code gilt
nur einmal; Fehlversuche zählen wie beim Passwort zur Sperre. Abschalten
nur mit Passwort. Implementierung in `system/Totp.php` ohne Bibliothek,
gegen die RFC-Testvektoren geprüft.

## Vertriebsseite (`/vertrieb/`)

Wird auf der Hauptdomain ausgeliefert, wenn kein Kundenbetrieb zum Host
passt. Produktname (Standard „Takt"), Anbieterdaten und Impressumsangaben
kommen aus den Anbieter-Einstellungen; Preise aus `anbieter_tarife`.
Inhalte: Nutzenversprechen, drei Probleme, fünf Module mit echten
Screenshots (`vertrieb/img/*.webp`), Rechtssicherheit (AZG, AVRAG, DSGVO,
ArbVG, UStG, BAO), Preistabelle, Ablauf, FAQ, Demo-Anfrage. Anfragen
landen in `anbieter_anfragen` und im Anbieter-Dashboard unter Anfragen;
zusätzlich Mail an die Anbieter-Adresse, wenn Mailversand konfiguriert.
JSON-LD SoftwareApplication, Impressum und Datenschutz für den Anbieter.

## Besichtigungs-App (`/besichtigung/`) und Angebote

Für Tablet und Handy, Anmeldung mit Büro-Zugang. Sechs Schritte: Kunde
(auch neuer Interessent), Objekt, Räume mit Zähler-Tasten, Zustand und
Rahmen, Fotos, Kalkulation. Live-Preis in der Fußleiste. Kalkulation wie
im alten Wizard: Fläche / 55 m² pro Stunde, Zuschläge je WC, Urinal,
Dusche, Küche, Empfang, Besprechungsraum, Zustandsfaktor 0,9–1,3,
Einsätze pro Monat je Intervall, Stundensatz aus dem Leistungskatalog.
Zwischenstand liegt am Gerät, Speichern bei jedem Schritt. „Angebot
erstellen" erzeugt Angebot mit Nummer (AN-Jahr-Nummer) und PDF. Im Büro
unter Angebote: PDF, Senden, „Angenommen → Objekt" legt Objekt und
Objekt-Leistung mit Turnus, Tagen, Zeiten und Pauschale an; der
Dienstplan übernimmt ab dann.

## Kundenportal (`/portal/`)

Zugang je Kunde wird im Büro in der Kundenakte angelegt (Benutzername
frei wählbar, Startpasswort wird einmalig angezeigt). Der Kunde sieht nur
seine eigenen Objekte: nächste Termine, Nachweise mit Zeiten und Fotos
(Mitarbeiter nur mit Vorname und Initial), Rechnungen als PDF, Objekte
mit Turnus. Er kann Beanstandungen melden (landen als Beschwerde im Büro
mit Benachrichtigung) und Feedback abgeben. Fotos werden nur ausgeliefert,
wenn der Einsatz zu einem Objekt des Kunden gehört; die Hausfarbe des
Betriebs färbt das Portal.

## Cronjob (`/cron/`)

Alle 15 Minuten aufrufen. Bei easyname: Cronjob als URL-Aufruf mit dem
Token aus dem Anbieter-Dashboard unter Einstellungen:
`https://DOMAIN/cron/?token=…`. Aufgaben: Dienstplan acht Wochen
fortschreiben, Benachrichtigungen versenden, Tagesbericht von gestern ans
Büro (einmal täglich), Fotos nach drei Monaten löschen, Anmeldeprotokoll
nach zwölf Monaten und abgelaufene Tokens bereinigen, Erinnerung sieben
Tage vor Ende der Testphase.

Logik in `system/`: `Schedule.php` (Dienstplan), `Mailer.php` (Versand über
das SMTP-Konto des Anbieters; Betriebsname als Absendername, Betriebs-E-Mail
als Antwortadresse). Benachrichtigungen landen in der Tabelle
`benachrichtigungen` und werden vom Cronjob versendet (kommt mit Stufe 1).

Bibliothek `system/lib/qrcode/` (chillerlan/php-qrcode 5.0.3) erzeugt den
QR-Code des Objekts. Er verweist auf `/app/?c=<token>`; der Token liegt in
`objekte.qr_token` und lässt sich vom Inhaber erneuern.

## Nächster Schritt

Büro-Dashboard und Mitarbeiter-PWA setzen auf dieser API auf.
