/* Besichtigungs-Wizard — für Tablet und Handy, Büro-Zugang.
   Sechs Schritte, Live-Kalkulation, Zwischenstand am Gerät, Angebot am Ende. */
'use strict';

const app = document.getElementById('app');
const CSRF = document.body.dataset.csrf;
const e = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const SCHRITTE = ['Kunde', 'Objekt', 'Räume', 'Zustand & Rahmen', 'Fotos', 'Kalkulation'];
let basis = null;                       // Kunden, Sätze, offene Besichtigungen
let b = leer();                         // aktuelle Besichtigung
let schritt = 0;
let fotos = [];
let gespeichert = null;                 // Zeitpunkt letzter Serverspeicherung

function leer() {
  return { id: 0, kunde_id: '', ansprechpartner: '', ansprechpartner_telefon: '', objektart: 'Büro', nutzung: '', gesamtflaeche: '', anzahl_raeume: 0, stockwerke: 1, aufzug: 0,
    wc: 0, urinale: 0, duschen: 0, kueche: 0, empfang: 0, besprechungsraeume: 0, serverraum: 0, lager: 0, fenster_anzahl: 0, fenster_erreichbar: '', bodenbelaege: '', glas_innen: 0, glas_aussen: 0,
    verschmutzung_boeden: 3, verschmutzung_sanitaer: 3, verschmutzung_kueche: 3, gesamteindruck: 3, red_flags: '', intervall: '3x', reinigungstage: 'Mo Mi Fr', uhrzeit_von: '18:00', uhrzeit_bis: '20:00',
    zutritt: '', schluessel_abholung: '', erreichbarkeit_oeffi: '', parkmoeglichkeit: '', material_lagerraum: 0, material_stellt_kunde: 0, strom_wasser_vorhanden: 1,
    stundensatz: '', rabatt_prozent: 0, material_pauschale: 0, hygiene_info: '', sicherheit_info: '', erwartung_info: '', naechster_schritt: '', interne_notizen: '' };
}

// ---------------------------------------------------------------------
// Server
// ---------------------------------------------------------------------
async function json(url, opt = {}) {
  const r = await fetch(url, { ...opt, headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF, ...(opt.headers || {}) } });
  const d = await r.json().catch(() => null);
  if (r.status === 401) { location.href = '/admin/?weiter=besichtigung'; throw new Error('Bitte anmelden.'); }
  if (!r.ok || !d || d.ok === false) throw new Error((d && d.fehler) || 'Fehler ' + r.status);
  return d.data;
}
function form(obj) { const fd = new FormData(); Object.entries(obj).forEach(([k, v]) => fd.append(k, v)); fd.append('csrf', CSRF); return fd; }

async function speichern(still = false) {
  lokalSichern();
  try {
    const d = await json('/besichtigung/', { method: 'POST', body: form({ aktion: 'speichern', id: b.id, daten: JSON.stringify(b) }) });
    b.id = d.id; gespeichert = new Date(); lokalSichern();
    if (!still) hinweis('Gespeichert.', 'ok');
    return true;
  } catch (err) { if (!still) hinweis(navigator.onLine ? err.message : 'Offline — Zwischenstand bleibt am Gerät.', 'fehler'); return false; }
}
function lokalSichern() { localStorage.setItem('besichtigung.entwurf', JSON.stringify({ b, schritt, zeit: Date.now() })); }
function lokalLaden() { try { return JSON.parse(localStorage.getItem('besichtigung.entwurf') || 'null'); } catch (_) { return null; } }
function lokalLoeschen() { localStorage.removeItem('besichtigung.entwurf'); }

