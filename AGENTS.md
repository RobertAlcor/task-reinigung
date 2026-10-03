# Projektablauf fuer Takt

Vorgabe des Projektinhabers vom 03.10.2026: Bei beauftragten Codeaenderungen soll GitHub mit aktualisiert werden. Nicht nur lokale Dateien oder ein ZIP liefern und das Repository unveraendert lassen.

- Vor jeder Aenderung den aktuellen Remote-Stand lesen. Fremde oder zwischenzeitliche Aenderungen erhalten; kein Force-Push.
- Aenderungen auf einem Arbeitszweig pruefen. Fuer abgeschlossene, gepruefte Auftraege den bereitgestellten Code mit dem vereinbarten GitHub-Zweig synchronisieren. Den tatsaechlichen Zweig und Commit in der Antwort nennen; bei fehlgeschlagenem Push dies offen sagen.
- Ein passendes Upload-Paket darf zusaetzlich bereitgestellt werden. GitHub und Paket muessen fuer die enthaltenen Dateien denselben Stand haben. Upload-Paket, gesamter Quellcode und Neuinstallation sind verschiedene Dinge.
- Tests ausfuehren: php tests/unit.php . und python3 tests/http_checks.py . sowie PHP-Syntaxpruefung. Das Python-Skript darf nicht http.py heissen, weil es sonst das Standardmodul verdeckt.
- deployment/testupdate-1.json dokumentiert nur Testupdate 1. Bei spaeteren Paketen Dateiliste, Referenzstand, Versionsbezeichnung und Hashes bewusst aktualisieren oder ein neues Manifest anlegen.
- Keine Zugangsdaten, private Schluessel, echte Kundendaten, Backups oder system/config.php committen oder in Upload-Pakete aufnehmen. Bestehenden Verschluesselungsschluessel niemals ersetzen.
- GitHub-Aktualisierung ist KEINE Erlaubnis fuer ungepruefte Live-Veroeffentlichung, Datenbankmigration oder Loeschung von Serverdateien. Easyname bleibt separat, bis ein kontrollierter Deploymentweg eingerichtet und freigegeben ist.
- Ein bestandener automatisierter Test ist keine Verkaufs- oder Sicherheitsfreigabe. Offene Befunde und nicht getestete Ablaufe nennen; keine erfundenen Testergebnisse.
- Kurze deutsche Abschlussmeldung: umgesetzt, GitHub-Commit, Testergebnis, Download, tatsaechlicher Serverstatus.
