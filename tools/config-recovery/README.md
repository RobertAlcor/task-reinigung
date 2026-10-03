# Fehlende config.php neu erstellen

Diese Hilfe setzt **keine vorhandene config.php voraus**. Sie repariert eine bestehende Takt-Installation, ohne deren Datenbank zu ändern.

## Anwendung

1. `START-HIER.html` aus dem bereitgestellten ZIP auf dem eigenen Computer mit Chrome oder Edge öffnen.
2. Auf „config-reparieren.php herunterladen“ klicken. Den angezeigten zufälligen Reparaturcode privat aufbewahren; die HTML-Seite offen lassen.
3. Nur die erzeugte PHP-Datei nach `/html/reinigung/public/config-reparieren.php` hochladen, neben `index.php`.
4. Auf der HTML-Seite „Konfiguration auf dem Server erstellen“ öffnen. Reparaturcode und **Datenbank-Zugangsdaten** aus Easyname → Datenbanken eingeben, nicht das FTP-Passwort.
5. Ein unbekannter bisheriger Verschlüsselungsschlüssel darf leer bleiben. Ein neuer Schlüssel ist ausschließlich bei leeren geprüften Verschlüsselungsfeldern und ausdrücklicher Zustimmung möglich. „Prüfen und config.php erstellen“ anklicken.
6. Bei Erfolg die Reparatur-PHP-Datei vom Server löschen und die neue `system/config.php` privat sichern. Eine vorhandene `setup.php` nicht weiter öffentlich bereitstellen. Takt neu aufrufen.

Die erzeugte Reparaturdatei ist sechs Stunden gültig. Sie enthält keinen Datenbankzugang und nur den SHA-256-Hash des lokal erzeugten 256-Bit-Reparaturcodes. Die unpersonalisierte PHP-Vorlage im Repository ist standardmäßig gesperrt und nicht direkt zu verwenden.

## Was geprüft wird

Die serverseitige Datenbankverbindung verwendet `localhost`, eine schreibgeschützte MySQL-Transaktion und SELECT-Abfragen. Geprüft werden die sechs bekannten verschlüsselten Spalten sowie aktive Zwei-Faktor-Konten und ein bestehender aktiver Anbieter. Unbekannte Verschlüsselungs-/Binärfelder, fehlende Tabellen, widersprüchliche Zwei-Faktor-Daten oder mehr als 10.000 verschlüsselte Werte führen zum Abbruch.

Ist ein alter Schlüssel angegeben, müssen alle gefundenen Verschlüsselungsfelder damit authentifiziert entschlüsselt werden können. Ohne Schlüssel und bei belegten Feldern wird **keine Konfiguration erstellt**. Es werden weder Schlüssel auf Verdacht ersetzt noch Zwei-Faktor-Anmeldungen umgangen. Ein neuer Schlüssel kann alte verschlüsselte Inhalte nicht wiederherstellen.

Die fehlende Konfiguration wird vollständig in einer privaten temporären Datei vorbereitet und über einen Hardlink ohne Überschreiben unter `system/config.php` bereitgestellt. Ein Hoster, der Hardlinks verbietet, erfordert eine andere sichere Dateibereitstellung; es gibt keinen unsicheren Fallback. Voraussetzung ist die bestehende Zugriffssperre `system/.htaccess`. Sie ersetzt keine Kontrolle der tatsächlichen Webserverkonfiguration.

**Keine Neuinstallation:** Kein SQL-Import, keine Kontenerstellung, keine Passwortänderung, keine Datenbanklöschung. Frühere Sonderwerte, die ausschließlich in der verlorenen Datei standen, lassen sich nicht rekonstruieren. Mail-Einstellungen in der Datenbank bleiben unverändert; ein verlorener Mail-Fallback muss bei Bedarf erneut eingerichtet werden.

## Bauen und prüfen

```sh
python3 tools/config-recovery/build.py
php tests/config_recovery.php
php tests/unit.php .
python3 tests/http_checks.py .
```

Der Build erstellt `START-HIER.html` und `Takt-Konfiguration-Reparatur.zip` in diesem Verzeichnis. Beide enthalten keine individuell eingegebenen Passwörter. Die persönliche Reparaturdatei, der Reparaturcode und die später auf dem Server erzeugte config.php gehören **nicht** ins Repository.

37 neue Tests prüfen die Reparaturlogik mit einer simulierten Datenbank, echter lokaler Kryptografie und temporären lokalen Dateien. Das sind keine Tests gegen die Easyname-Datenbank. Die tatsächliche Hosting-Verbindung und Dateierstellung werden erst beim vom Nutzer ausgelösten Reparaturlauf geprüft. Keine Sicherheits- oder Verkaufsfreigabe.

Technische Referenzen: PHP-Handbuch zu PDO, openssl_decrypt und fopen; MySQL-Handbuch zu READ ONLY transactions.