// ---------------------------------------------------------------------
// Kalkulation (identisch mit Offer::kalkulieren am Server)
// ---------------------------------------------------------------------
function kalk() {
  const K = basis.kalk, n = (x) => Number(x) || 0;
  let min = (n(b.gesamtflaeche) / K.qm_pro_stunde) * 60;
  min += n(b.wc) * K.wc + n(b.urinale) * K.urinal + n(b.duschen) * K.dusche;
  if (n(b.kueche) > 0) min += K.kueche * Math.max(1, n(b.kueche));
  if (n(b.empfang)) min += K.empfang;
  if (n(b.besprechungsraeume) > 0) min += K.besprechung * n(b.besprechungsraeume);
  const ge = n(b.gesamteindruck) || 3; const faktor = ge <= 2 ? 1.3 : ge >= 4 ? 0.9 : 1.0;
  min *= faktor;
  const stunden = Math.round(min / 6) / 10;
  const einsaetze = basis.einsaetze[b.intervall] || 4.33;
  const stundenMonat = Math.round(stunden * einsaetze * 10) / 10;
  const satz = n(String(b.stundensatz).replace(',', '.')) || basis.stundensatz;
  const netto = Math.round((stundenMonat * satz * (1 - n(b.rabatt_prozent) / 100) + n(String(b.material_pauschale).replace(',', '.'))) * 100) / 100;
  return { stunden, einsaetze, stundenMonat, faktor, satz, netto, brutto: Math.round(netto * 1.2 * 100) / 100 };
}
// Feste deutsche Schreibweise, unabhängig von der Spracheinstellung des Geräts
const fmt = (v, dez) => { const n = Number(v) || 0; const [g, d] = Math.abs(n).toFixed(dez).split('.'); return (n < 0 ? '-' : '') + g.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (dez > 0 ? ',' + d : ''); };
const geld = (v) => fmt(v, 2) + ' €';
const zahl = (v) => fmt(v, 1).replace(/,0$/, '');

// ---------------------------------------------------------------------
// Ansichten
// ---------------------------------------------------------------------
function render() {
  const k = basis ? kalk() : null;
  app.innerHTML = `
    <header class="kopf"><button class="zurueck" data-a="start" aria-label="Zur Übersicht">‹</button><h1>${e(SCHRITTE[schritt])}</h1><span class="klein">${schritt + 1}/${SCHRITTE.length}</span></header>
    <div class="fortschritt" aria-hidden="true">${SCHRITTE.map((_, i) => `<span class="${i <= schritt ? 'an' : ''}"></span>`).join('')}</div>
    <main class="inhalt"><div id="hinweis"></div>${[viewKunde, viewObjekt, viewRaeume, viewZustand, viewFotos, viewKalk][schritt]()}</main>
    <div class="leiste">
      <div class="preis">${k && k.netto > 0 ? `<b>${geld(k.netto)}</b><small>netto / Monat · ${zahl(k.stunden)} Std je Einsatz</small>` : '<small>Noch keine Kalkulation</small>'}</div>
      <div class="knoepfe">${schritt > 0 ? '<button class="knopf zweit klein" data-a="vor">Zurück</button>' : ''}${schritt < SCHRITTE.length - 1 ? '<button class="knopf klein" data-a="weiter">Weiter</button>' : ''}</div>
    </div>`;
  binde();
}

function viewStart() {
  const lokal = lokalLaden();
  app.innerHTML = `
    <header class="kopf"><h1>Besichtigungen</h1><a class="klein" href="/admin/">Büro</a></header>
    <main class="inhalt">
      <button class="knopf" data-a="neu">Neue Besichtigung</button>
      ${lokal && lokal.b && !lokal.b.id ? `<button class="knopf zweit" data-a="fortsetzen">Nicht gespeicherten Entwurf fortsetzen</button>` : ''}
      <h2>Zuletzt</h2>
      ${basis.offen.length ? basis.offen.map((x) => `<div class="karte klick" data-a="laden" data-id="${x.id}"><div class="einsatz" style="grid-template-columns:1fr auto"><div><div class="titel">${e(x.firmenname || 'Ohne Kunde')}</div><div class="sub">${e(x.objektart || '')}${x.gesamtflaeche ? ' · ' + zahl(Number(x.gesamtflaeche)) + ' m²' : ''}${x.kalk_preis_monat ? ' · ' + geld(Number(x.kalk_preis_monat)) : ''} · ${x.aktualisiert_am.substring(8, 10)}.${x.aktualisiert_am.substring(5, 7)}.</div></div><span class="marke ${x.status === 'angebot_erstellt' ? 'ok' : x.status === 'abgeschlossen' ? 'lauf' : 'aus'}">${{ entwurf: 'Entwurf', abgeschlossen: 'abgeschlossen', angebot_erstellt: 'Angebot' }[x.status] || x.status}</span></div></div>`).join('') : '<p class="leer">Noch keine Besichtigungen.</p>'}
    </main>`;
  app.querySelector('[data-a=neu]').onclick = () => { b = leer(); b.stundensatz = ''; fotos = []; schritt = 0; gespeichert = null; lokalSichern(); render(); };
  const f = app.querySelector('[data-a=fortsetzen]'); if (f) f.onclick = () => { b = lokal.b; schritt = lokal.schritt || 0; render(); };
  app.querySelectorAll('[data-a=laden]').forEach((k) => (k.onclick = async () => {
    try { const d = await json('/besichtigung/?d=lade&id=' + k.dataset.id); fotos = d.fotos || []; delete d.fotos; b = { ...leer(), ...Object.fromEntries(Object.entries(d).map(([kk, v]) => [kk, v === null ? '' : v])) };
      b.uhrzeit_von = (b.uhrzeit_von || '').substring(0, 5); b.uhrzeit_bis = (b.uhrzeit_bis || '').substring(0, 5); schritt = 0; render(); }
    catch (err) { alert(err.message); }
  }));
}

