# FTP-Paket ohne manuelles Zuordnen einzelner Dateien

Das erzeugte ZIP enthält ausschließlich `public/index.php` und
`public/config-reparieren.php`. Es passt direkt zum Projektordner
`/html/reinigung/`. ZIP lokal entpacken und den **gesamten public-Ordner**
in den Projektordner übertragen, mit vorhandenen Ordnern zusammenführen.
Nicht in den schon geöffneten public-Ordner hochladen. Keine Ordner löschen.

Ohne config.php leitet die normale Startseite auf der vorgesehenen Domain
zum Reparaturformular weiter. Die vorhandenen Datenbank-Zugangsdaten und
der private Reparaturcode müssen einmal eingegeben werden. Keine FTP-Daten
verwenden. Mit vorhandener config.php läuft die Startseite unverändert weiter.
Die Datenbank wird beim Reparaturlauf nur gelesen. Ein verlorener Schlüssel
wird nicht rekonstruiert; bei verschlüsselten Daten ohne passenden Schlüssel
oder fehlenden Anbieter-Konten stoppt die Reparatur unverändert.

Bauen: `python3 tools/config-recovery/build_ftp_upload.py --output /privater/pfad`
Die Ausgabe muss außerhalb des Repositorys liegen. `Takt-Zugang-PRIVAT.txt`
und dessen Inhalt nicht hochladen, nicht committen. Nur das ZIP ist für den
Server bestimmt; es enthält nur den Hash des Freigabecodes, keine Datenbank-
Zugangsdaten. Die persönliche PHP-Datei nie committen. Gültigkeit: 23 Stunden.

Die Anleitung im Chat nennt Uploadziel und Reparaturcode; keine HTML-Datei
und kein weiterer Download sind erforderlich. Erst das entpackte Verzeichnis
hochladen, nicht erwarten, dass FTP selbst ZIP-Dateien entpackt.

Nach Erfolg config.php privat sichern und config-reparieren.php entfernen.
Die Weiterleitung ist dann automatisch inaktiv. Keine Datenbankmigration,
kein neues Anbieter-Konto und kein öffentlicher Setup-Lauf. Die Reparaturdatei
ist nicht im GitHub-Quellcode aktiviert, sondern wird nur für das private ZIP
befristet erzeugt. Testupdate-1-Manifest bleibt historisch unverändert.

Das Paket wurde lokal geprüft. Ein korrekter Upload und die Datenbankprüfung
auf Easyname sind zusätzliche Voraussetzungen, keine garantierten Ergebnisse.
