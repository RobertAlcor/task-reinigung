# Takt: kontrollierte GitHub-Uebertragung zu Easyname

Stand: 03.10.2026. Nur **Testupdate 1** fuer eine bestehende Testinstallation.
Dieser Ablauf ist keine Sicherheits- oder Verkaufsfreigabe. Kein Serverupload bei Push.

## Einmalig einrichten

1. Ein bereits in einem Chat geteiltes FTP-Passwort im Easyname-Controlpanel ersetzen.
2. GitHub-Repository → Settings → Secrets and variables → Actions → New repository secret.
3. Die drei Secrets anlegen: `EASYNAME_FTP_HOST`, `EASYNAME_FTP_USER`, `EASYNAME_FTP_PASSWORD`.
   Host und Benutzer aus dem Easyname-FTP-Zugang uebernehmen, das **neue** Passwort einsetzen.
   Keine Protokollangabe, keinen Pfad und keinen Port an den Host anfuegen.
4. Zugang auf den vorgesehenen Testbereich beschraenken. Eine Testkopie benoetigt eine eigene
   Testdatenbank, fiktive Daten und darf keine echten Mails an Kunden versenden.

Die Uebertragung verwendet ausschliesslich explizites FTPS auf Port 21, mit geprueftem
TLS-Zertifikat, mindestens TLS 1.2 und verschluesseltem Datenkanal. Kein unverschluesselter
Fallback; FTP und SFTP sind verschiedene Zugaenge. Ob der konkrete Zugang funktioniert,
zeigt erst der folgende Verbindungstest. Zugangsdaten werden weder ins Repository geschrieben
noch als Workflow-Eingabefelder abgefragt.

## Erster Lauf: nur pruefen

Actions → **Takt - Tests und Easyname** → Run workflow → Branch `main`.
`aktion = pruefen`, `zielordner = auto`; Bestaetigung leer lassen.

Nach den Codepruefungen werden die Takt-Kennungsdateien und sechs Update-Dateien gelesen.
Kein Download der Konfiguration, keine Dateilisten, kein Schreiben auf den Server.
Der FTP-Ordner wird anhand von `index.php` und `system/Crypto.php` bestimmt: `/`, `/public`
oder `/Public`. Bei mehreren Treffern oder abweichendem Quellcode wird abgebrochen.

Der im Controlpanel angezeigte physische Zugangspfad kann innerhalb des beschraenkten
FTP-Kontos als `/` erscheinen. Deshalb `/html/reinigung/` nicht ungeprueft als Upload-Ziel
verwenden. Der erkannte FTP-Zielordner steht im Workflow-Ergebnis.

## Erst nach erfolgreicher Pruefung: Testupdate uebertragen

- Eigene vollstaendige Datei-/Datenbanksicherung ausserhalb des oeffentlichen Webordners
  erstellen. Benutzer abmelden; Testfenster ohne parallele Bearbeitung verwenden.
- Workflow erneut starten: `aktion = hochladen`, `zielordner = <genau der gepruefte Ordner>`,
  `bestaetigung = TESTUPDATE-1`; Checkbox fuer fiktive Testdaten und vorhandenes Backup setzen.
- Ausschliesslich die sechs Dateien aus `deployment/testupdate-1.json` werden uebertragen.
  Jeder Serverstand muss dem dokumentierten Ursprungsstand oder Testupdate 1 entsprechen.
  Unbekannte Aenderungen werden nicht ueberschrieben. Bereits identische Dateien bleiben bestehen.
- Jede neue Datei wird unter zufaelligem temporaerem PHP-Namen im gleichen Ordner vorbereitet,
  per SHA-256 rueckgeprueft und dann umbenannt. Die sechs Umbenennungen sind **kein atomarer
  Gesamt-Release**. Bei einem abgefangenen Fehler wird eine Ruecksicherung der angefassten
  Originaldateien versucht. Verbindungsabbruch, Runner-Ausfall oder Abbruch des Jobs koennen diese
  verhindern. Das eigene Backup bleibt deshalb zwingend erforderlich.
- Keine Ordner loeschen; nur selbst angelegte temporaere Dateien werden bereinigt. `config.php`,
  Uploads und Verschluesselungsschluessel bleiben unberuehrt; keine SQL-Migration, kein `setup.php`.
- Danach neu anmelden und Anbieterbereich, Buero, Portal und App manuell abnehmen. Bei Problemen
  die sechs Originaldateien gemeinsam aus der eigenen Sicherung wiederherstellen.

## Grenzen und weitere Updates

Die Tests pruefen lokale Regressionen und FTPS-Logik mit einem simulierten Server, nicht das
Hosting oder Geschaeftsablaeufe mit echten Daten. Ein gruener Testjob ist kein erfolgreicher Upload.
Der Modus `pruefen` aendert nichts, auch wenn der gesamte Workflow gruen ist.

Der Upload ist absichtlich auf Testupdate 1 begrenzt. Spaetere Laufzeitaenderungen verlangen
bewusst aktualisierte Release-Manifeste und entsprechende Anpassung dieses Deployments.
Nicht alle kuenftigen Commits automatisch mit dem alten Manifest hochladen.

Referenzen: GitHub Docs (Manual workflow runs; Using secrets), Python 3.12 ftplib.FTP_TLS.