function feld(key, label, opt = {}) {
  const t = opt.typ || 'text';
  if (t === 'textarea') return `<label>${e(label)}<textarea data-k="${key}" rows="${opt.rows || 2}" placeholder="${e(opt.ph || '')}">${e(b[key])}</textarea></label>`;
  return `<label>${e(label)}<input data-k="${key}" type="${t}" value="${e(b[key])}" placeholder="${e(opt.ph || '')}" ${opt.attr || ''}></label>`;
}
function zaehler(key, label) {
  return `<div class="zaehler"><span>${e(label)}</span><div><button type="button" data-z="${key}" data-d="-1" aria-label="weniger">−</button><b>${Number(b[key]) || 0}</b><button type="button" data-z="${key}" data-d="1" aria-label="mehr">+</button></div></div>`;
}
function schalter(key, label) {
  return `<label class="schalter"><input type="checkbox" data-k="${key}" ${Number(b[key]) ? 'checked' : ''}><span>${e(label)}</span></label>`;
}
function skala(key, label, texte) {
  return `<div class="skala"><span>${e(label)}</span><div>${[1, 2, 3, 4, 5].map((n) => `<button type="button" data-s="${key}" data-v="${n}" class="${Number(b[key]) === n ? 'an' : ''}" title="${e(texte[n - 1])}">${n}</button>`).join('')}</div><small>${e(texte[(Number(b[key]) || 3) - 1])}</small></div>`;
}
function wahl(key, label, optionen) {
  return `<div class="wahl"><span>${e(label)}</span><div>${Object.entries(optionen).map(([v, t]) => `<button type="button" data-w="${key}" data-v="${v}" class="${b[key] === v ? 'an' : ''}">${e(t)}</button>`).join('')}</div></div>`;
}

