# GitHub und Easyname: Stand und Veroeffentlichung

Stand: 03.10.2026. Testupdate 1, KEINE Verkaufsfreigabe.

## Was jetzt im Repository liegt

Die sechs Laufzeitkorrekturen des bereits bereitgestellten Testupdates wurden unveraendert uebernommen. Ihre SHA-256-Werte stehen in deployment/testupdate-1.json. Ebenfalls enthalten: 55 isolierte PHP-Regressionstests, 56 lokale HTTP-Pruefungen mit Datenbank-Testdouble und der nur lesende Server-Preflight.

Der Importprueflauf 37132692566 bestand Syntax-, Unit- und HTTP-Pruefung. Der erste Importversuch scheiterte am Dateinamen tests/http.py, der das Python-Standardmodul http verdeckte. Das Skript heisst jetzt tests/http_checks.py. Die sechs Anwendungsdateien wurden dadurch nicht veraendert.

Die Uebernahme aktualisiert den Quellcode, NICHT den Easyname-Webspace. Weder Datenbank noch Konfiguration oder Verschluesselungsschluessel wurden geaendert. Ein allgemeiner automatischer Paket- oder Easyname-Deployment-Workflow ist noch nicht eingerichtet.

## Code herunterladen

Auf GitHub den Zweig main auswaehlen, dann Code > Download ZIP. Das ist der gesamte Quellcode und kein fertiger Server-Updateauftrag. Nicht das gesamte Repository in den oeffentlichen Webordner hochladen.

Fuer Testupdate 1 werden in einer bestehenden, passenden Testinstallation genau diese Dateien ersetzt:

| Quelle im Repository | Ziel relativ zum vorhandenen Webordner |
|---|---|
| public/api/index.php | api/index.php |
| public/system/Auth.php | system/Auth.php |
| public/system/Database.php | system/Database.php |
| public/system/Http.php | system/Http.php |
| public/system/Push.php | system/Push.php |
| public/system/bootstrap.php | system/bootstrap.php |

Das separate Easyname-Upload-ZIP enthaelt diese sechs Dateien bereits ohne den umschliessenden public-Ordner. Ordner zusammenfuehren, nur die sechs Dateien ersetzen. Niemals system/ komplett loeschen, keinen public/public-Ordner erzeugen.

Vorher Dateien und Datenbank sichern. Testinstallation mit fiktiven Daten und eigener Testdatenbank verwenden. system/config.php, Uploads und bestehende Daten bleiben erhalten. Fuer dieses Update kein SQL importieren und setup.php nicht starten. Bei Fehlern alle sechs Originaldateien gemeinsam zurueckspielen. Neuinstallation separat nach INSTALLATION.md behandeln.

## Direkt aus GitHub zu Easyname: noch einzurichten

Takt benoetigt PHP und MySQL. GitHub Pages fuehrt PHP nicht aus. Der passende Weg waere ein GitHub-Actions-Workflow, der nach bestandenen Tests und ausdruecklicher Freigabe gezielt Dateien ueber SFTP/SSH auf den Easyname-Webspace uebertraegt.

Dafuer werden einmalig SSH/SFTP-Host, tatsaechlicher Port, Benutzer, gepruefter Zielpfad und die serverseitig bestaetigte Hostschluessel-Identitaet benoetigt. Passwort oder privater SSH-Schluessel gehoeren in GitHub Actions Secrets, nicht in den Chat oder das Repository. Der SSH-Zugang unterscheidet sich bei Easyname zwischen CloudPit und Controlpanel; nicht pauschal Port 22 annehmen.

Zuerst manueller Ausloeser und getrennte Testumgebung; Produktionsupload erst nach dokumentierter Abnahme. Keine implizite Ordnerloeschung, keine automatische Migration, keine Ueberschreibung von Konfiguration und Benutzerdaten. Zu jedem Deployment gehoeren Sicherung, Rollback und anschliessender Funktionstest.

## Offene Freigabepunkte

Passwort-Reset bei parallelen Anfragen und bestehende Sitzungen; Offline-Warteschlange beim Benutzerwechsel; weitere Direktzugangslinks; vollstaendige Tests mit zwei getrennten Betrieben und allen Rollen; Angebot bis Rechnung/Storno; E-Mail, Push, Cron, Backup-Wiederherstellung; tatsaechlicher Webserver-Dateischutz und vollstaendige Anbieterangaben. Ein gruenes Unit-Ergebnis ersetzt diese Abnahme nicht.

## Primaerquellen

- GitHub Pages und PHP: https://docs.github.com/de/pages/getting-started-with-github-pages/creating-a-github-pages-site
- Quellcode herunterladen: https://docs.github.com/en/repositories/working-with-files/using-files/downloading-source-code-archives
- Easyname SSH (CloudPit/Controlpanel): https://www.easyname.at/de/support/webhosting/webspace/wie-verbinde-ich-mich-ueber-ssh-mit-meinem-webspace
- GitHub Actions Secrets: https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/use-secrets
