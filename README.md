# Takt — Software für Reinigungsbetriebe

Webbasierte Verwaltungssoftware für Reinigungsbetriebe, entwickelt für
österreichisches Recht. Mandantenfähig: ein Anbieter betreut mehrere Betriebe.

Handprogrammiert in PHP, ohne Framework, ohne CMS. Läuft auf einfachem
Shared Hosting mit PHP 8 und MySQL 5.7.

## Module

| Bereich | Pfad | Für wen |
|---|---|---|
| Anbieter-Dashboard | `/anbieter/` | Betreiber der Software — Betriebe, Verträge, Rechnungen |
| Büro | `/admin/` | Reinigungsbetrieb — Kunden, Objekte, Mitarbeiter, Dienstplan, Rechnungen |
| Mitarbeiter-App | `/app/` | Reinigungskräfte — PWA, 7 Sprachen, QR-Stempeln, offline |
| Kundenportal | `/portal/` | Kunden des Betriebs — Nachweise, Fotos, Rechnungen |
| Besichtigung | `/besichtigung/` | Außendienst am Tablet — Aufmaß, Kalkulation, Angebot |
| Vertriebsseite | `/` | Öffentlich — Produktseite mit Selbstregistrierung |
| Website-Modul | `/index.php?betrieb=…` | Eigene Website des Betriebs unter dessen Domain |
| API | `/api/` | JSON-Schnittstelle für die App |
| Cronjob | `/cron/` | Plan fortschreiben, Mails, Backup, Löschfristen |

## Funktionsumfang

- **Dienstplan** acht Wochen voraus, Ersatzvorschlag nach Qualifikation,
  Verfügbarkeit und Wochenauslastung, Einsatzanfragen per Push
- **Zeiterfassung** per QR-Code oder NFC am Objekt, ohne Ortung, offlinefähig,
  Monatsblatt als PDF zur Unterschrift, Export fürs Lohnbüro
- **Fotonachweis** vorher/nachher getrennt, EXIF-bereinigt, automatische Löschung
- **Rechnungen** mit Abrechnungslauf, Festschreibung, Storno per Gutschrift,
  Mahnwesen mit Verzugszinsen (§ 456 UGB) und Betreibungskosten (§ 458 UGB)
- **Besichtigung** in sechs Schritten mit Live-Kalkulation und Angebots-PDF
- **Berichte** bis zum Deckungsbeitrag je Objekt, alles als CSV
- **Kundenportal** mit Leistungsnachweis, Beschwerden und Feedback
- **Website-Modul** je Betrieb unter eigener Domain, gepflegt aus dem Büro
- **Zwei-Faktor-Anmeldung**, QR-Login, Rollen, Audit-Log
- **Datenexport** als ZIP (Art. 20 DSGVO), Löschung nach Vertragsende

## Rechtliche Grundlagen

§ 26 AZG · § 10 AVRAG · § 96 ArbVG · Art. 9, 20, 28 DSGVO · § 11 UStG ·
§ 132 BAO · § 456, § 458 UGB · § 5 ECG · § 14 UGB · § 25 MedienG

AGB und Auftragsverarbeitungsvertrag liegen versioniert in
`public/system/texte/` und werden beim ersten Login bestätigt.

## Technik

- PHP 8.1+, MySQL 5.7+ / MariaDB
- Kein Framework, kein Composer zur Laufzeit — Bibliotheken liegen in
  `public/system/lib/`: dompdf 3.1, PHPMailer 6.9, chillerlan/php-qrcode 5.0
- Frontend ohne Build-Schritt: handgeschriebenes CSS, Vanilla JavaScript
- Mitarbeiter-App als PWA mit Service Worker und Offline-Warteschlange
- Web Push mit VAPID, ohne Fremdbibliothek (OpenSSL)
- Verschlüsselung sensibler Felder (SVNR, IBAN, Alarmcodes) mit AES-256-GCM

## Installation

1. Inhalt von `public/` auf den Webspace hochladen, Domain auf diesen
   Ordner zeigen lassen
2. Datenbank anlegen, `datenbank/01-datenbank-komplett.sql` importieren
3. `setup.php` im Browser aufrufen, Formular ausfüllen
4. **`setup.php` danach löschen**
5. Cronjob alle 15 Minuten auf die URL aus dem Anbieter-Dashboard

Ausführlich in [INSTALLATION.md](INSTALLATION.md).

### Bestehende Installation aktualisieren

Dateien überschreiben, dann `datenbank/AKTUALISIEREN.sql` importieren.
Meldungen „Duplicate column" sind harmlos.

## Konfiguration

`public/system/config.php` enthält Zugangsdaten und den
Verschlüsselungsschlüssel und ist **bewusst nicht im Repository**.
Vorlage: `public/system/config.beispiel.php`.

> Wird der Verschlüsselungsschlüssel geändert, sind bereits gespeicherte
> SVNR, IBAN und Alarmcodes unlesbar.

## Dokumentation

- [LIESMICH.md](LIESMICH.md) — technische Dokumentation aller Module
- [INSTALLATION.md](INSTALLATION.md) — Einrichtung Schritt für Schritt
- [TESTPLAN.pdf](TESTPLAN.pdf) — 161 Prüfschritte, [Formular zum Ausfüllen](TESTPLAN-Formular.pdf)
- [Takt-Info.pdf](Takt-Info.pdf) — Produktübersicht für Interessenten

## Lizenz

Alle Rechte vorbehalten. Robert Alchimowicz, Alcor Group, Wien.

---

handprogrammiert von [Webdesign ALCOR](https://webdesign-alcor.at)
