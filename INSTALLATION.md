# Installation bei easyname — Schritt für Schritt

## 1. Datenbank
1. easyname-Kundencenter → Hosting → Datenbanken → `u230952db1` → phpMyAdmin aufrufen.
2. Links die Datenbank `u230952db1` anklicken.
3. Nur bei Neustart (alte Tabellen vorhanden): Reiter **Importieren** → Datei `datenbank/00-alles-loeschen.sql` → OK.
4. Reiter **Importieren** → Datei `datenbank/01-datenbank-komplett.sql` → OK.
   Ergebnis: 59 Tabellen und 4 Views. Keine Fehlermeldung.

## 2. Dateien hochladen
1. FileZilla: Ordner `html/reinigung/public/` auf dem Server öffnen. Falls dort noch etwas liegt, alles löschen.
2. Server → „Versteckte Dateien anzeigen" einschalten (sonst fehlt die `.htaccess`).
3. Den **Inhalt** des Ordners `public/` aus diesem Paket hineinziehen — also `admin`, `anbieter`, `api`, `app`, `assets`, `besichtigung`, `cron`, `portal`, `system`, `uploads`, `vertrieb`, `.htaccess`, `index.php`, `setup.php`.
   Es darf **kein** Ordner `public/public` entstehen.
4. easyname → Subdomain `reinigung.webdesign-alcor.at` → Webspace-Pfad: `/reinigung/public/` (ist bereits so).

## 3. Einrichten
1. `https://reinigung.webdesign-alcor.at/setup.php` aufrufen.
2. Datenbank: Name `u230952db1`, Benutzer `u230952db1`, Passwort von easyname, Server `localhost`.
3. Anbieter-Zugang: dein Name, Benutzername (z. B. `ALCOR`), E-Mail, Passwort mit mindestens 10 Zeichen.
4. „Einrichten". Den angezeigten **Verschlüsselungsschlüssel sichern** (Passwortmanager). Er steht danach nur noch in `system/config.php`.
5. `setup.php` per FileZilla **löschen**.

## 4. Mailversand
`system/config.php` herunterladen, Abschnitt `'mail'` ausfüllen:

    'mail' => [
        'host'       => 'smtp.easyname.com',
        'port'       => 587,
        'secure'     => 'tls',
        'user'       => '230952mail4',            // Postfach-Kennung, nicht die Adresse
        'password'   => 'POSTFACH-PASSWORT',
        'from_email' => 'office@webdesign-alcor.at',
        'from_name'  => 'Takt',
    ],

Hochladen. Test: `https://reinigung.webdesign-alcor.at/admin/?vergessen` mit einer Adresse — es kommt eine Mail.

## 5. Anbieter-Dashboard `/anbieter/`
1. Anmelden mit deinem Anbieter-Benutzer.
2. **Einstellungen**: Firma, Inhaber, Adresse, UID, IBAN, Produktname, Gewerbe, Behörde, Kammer. Speichern.
3. **Konto**: Zwei-Faktor einrichten (Authenticator-App).
4. **Einstellungen → Cronjob**: die angezeigte URL kopieren. easyname → Hosting → Cronjobs → neu, alle 15 Minuten, Befehl:
   `wget -q -O /dev/null "https://reinigung.webdesign-alcor.at/cron/?token=…"`
5. **Demo-Betrieb anlegen** (Knopf oben rechts): legt „Alcor Facility Management" mit Beispieldaten und allen Zugängen an — zum Ansehen von Büro, Besichtigung, App, Kundenportal und Website. Die Zugänge stehen danach am Bildschirm.
6. **Betrieb anlegen**: echte Betriebe kommen über diesen Knopf oder registrieren sich selbst über die Vertriebsseite.

## 6. Betrieb `/admin/`
1. Mit dem Inhaber-Login des Betriebs anmelden, AV-Vertrag und AGB bestätigen.
2. Mein Konto: Passwort ändern, Zwei-Faktor einrichten.
3. Einstellungen: Betriebsdaten, Logo; Rechnungen → Layout: Bank, USt.
4. Mitarbeiter, Kunden, Objekte anlegen — oder Besichtigung starten.

## Fehlersuche
- **403** beim Aufruf: Webspace-Pfad der Subdomain falsch oder `index.php` fehlt im Stammordner.
- **„Konfiguration fehlt"**: `setup.php` noch nicht ausgeführt.
- **„Es ist ein Fehler aufgetreten"**: in `system/config.php` `'debug' => true` setzen, Seite neu laden — der echte Fehler erscheint. Danach wieder `false`.
- **„Es ist bereits ein Anbieter-Zugang angelegt"**: Datenbank ist nicht leer. `00-alles-loeschen.sql` importieren, dann `01-datenbank-komplett.sql`, dann Setup erneut.

## Wichtig
Zugangsdaten, Schlüssel und Passwörter niemals in Chats, E-Mails oder Screenshots weitergeben.
