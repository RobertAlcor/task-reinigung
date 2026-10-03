from pathlib import Path
import json
root=Path(__file__).resolve().parents[2]
template=(root/'tools/config-recovery/recovery.template.php').read_text(encoding='utf-8')
html='''<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; connect-src 'none'; base-uri 'none'; form-action 'none'">
<meta name="referrer" content="no-referrer"><title>Takt – fehlende Konfiguration erstellen</title>
<style>body{font:17px/1.6 system-ui;max-width:820px;margin:40px auto;padding:0 24px;color:#18263c;background:#f7f9fc}main{background:white;border:1px solid #d4dce8;border-radius:12px;padding:30px}h1{font-size:30px;line-height:1.2}h2{font-size:21px;margin-top:30px}button,a.button{display:inline-block;font:inherit;font-weight:600;border:0;border-radius:6px;padding:14px 20px;background:#155174;color:white;cursor:pointer;text-decoration:none}button:disabled{opacity:.6}input{box-sizing:border-box;width:100%;padding:12px;font:15px ui-monospace,monospace;border:1px solid #65788e;margin:12px 0}aside{background:#edf4fa;padding:18px;border-left:4px solid #155174}code{overflow-wrap:anywhere}#error{color:#9e210e}small{font-size:14px}li{margin-bottom:12px}a{color:#155174}:focus-visible{outline:3px solid #c96300;outline-offset:3px}</style></head>
<body><main><p>TAKT · REPARATURHILFE</p><h1>Die fehlende config.php neu erstellen</h1>
<p>Diese Datei auf deinem Computer öffnen. Hier werden keine Datenbankpasswörter abgefragt und keine Daten ins Internet gesendet. Du erstellst eine befristete, geschützte Reparaturdatei für deinen Server.</p>
<aside><strong>Die bestehende Datenbank bleibt erhalten.</strong><br>Der Assistent verbindet sich später auf deinem Server lesend mit ihr. Er erstellt die fehlende Konfiguration nur dann, wenn der Schlüssel zu den vorhandenen Daten passt oder keine belegten Verschlüsselungsfelder gefunden wurden. Ein unbekannter alter Schlüssel wird nicht ersetzt, wenn verschlüsselte Daten vorhanden sind.</aside>
<h2>1. Reparaturdatei erzeugen</h2><button id="make" type="button">config-reparieren.php herunterladen</button><p id="error" role="alert"></p>
<section id="next" hidden><h2>2. Diesen Reparaturcode aufbewahren</h2><label for="code">Für die Freigabe auf deinem Server:</label><input id="code" readonly autocomplete="off" spellcheck="false"><small>Nur du erhältst diesen Code. Die PHP-Datei enthält ausschließlich seinen Hash. Nicht in GitHub speichern, nicht in Screenshots oder im Chat weitergeben. Diese Seite offen lassen. Die Reparaturdatei gilt sechs Stunden.</small>
<h2>3. Nur die heruntergeladene PHP-Datei hochladen</h2><p>Mit FTP die Datei <code>config-reparieren.php</code> neben <code>index.php</code> ablegen:</p><p><code>/html/reinigung/public/config-reparieren.php</code></p><p>Nichts löschen, keine SQL-Datei importieren und kein setup.php starten. Diese HTML-Datei nicht auf den Server hochladen.</p>
<h2>4. Reparatur auf deinem Server öffnen</h2><p><a class="button" href="https://reinigung.webdesign-alcor.at/config-reparieren.php" target="_blank" rel="noopener noreferrer">Konfiguration auf dem Server erstellen</a></p>
<p>Dort den Reparaturcode und deine <strong>Datenbank-Zugangsdaten</strong> aus Easyname → Datenbanken eingeben. Das sind nicht deine FTP-Zugangsdaten. Die vorausgefüllten Datenbanknamen prüfen.</p>
<p>Ist der frühere Verschlüsselungsschlüssel unbekannt, das optionale Schlüsselfeld leer lassen. Die Checkbox erlaubt einen neuen Schlüssel ausschließlich für vollständig leere geprüfte Verschlüsselungsfelder. Anschließend „Prüfen und config.php erstellen“ anklicken.</p>
<h2>5. Nach Erfolg</h2><p><code>config-reparieren.php</code> wieder vom Server löschen, die neue <code>system/config.php</code> privat sichern und Takt neu aufrufen. Die Reparatur ersetzt keine Konten und setzt keine Passwörter oder Zwei-Faktor-Anmeldungen zurück. Ein vorhandenes <code>setup.php</code> nicht weiter öffentlich bereitstellen.</p>
<p>Bei „verschlüsselte Daten vorhanden“ wird nichts neu geschrieben. Dann ist eine gesonderte Entscheidung zu den betroffenen Daten erforderlich; keine Tabellen löschen und keinen Schlüssel auf Verdacht einsetzen.</p></section></main>
<script id="template" type="application/json">__TEMPLATE__</script>
<script>
'use strict';
const make = document.getElementById('make');
make.addEventListener('click', async () => {
  make.disabled = true;
  document.getElementById('error').textContent = '';
  try {
    if (!globalThis.crypto?.subtle) throw new Error('Bitte die lokal gespeicherte HTML-Datei mit einem aktuellen Chrome oder Edge öffnen.');
    const bytes = crypto.getRandomValues(new Uint8Array(32));
    const code = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(code)));
    const hash = Array.from(digest, b => b.toString(16).padStart(2, '0')).join('');
    const expires = String(Math.floor(Date.now() / 1000) + 6 * 3600);
    const template = JSON.parse(document.getElementById('template').textContent);
    if (template.split('__ACCESS_HASH__').length !== 2 || template.split('__EXPIRES__').length !== 2) throw new Error('Vorlage ist beschädigt.');
    const source = template.replace('__ACCESS_HASH__', hash).replace('__EXPIRES__', expires);
    const url = URL.createObjectURL(new Blob([source], {type:'application/octet-stream'}));
    const a = document.createElement('a'); a.href = url; a.download = 'config-reparieren.php'; document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
    document.getElementById('code').value = code;
    document.getElementById('next').hidden = false;
    make.textContent = 'Neue Reparaturdatei und neuen Code erzeugen';
  } catch (e) {
    document.getElementById('error').textContent = e.message || 'Datei konnte nicht erzeugt werden.';
  } finally { make.disabled = false; }
});
</script></body></html>
'''
# Prevent the embedded PHP/HTML from terminating the script element.
html=html.replace('__TEMPLATE__',json.dumps(template,ensure_ascii=False).replace('<','\\u003c'))
(root/'tools/config-recovery/START-HIER.html').write_text(html,encoding='utf-8')

import zipfile
out=root/'tools/config-recovery/Takt-Konfiguration-Reparatur.zip'
with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
    z.write(root/'tools/config-recovery/START-HIER.html','START-HIER.html')
    z.write(root/'tools/config-recovery/README.md','ANLEITUNG.md')
print(out)