function viewKunde() {
  const k = basis.kunden.find((x) => Number(x.id) === Number(b.kunde_id));
  return `<div class="karte">
    <label>Kunde<select data-k="kunde_id"><option value="">— wählen —</option>${basis.kunden.map((x) => `<option value="${x.id}" ${Number(x.id) === Number(b.kunde_id) ? 'selected' : ''}>${e(x.firmenname)}${x.status === 'interessent' ? ' (Interessent)' : ''}</option>`).join('')}</select></label>
    ${k ? `<p class="klein" style="margin-top:8px">${e([k.kontaktperson, k.telefon, k.adresse ? k.adresse + ', ' + k.plz + ' ' + k.ort : ''].filter(Boolean).join(' · '))}</p>` : ''}
    <button class="knopf zweit klein" data-a="neukunde">Neuen Interessenten anlegen</button>
  </div>
  <div class="karte">${feld('ansprechpartner', 'Ansprechperson vor Ort')}${feld('ansprechpartner_telefon', 'Telefon vor Ort', { typ: 'tel' })}</div>
  <div id="neukunde" class="karte" hidden>
    <h3>Neuer Interessent</h3>
    <label>Firma<input id="nk_firma"></label><label>Ansprechperson<input id="nk_kp"></label><label>Telefon<input id="nk_tel" type="tel"></label><label>E-Mail<input id="nk_mail" type="email"></label>
    <label>Adresse<input id="nk_adr"></label><div class="zeile"><label>PLZ<input id="nk_plz" inputmode="numeric"></label><label>Ort<input id="nk_ort" value="Wien"></label></div>
    <button class="knopf klein" data-a="neukunde_speichern">Anlegen und auswählen</button>
  </div>`;
}
function viewObjekt() {
  return `<div class="karte">
    ${wahl('objektart', 'Art', { 'Büro': 'Büro', 'Ordination': 'Ordination', 'Kanzlei': 'Kanzlei', 'Geschäft': 'Geschäft', 'Stiegenhaus': 'Stiegenhaus', 'Sonstiges': 'Sonstiges' })}
    ${feld('gesamtflaeche', 'Gesamtfläche in m²', { typ: 'text', attr: 'inputmode="decimal"', ph: 'z. B. 180' })}
    ${zaehler('anzahl_raeume', 'Räume')}${zaehler('stockwerke', 'Stockwerke')}
    ${schalter('aufzug', 'Aufzug vorhanden')}
    ${feld('bodenbelaege', 'Bodenbeläge', { ph: 'Parkett, Fliesen, Teppich …' })}
  </div>`;
}
function viewRaeume() {
  return `<div class="karte"><h3>Sanitär</h3>${zaehler('wc', 'WC')}${zaehler('urinale', 'Urinale')}${zaehler('duschen', 'Duschen')}</div>
  <div class="karte"><h3>Bereiche</h3>${zaehler('kueche', 'Küchen / Teeküchen')}${zaehler('besprechungsraeume', 'Besprechungsräume')}${schalter('empfang', 'Empfang')}${schalter('serverraum', 'Serverraum (nicht betreten)')}${schalter('lager', 'Lager')}</div>
  <div class="karte"><h3>Glas</h3>${zaehler('fenster_anzahl', 'Fenster')}${schalter('glas_innen', 'Glasflächen innen')}${schalter('glas_aussen', 'Glasflächen außen')}${feld('fenster_erreichbar', 'Erreichbarkeit der Fenster', { ph: 'ebenerdig, Leiter, Hubsteiger nötig' })}</div>`;
}
function viewZustand() {
  const z = ['sehr schlecht', 'schlecht', 'normal', 'gut', 'sehr gut'];
  return `<div class="karte"><h3>Zustand</h3>${skala('verschmutzung_boeden', 'Böden', z)}${skala('verschmutzung_sanitaer', 'Sanitär', z)}${skala('verschmutzung_kueche', 'Küche', z)}${skala('gesamteindruck', 'Gesamteindruck (fließt in die Kalkulation)', z)}</div>
  <div class="karte"><h3>Reinigungsrhythmus</h3>${wahl('intervall', 'Intervall', Object.fromEntries(Object.keys(basis.einsaetze).map((k) => [k, k.replace('-monat', '/Monat').replace('x', '×') + (k.includes('monat') ? '' : ' / Woche')])))}
    ${feld('reinigungstage', 'Tage', { ph: 'Mo Mi Fr' })}<div class="zeile">${feld('uhrzeit_von', 'Von', { typ: 'time' })}${feld('uhrzeit_bis', 'Bis', { typ: 'time' })}</div></div>
  <div class="karte"><h3>Zutritt und Logistik</h3>${feld('zutritt', 'Zutritt', { ph: 'Schlüssel, Code, Portier …' })}${feld('schluessel_abholung', 'Schlüsselübergabe')}${feld('parkmoeglichkeit', 'Parken')}${feld('erreichbarkeit_oeffi', 'Öffis')}
    ${schalter('material_lagerraum', 'Lagerplatz für Material vorhanden')}${schalter('material_stellt_kunde', 'Kunde stellt Material')}${schalter('strom_wasser_vorhanden', 'Strom und Wasser zugänglich')}</div>
  <div class="karte"><h3>Bedenken</h3>${feld('red_flags', 'Auffälligkeiten', { typ: 'textarea', ph: 'Preisvorstellung unrealistisch, Zahlungsmoral, Zustand …' })}</div>`;
}
function viewFotos() {
  return `<div class="karte">
    ${b.id ? `<label class="knopf fotoknopf"><span>Foto aufnehmen</span><input type="file" accept="image/*" capture="environment" id="foto"></label>
      <div class="fotos" id="fotoliste">${fotos.map((f) => `<div class="platz ok" style="padding:0;overflow:hidden"><img src="/besichtigung/?d=foto&id=${f.id}" alt="" style="width:100%;height:100%;object-fit:cover"></div>`).join('')}</div>
      <p class="klein" style="margin-top:10px">${fotos.length} Foto${fotos.length === 1 ? '' : 's'}. Räume, Böden, Sanitär, Problemstellen — keine Personen.</p>`
      : '<p class="klein">Besichtigung wird zuerst gespeichert, dann können Fotos aufgenommen werden.</p><button class="knopf klein" data-a="speichern">Jetzt speichern</button>'}
  </div>
  <div class="karte">${feld('hygiene_info', 'Hygiene-Anforderungen', { typ: 'textarea', ph: 'Ordination: Desinfektion, Farbcodes …' })}${feld('sicherheit_info', 'Sicherheit', { typ: 'textarea', ph: 'Alarm, Schließanlage, Bereiche nicht betreten' })}${feld('erwartung_info', 'Erwartungen des Kunden', { typ: 'textarea' })}</div>`;
}
function viewKalk() {
  const k = kalk();
  return `<div class="karte">
    <div class="zeile">${feld('stundensatz', 'Stundensatz netto', { typ: 'text', attr: 'inputmode="decimal"', ph: String(basis.stundensatz).replace('.', ',') })}${feld('rabatt_prozent', 'Rabatt %', { typ: 'number', attr: 'min="0" max="50"' })}</div>
    ${feld('material_pauschale', 'Materialpauschale / Monat', { typ: 'text', attr: 'inputmode="decimal"', ph: '0,00' })}
  </div>
  <div class="karte kalkbox">
    <div class="zeile2"><span>Zeit je Einsatz</span><b>${zahl(k.stunden)} Std</b></div>
    <div class="zeile2"><span>Einsätze / Monat</span><b>${zahl(k.einsaetze)}</b></div>
    <div class="zeile2"><span>Stunden / Monat</span><b>${zahl(k.stundenMonat)}</b></div>
    <div class="zeile2"><span>Zustandsfaktor</span><b>× ${k.faktor}</b></div>
    <div class="zeile2"><span>Satz</span><b>${geld(k.satz)} / Std</b></div>
    <div class="zeile2 gesamt"><span>Monatlich netto</span><b>${geld(k.netto)}</b></div>
    <div class="zeile2"><span>brutto</span><b>${geld(k.brutto)}</b></div>
  </div>
  <div class="karte">${feld('naechster_schritt', 'Nächster Schritt', { ph: 'Angebot bis Freitag, Rückruf …' })}${feld('interne_notizen', 'Interne Notizen', { typ: 'textarea' })}</div>
  <div id="angebot"></div>
  <button class="knopf" data-a="angebot" ${!b.kunde_id ? 'disabled' : ''}>Angebot erstellen${!b.kunde_id ? ' (Kunde fehlt)' : ''}</button>
  <button class="knopf zweit" data-a="speichern">Nur speichern</button>
  ${gespeichert ? `<p class="klein mittig">Zuletzt gespeichert ${gespeichert.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })}</p>` : ''}`;
}

