# Dieses Paket ist vollständig — inklusive Zugangsdaten

Es enthält alles, was auf dem Server liegt oder dort hingehört. Gedacht als
vollständige Sicherung und als Grundlage für eine Neuinstallation.

## Was darin sensibel ist

| Datei | Inhalt | Risiko bei Veröffentlichung |
|---|---|---|
| `public/system/config.php` | Datenbankpasswort, Mailpasswort, **Verschlüsselungsschlüssel** | Vollzugriff auf alle Daten; SVNR und IBAN aller Mitarbeiter entschlüsselbar |
| `public/takt-zugang-x7q2m.php` | Einmal-Zugang ohne Passwort | Wer die Datei auf dem Server aufruft, ist sofort Superuser |
| `public/setup.php` | Einrichtung | Kann eine bestehende Installation überschreiben, wenn config.php fehlt |

Der Verschlüsselungsschlüssel ist der kritischste Wert. Mit ihm lassen sich
Sozialversicherungsnummern, IBANs und Alarmcodes entschlüsseln — Daten, für
die du als Auftragsverarbeiter nach Art. 28 DSGVO haftest.

## Die mitgelieferte .gitignore

Sie schließt diese drei Dateien automatisch aus, sobald du `git add .`
ausführst. Du musst nichts weiter tun — die Dateien bleiben lokal auf deinem
Rechner und auf dem Server, landen aber nicht im Repository.

Willst du sie bewusst doch hochladen, müsstest du sie aus der `.gitignore`
entfernen oder mit `git add -f public/system/config.php` erzwingen.

## Empfehlung

- Repository auf **privat** setzen (GitHub → Settings → General → Change visibility)
- `config.php` auch im privaten Repository weglassen; stattdessen
  `config.beispiel.php` pflegen und die echte Datei getrennt sichern
  (Passwortmanager, verschlüsselter USB-Stick, Hosting-Backup)
- `takt-zugang-x7q2m.php` nach Gebrauch vom Server löschen

## Falls Zugangsdaten schon einmal öffentlich waren

1. Datenbankpasswort bei easyname ändern, danach in `config.php` nachtragen
2. Postfachpasswort ändern, danach im Anbieter-Dashboard unter
   Einstellungen → Mailversand nachtragen
3. Verschlüsselungsschlüssel **nicht einfach ändern** — sonst sind bestehende
   SVNR, IBAN und Alarmcodes unlesbar. Wechsel nur mit Migration: alte Werte
   entschlüsseln, Schlüssel tauschen, neu verschlüsseln.

---

Robert Alchimowicz · Alcor Group, Wien