// ---------------------------------------------------------------------
// Ereignisse
// ---------------------------------------------------------------------
function hinweis(text, typ) { const h = app.querySelector('#hinweis'); if (h) h.innerHTML = `<div class="${typ === 'ok' ? 'erfolg' : 'fehler'}">${e(text)}</div>`; }
function preisAktualisieren() { const p = app.querySelector('.preis'); if (!p) return; const k = kalk(); p.innerHTML = k.netto > 0 ? `<b>${geld(k.netto)}</b><small>netto / Monat · ${zahl(k.stunden)} Std je Einsatz</small>` : '<small>Noch keine Kalkulation</small>'; }

function binde() {
  app.querySelectorAll('[data-k]').forEach((el) => {
    const ev = el.tagName === 'SELECT' || el.type === 'checkbox' ? 'change' : 'input';
    el.addEventListener(ev, () => { b[el.dataset.k] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; lokalSichern(); preisAktualisieren(); if (el.dataset.k === 'kunde_id') render(); });
  });
  app.querySelectorAll('[data-z]').forEach((btn) => (btn.onclick = () => { b[btn.dataset.z] = Math.max(0, (Number(b[btn.dataset.z]) || 0) + Number(btn.dataset.d)); btn.parentElement.querySelector('b').textContent = b[btn.dataset.z]; lokalSichern(); preisAktualisieren(); }));
  app.querySelectorAll('[data-s]').forEach((btn) => (btn.onclick = () => { b[btn.dataset.s] = Number(btn.dataset.v); render(); }));
  app.querySelectorAll('[data-w]').forEach((btn) => (btn.onclick = () => { b[btn.dataset.w] = btn.dataset.v; render(); }));
  const w = app.querySelector('[data-a=weiter]'); if (w) w.onclick = async () => { await speichern(true); schritt++; render(); };
  const v = app.querySelector('[data-a=vor]'); if (v) v.onclick = () => { schritt--; render(); };
  const s = app.querySelector('[data-a=start]'); if (s) s.onclick = async () => { await speichern(true); await ladeBasis(); viewStart(); };
  app.querySelectorAll('[data-a=speichern]').forEach((btn) => (btn.onclick = async () => { if (await speichern()) render(); }));
  const nk = app.querySelector('[data-a=neukunde]'); if (nk) nk.onclick = () => { app.querySelector('#neukunde').hidden = false; app.querySelector('#nk_firma').focus(); };
  const nks = app.querySelector('[data-a=neukunde_speichern]'); if (nks) nks.onclick = async () => {
    try { const d = await json('/besichtigung/', { method: 'POST', body: form({ aktion: 'kunde', firmenname: app.querySelector('#nk_firma').value, kontaktperson: app.querySelector('#nk_kp').value, telefon: app.querySelector('#nk_tel').value, email: app.querySelector('#nk_mail').value, adresse: app.querySelector('#nk_adr').value, plz: app.querySelector('#nk_plz').value, ort: app.querySelector('#nk_ort').value }) });
      await ladeBasis(); b.kunde_id = d.id; lokalSichern(); render(); hinweis('Interessent angelegt.', 'ok'); }
    catch (err) { hinweis(err.message, 'fehler'); }
  };
  const fi = app.querySelector('#foto'); if (fi) fi.onchange = async () => {
    const f = fi.files[0]; if (!f) return; fi.closest('label').querySelector('span').textContent = 'Wird übertragen …';
    try { const blob = await verkleinern(f); await json('/besichtigung/', { method: 'POST', body: (() => { const fd = form({ aktion: 'foto', id: b.id }); fd.append('foto', blob, 'foto.jpg'); return fd; })() });
      const d = await json('/besichtigung/?d=lade&id=' + b.id); fotos = d.fotos || []; render(); hinweis('Foto gespeichert.', 'ok'); }
    catch (err) { render(); hinweis(err.message, 'fehler'); }
  };
  const ab = app.querySelector('[data-a=angebot]'); if (ab) ab.onclick = async () => {
    ab.disabled = true;
    if (!(await speichern(true))) { ab.disabled = false; return; }
    try { const d = await json('/besichtigung/', { method: 'POST', body: form({ aktion: 'angebot', id: b.id }) });
      app.querySelector('#angebot').innerHTML = `<div class="erfolg">Angebot ${e(d.nummer)} erstellt · ${geld(d.brutto)} brutto/Monat</div><a class="knopf zweit" href="${e(d.pdf)}" target="_blank">Angebot als PDF öffnen</a>`; lokalLoeschen(); }
    catch (err) { hinweis(err.message, 'fehler'); ab.disabled = false; }
  };
}

function verkleinern(datei, maxKante = 1600, q = 0.82) {
  return new Promise((res, rej) => { const url = URL.createObjectURL(datei); const img = new Image();
    img.onload = () => { const s = Math.min(1, maxKante / Math.max(img.width, img.height)); const c = document.createElement('canvas'); c.width = Math.round(img.width * s); c.height = Math.round(img.height * s); c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url); c.toBlob((bl) => bl ? res(bl) : rej(new Error('Bild nicht verarbeitbar.')), 'image/jpeg', q); };
    img.onerror = () => { URL.revokeObjectURL(url); rej(new Error('Bild nicht lesbar.')); }; img.src = url; });
}

async function ladeBasis() { basis = await json('/besichtigung/?d=liste'); }

(async function start() {
  try { await ladeBasis(); viewStart(); }
  catch (err) { app.innerHTML = `<div class="anmelden"><h1>Fehler</h1><p>${e(err.message)}</p></div>`; }
})();
