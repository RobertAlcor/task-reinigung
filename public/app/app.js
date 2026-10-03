/* Mitarbeiter-App — Vanilla JS, ohne Framework.
   Offline-fähig: Plan wird zwischengespeichert, Aktionen landen in einer
   Warteschlange (IndexedDB) und werden bei Verbindung übertragen. */
'use strict';

const API = '/api/';
const SPRACHEN = ['de', 'en', 'tr', 'hr', 'ro', 'pl', 'hu'];
let sprache = localStorage.getItem('sprache') || ((navigator.language || 'de').substring(0, 2).toLowerCase());
if (!SPRACHEN.includes(sprache)) sprache = 'de';
document.documentElement.lang = sprache;
const t = (k, v) => { let s = (window.I18N && (I18N[sprache][k] ?? I18N.de[k])) ?? k; if (v) Object.entries(v).forEach(([a, b]) => { s = s.replace('{' + a + '}', b); }); return s; };
/* Serverfehler (deutsch) in die App-Sprache uebersetzen, wo bekannt */
const tFehler = (m) => /Personalnummer oder PIN/.test(m) ? t('srv_login') : /Fehlversuche/.test(m) ? t('srv_gesperrt') : /gehört nicht zum Objekt/.test(m) ? t('srv_code') : /bisherige PIN/.test(m) ? t('srv_pin_alt') : m;
function sprachWechsel(l) { if (!SPRACHEN.includes(l)) return; sprache = l; localStorage.setItem('sprache', l); document.documentElement.lang = l; render(); }
const app = document.getElementById('app');
const offlineLeiste = document.getElementById('offline-leiste');

// ---------------------------------------------------------------------
// Zustand
// ---------------------------------------------------------------------
const zustand = {
  token: localStorage.getItem('token'),
  profil: JSON.parse(localStorage.getItem('profil') || 'null'),
  plan: JSON.parse(localStorage.getItem('plan') || '[]'),
  planStand: localStorage.getItem('planStand'),
  ansicht: 'plan',
  einsatzId: null,
  qrToken: null,                // aus /app/?c=…
  online: navigator.onLine,
};

// ---------------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------------
const e = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const zeit = (t) => (t || '').substring(0, 5);
const datumDe = (d) => { const [j, m, t] = d.split('-'); return `${t}.${m}.${j}`; };
const WT = { de: ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'], en: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], tr: ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'], hr: ['Ned', 'Pon', 'Uto', 'Sri', 'Čet', 'Pet', 'Sub'], ro: ['Dum', 'Lun', 'Mar', 'Mie', 'Joi', 'Vin', 'Sâm'], pl: ['Nd', 'Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'So'], hu: ['V', 'H', 'K', 'Sze', 'Cs', 'P', 'Szo'] };
const wochentag = (d) => (WT[sprache] || WT.de)[new Date(d + 'T12:00:00').getDay()];
const heute = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
const jetztIso = () => new Date().toISOString();
const jetztLokal = () => { const d = new Date(), p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`; };
const minutenText = (m) => `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')} ${t('std')}`;
const uhr = (dt) => dt ? dt.substring(11, 16) : '';

function setzeAnsicht(name, param) {
  zustand.ansicht = name;
  if (param !== undefined) zustand.einsatzId = param;
  render();
  window.scrollTo(0, 0);
}

// ---------------------------------------------------------------------
// API
// ---------------------------------------------------------------------
async function api(pfad, opt = {}) {
  const kopf = { Accept: 'application/json' };
  if (zustand.token) kopf.Authorization = 'Bearer ' + zustand.token;
  let body = opt.body;
  if (body && !(body instanceof FormData)) { kopf['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
  const r = await fetch(API + pfad, { method: opt.method || 'GET', headers: kopf, body });
  let d = null;
  try { d = await r.json(); } catch (_) {}
  if (r.status === 401 && pfad !== 'app/login') { abmelden(false); throw new Error(t('neu_anmelden')); }
  if (!r.ok || !d || d.ok === false) { const orig = (d && d.fehler) || t('fehler') + ' ' + r.status; const err = new Error(tFehler(orig)); err.original = orig; throw err; }
  return d.data;
}

// ---------------------------------------------------------------------
// Offline-Warteschlange (IndexedDB)
// ---------------------------------------------------------------------
const DB_NAME = 'einsaetze-app';
function idb() {
  return new Promise((res, rej) => {
    const q = indexedDB.open(DB_NAME, 1);
    q.onupgradeneeded = () => { const d = q.result; if (!d.objectStoreNames.contains('queue')) d.createObjectStore('queue', { keyPath: 'lokal_id' }); };
    q.onsuccess = () => res(q.result); q.onerror = () => rej(q.error);
  });
}
async function queueAdd(eintrag) {
  eintrag.lokal_id = eintrag.lokal_id || (Date.now() + '-' + Math.random().toString(36).slice(2, 8));
  const d = await idb(); await new Promise((res, rej) => { const t = d.transaction('queue', 'readwrite'); t.objectStore('queue').put(eintrag); t.oncomplete = res; t.onerror = () => rej(t.error); });
  aktualisiereOfflineAnzeige();
  return eintrag.lokal_id;
}
async function queueAlle() {
  const d = await idb(); return new Promise((res, rej) => { const q = d.transaction('queue').objectStore('queue').getAll(); q.onsuccess = () => res(q.result); q.onerror = () => rej(q.error); });
}
async function queueEntfernen(ids) {
  const d = await idb(); await new Promise((res, rej) => { const t = d.transaction('queue', 'readwrite'); ids.forEach((id) => t.objectStore('queue').delete(id)); t.oncomplete = res; t.onerror = () => rej(t.error); });
  aktualisiereOfflineAnzeige();
}
let syncLaeuft = false;
async function sync() {
  if (!zustand.token || !navigator.onLine || syncLaeuft) return;
  syncLaeuft = true;
  try {
    const alle = await queueAlle();
    const daten = alle.filter((x) => x.typ !== 'foto');
    const fotos = alle.filter((x) => x.typ === 'foto');
    if (daten.length) {
      const erg = await api('app/sync', { method: 'POST', body: { eintraege: daten } });
      const fertig = erg.filter((x) => x.ok || /bereits|nicht zugeteilt|Unbekannt/.test(x.fehler || '')).map((x) => x.lokal_id);
      await queueEntfernen(fertig);
      erg.filter((x) => !x.ok).forEach((x) => console.warn('Sync:', x.fehler));
    }
    for (const f of fotos) {
      try {
        const fd = new FormData(); fd.append('einsatz_id', f.einsatz_id); fd.append('typ', f.foto_typ); fd.append('foto', f.blob, 'foto.jpg');
        await api('app/foto', { method: 'POST', body: fd });
        await queueEntfernen([f.lokal_id]);
      } catch (err) { if (/nicht zugeteilt|gültiges Foto|Nur JPEG/.test(err.original || err.message)) await queueEntfernen([f.lokal_id]); else console.warn('Foto-Sync:', err.message); }
    }
    await ladePlan(true);
  } catch (err) { console.warn('Sync fehlgeschlagen:', err.message); }
  finally { syncLaeuft = false; render(); }
}
async function aktualisiereOfflineAnzeige() {
  const n = (await queueAlle()).length;
  offlineLeiste.hidden = navigator.onLine && n === 0;
  document.body.classList.toggle('hat-leiste', !offlineLeiste.hidden);
  offlineLeiste.textContent = navigator.onLine ? t('online_leiste', { n }) : t('offline_leiste', { n });
}
window.addEventListener('online', () => { zustand.online = true; sync(); aktualisiereOfflineAnzeige(); });
window.addEventListener('offline', () => { zustand.online = false; aktualisiereOfflineAnzeige(); });

// ---------------------------------------------------------------------
// Daten laden
// ---------------------------------------------------------------------
async function pushNachLogin() { if (pushMoeglich() && !iosOhneInstallation() && !localStorage.getItem('push_status')) { setTimeout(() => pushAnmelden(true), 1500); } }
async function ladePlan(still = false) {
  try {
    const plan = await api('app/meine-einsaetze');
    zustand.plan = plan; zustand.planStand = jetztIso();
    localStorage.setItem('plan', JSON.stringify(plan)); localStorage.setItem('planStand', zustand.planStand);
    const profil = await api('app/profil');
    zustand.profil = profil; localStorage.setItem('profil', JSON.stringify(profil));
  } catch (err) { if (!still) console.warn(err.message); }
}
function einsatz(id) { return zustand.plan.find((x) => Number(x.id) === Number(id)); }

// ---------------------------------------------------------------------
// Anmeldung
// ---------------------------------------------------------------------
async function anmelden(pnr, pin) {
  const d = await api('app/login', { method: 'POST', body: { personalnummer: pnr, pin, geraet: navigator.userAgent.substring(0, 140) } });
  zustand.token = d.token; localStorage.setItem('token', d.token); pushNachLogin();
  await ladePlan(); await sync();
}
function abmelden(server = true) {
  if (server && zustand.token) api('logout', { method: 'POST' }).catch(() => {});
  ['token', 'profil', 'plan', 'planStand'].forEach((k) => localStorage.removeItem(k));
  zustand.token = null; zustand.profil = null; zustand.plan = []; zustand.ansicht = 'plan'; render();
}

// ---------------------------------------------------------------------
// Check-in / Check-out (online direkt, sonst Warteschlange)
// ---------------------------------------------------------------------
async function checkin(einsatzId, token, methode = 'qr') {
  const eintrag = { typ: 'checkin', einsatz_id: einsatzId, token, methode, zeit: jetztIso() };
  const ein = einsatz(einsatzId);
  if (ein) { ein.checkin_zeit = jetztLokal(); ein.status = 'laeuft'; localStorage.setItem('plan', JSON.stringify(zustand.plan)); }
  if (navigator.onLine) {
    try { await api('app/checkin', { method: 'POST', body: { einsatz_id: einsatzId, token, methode, zeit: eintrag.zeit } }); await ladePlan(true); return 'online'; }
    catch (err) { if (/gehört nicht|nicht gefunden|nicht zugeteilt/.test(err.original || err.message)) { await ladePlan(true); throw err; } }
  }
  await queueAdd(eintrag); return 'offline';
}
async function checkout(einsatzId, pause, methode = 'qr') {
  const eintrag = { typ: 'checkout', einsatz_id: einsatzId, pause_minuten: pause, methode, zeit: jetztIso() };
  const ein = einsatz(einsatzId);
  if (ein) { ein.checkout_zeit = jetztLokal(); ein.status = 'erledigt'; localStorage.setItem('plan', JSON.stringify(zustand.plan)); }
  if (navigator.onLine) {
    try { await api('app/checkout', { method: 'POST', body: { einsatz_id: einsatzId, pause_minuten: pause, methode, zeit: eintrag.zeit } }); await ladePlan(true); return 'online'; }
    catch (err) { if (/Kein Check-in|nicht gefunden/.test(err.original || err.message)) { await ladePlan(true); throw err; } }
  }
  await queueAdd(eintrag); return 'offline';
}

// ---------------------------------------------------------------------
// Fotos: am Gerät verkleinern, dann hochladen oder einreihen
// ---------------------------------------------------------------------
function verkleinern(datei, maxKante = 1600, qualitaet = 0.82) {
  return new Promise((res, rej) => {
    const url = URL.createObjectURL(datei); const img = new Image();
    img.onload = () => {
      const s = Math.min(1, maxKante / Math.max(img.width, img.height));
      const c = document.createElement('canvas'); c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(url);
      c.toBlob((b) => b ? res(b) : rej(new Error(t('bild_fehler'))), 'image/jpeg', qualitaet);
    };
    img.onerror = () => { URL.revokeObjectURL(url); rej(new Error(t('bild_fehler'))); };
    img.src = url;
  });
}
const lokaleFotos = [];   // offline aufgenommene Fotos dieser Sitzung (Vorschau)
async function ladeFotos(einsatzId) {
  const reihen = { vorher: app.querySelector('[data-reihe=vorher]'), nachher: app.querySelector('[data-reihe=nachher]') };
  if (!reihen.vorher || !reihen.nachher) return;
  const html = { vorher: '', nachher: '' };
  lokaleFotos.filter((f) => f.einsatz_id === einsatzId).forEach((f) => { html[f.typ] += `<figure class="offline"><img src="${f.url}" alt=""><figcaption>${t('offline')}</figcaption></figure>`; });
  if (navigator.onLine) {
    try { (await api(`app/einsaetze/${einsatzId}/fotos`)).forEach((f) => { if (f.thumb && html[f.typ] !== undefined) html[f.typ] += `<figure><img src="${f.thumb}" alt=""><figcaption>${uhr(f.aufgenommen_am)}</figcaption></figure>`; }); } catch (err) {}
  }
  Object.keys(reihen).forEach((k) => { reihen[k].innerHTML = html[k] || `<div class="fotoleer">${t('noch_kein_foto')}</div>`; });
}

async function fotoHochladen(einsatzId, typ, datei) {
  const blob = await verkleinern(datei);
  if (navigator.onLine) {
    try { const fd = new FormData(); fd.append('einsatz_id', einsatzId); fd.append('typ', typ); fd.append('foto', blob, 'foto.jpg'); await api('app/foto', { method: 'POST', body: fd }); await ladePlan(true); return 'online'; }
    catch (err) { if (/nicht zugeteilt|Nur JPEG/.test(err.original || err.message)) throw err; }
  }
  await queueAdd({ typ: 'foto', einsatz_id: einsatzId, foto_typ: typ, blob }); return 'offline';
}

// ---------------------------------------------------------------------
// QR-Scanner: BarcodeDetector, sonst jsQR
// ---------------------------------------------------------------------
let scanStop = null;
function scanner(zielTitel, beiTreffer) {
  const box = document.createElement('div'); box.className = 'scanner';
  box.innerHTML = `<video playsinline autoplay muted aria-label="Kamera"></video><div class="rahmen"></div>
    <div class="unten"><p>${e(zielTitel)}</p><button class="knopf manuell" data-a="manuell">${t('code_eingeben')}</button><button class="knopf zweit" data-a="abbrechen">${t('abbrechen')}</button></div>`;
  document.body.appendChild(box);
  const video = box.querySelector('video'); let stream = null, aktiv = true;
  const ende = () => { aktiv = false; if (stream) stream.getTracks().forEach((t) => t.stop()); box.remove(); scanStop = null; };
  scanStop = ende;
  box.querySelector('[data-a=abbrechen]').onclick = ende;
  box.querySelector('[data-a=manuell]').onclick = () => { const c = prompt(t('code_prompt')); if (c && /^[a-f0-9]{32}$/i.test(c.trim())) { ende(); beiTreffer(c.trim().toLowerCase()); } };
  const treffer = (text) => { const m = /([a-f0-9]{32})/i.exec(text || ''); if (m) { ende(); beiTreffer(m[1].toLowerCase()); } };

  navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } }, audio: false }).then(async (s) => {
    stream = s; video.srcObject = s; await video.play().catch(() => {});
    if ('BarcodeDetector' in window) {
      const det = new BarcodeDetector({ formats: ['qr_code'] });
      const lauf = async () => { if (!aktiv) return; try { const codes = await det.detect(video); if (codes.length) return treffer(codes[0].rawValue); } catch (_) {} setTimeout(lauf, 250); };
      lauf();
    } else if (window.jsQR) {
      const c = document.createElement('canvas'); const ctx = c.getContext('2d', { willReadFrequently: true });
      const lauf = () => { if (!aktiv) return; if (video.readyState >= 2) { c.width = video.videoWidth; c.height = video.videoHeight; ctx.drawImage(video, 0, 0); const d = ctx.getImageData(0, 0, c.width, c.height); const r = window.jsQR(d.data, d.width, d.height, { inversionAttempts: 'dontInvert' }); if (r) return treffer(r.data); } setTimeout(lauf, 300); };
      lauf();
    }
  }).catch(() => { box.querySelector('.unten p').textContent = t('kamera_fehlt'); });
}

// ---------------------------------------------------------------------
// Ansichten
// ---------------------------------------------------------------------
function render() {
  if (!zustand.token) return renderLogin();
  const a = zustand.ansicht;
  const inhalt = a === 'einsatz' ? viewEinsatz() : a === 'zeiten' ? viewZeiten() : a === 'abwesenheit' ? viewAbwesenheit() : a === 'mehr' ? viewMehr() : a === 'nachrichten' ? viewNachrichten() : a === 'anfragen' ? viewAnfragen() : a === 'pin' ? viewPin() : viewPlan();
  const zurueck = ['einsatz', 'nachrichten', 'anfragen', 'pin'].includes(a);
  const titel = { plan: t('meine_einsaetze'), einsatz: t('einsatz'), zeiten: t('meine_zeiten'), abwesenheit: t('abwesenheit'), mehr: t('mehr'), nachrichten: t('nachrichten'), anfragen: t('anfragen'), pin: t('pin_aendern') }[a];
  app.innerHTML = `
    <header class="kopf">${zurueck ? '<button class="zurueck" data-a="zurueck">‹ ' + t('zurueck') + '</button>' : ''}<h1>${e(titel)}</h1></header>
    <main class="inhalt">${inhalt}</main>
    <nav class="nav" aria-label="Hauptnavigation">
      <button data-a="nav" data-z="plan" class="${['plan', 'einsatz'].includes(a) ? 'aktiv' : ''}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>${t('nav_plan')}</button>
      <button data-a="nav" data-z="zeiten" class="${a === 'zeiten' ? 'aktiv' : ''}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>${t('nav_zeiten')}</button>
      <button data-a="nav" data-z="abwesenheit" class="${a === 'abwesenheit' ? 'aktiv' : ''}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19h16M6 15l4-8 3 5 2-3 3 6"/></svg>${t('nav_frei')}</button>
      <button data-a="nav" data-z="mehr" class="${['mehr', 'nachrichten', 'pin'].includes(a) ? 'aktiv' : ''}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>${t('nav_mehr')}${zustand.profil && (zustand.profil.ungelesen > 0 || zustand.profil.anfragen > 0) ? '<span class="punkt"></span>' : ''}</button>
    </nav>`;
  app.querySelectorAll('[data-a=nav]').forEach((b) => (b.onclick = () => setzeAnsicht(b.dataset.z)));
  const z = app.querySelector('[data-a=zurueck]'); if (z) z.onclick = () => setzeAnsicht(a === 'einsatz' ? 'plan' : 'mehr');
  bindeAnsicht(a);
}

function renderLogin() {
  app.innerHTML = `<div class="anmelden">
    <svg class="logo" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="2.5" y="4.5" width="19" height="15.5" rx="2.5" stroke="#0B5D4A" stroke-width="1.6"/><path d="M2.5 9.5h19" stroke="#0B5D4A" stroke-width="1.6"/><rect x="6" y="12.5" width="4" height="4" rx="1" fill="#0B5D4A"/><rect x="14" y="12.5" width="4" height="4" rx="1" fill="#0B5D4A" opacity=".35"/></svg>
    <h1>${t('anmelden')}</h1><p>${t('anmelden_sub')}</p>
    <div id="fehler"></div>
    <form id="login"><label for="pnr">${t('personalnummer')}</label><input id="pnr" name="pnr" inputmode="numeric" autocomplete="username" required autofocus>
      <label for="pin">${t('pin')}</label><input id="pin" name="pin" type="password" inputmode="numeric" pattern="\\d{4}" maxlength="4" class="pin" autocomplete="current-password" required>
      <button class="knopf" type="submit">${t('anmelden')}</button></form>
    <label style="margin-top:22px">${t('sprache')}<select id="sprache">${SPRACHEN.map((l) => `<option value="${l}" ${l === sprache ? 'selected' : ''}>${I18N.sprachen[l]}</option>`).join('')}</select></label></div>`;
  app.querySelector('#sprache').onchange = (ev) => sprachWechsel(ev.target.value);
  app.querySelector('#login').onsubmit = async (ev) => {
    ev.preventDefault(); const f = ev.target; const btn = f.querySelector('button'); btn.disabled = true;
    try { await anmelden(f.pnr.value.trim(), f.pin.value); if (zustand.qrToken) return qrFluss(zustand.qrToken); render(); }
    catch (err) { app.querySelector('#fehler').innerHTML = `<div class="fehler">${e(err.message)}</div>`; btn.disabled = false; }
  };
}

function viewPlan() {
  const tage = {}; zustand.plan.forEach((x) => { (tage[x.datum] = tage[x.datum] || []).push(x); });
  const h = heute(); const keys = Object.keys(tage).sort();
  let html = `<button class="knopf" data-a="scan"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8V5a1 1 0 011-1h3M16 4h3a1 1 0 011 1v3M20 16v3a1 1 0 01-1 1h-3M8 20H5a1 1 0 01-1-1v-3"/><rect x="8" y="8" width="8" height="8"/></svg>${t('code_scannen')}</button>`;
  if (zustand.profil && zustand.profil.anfragen > 0) html += `<button class="knopf zweit" data-a="nav2" data-z="anfragen" style="border-color:var(--haus);color:var(--haus)">${t('anfragen_offen', { n: zustand.profil.anfragen })}</button>`;
  if (!keys.length) html += `<p class="leer">${t('keine_einsaetze')}${zustand.planStand ? '' : ' ' + t('verbindung_pruefen')}</p>`;
  keys.forEach((d) => {
    html += `<div class="tag-kopf">${d === h ? t('heute') : wochentag(d) + ', ' + datumDe(d)}<span>${tage[d].length} ${tage[d].length === 1 ? t('einsatz_1') : t('einsatz_n')}</span></div>`;
    tage[d].forEach((x) => { html += `<div class="karte klick" data-a="einsatz" data-id="${x.id}"><div class="einsatz"><div class="zeit">${zeit(x.uhrzeit_von)}<small>${t('bis')} ${zeit(x.uhrzeit_bis)}</small></div><div><div class="titel">${e(x.objekt_name)}</div><div class="sub">${e(x.adresse)}, ${e(x.plz)} · ${e(x.leistung)}${x.kollegen ? ' · ' + t('mit') + ' ' + e(x.kollegen) : ''}</div></div>${statusMarke(x)}</div></div>`; });
  });
  if (zustand.planStand) html += `<p class="klein mittig" style="margin-top:16px">${t('stand', { t: new Date(zustand.planStand).toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' }) })}${navigator.onLine ? '' : ' · ' + t('offline')}</p>`;
  return html;
}
function statusMarke(x) {
  if (x.checkout_zeit) return '<span class="marke ok">' + t('st_erledigt') + '</span>';
  if (x.checkin_zeit) return '<span class="marke lauf">' + t('st_laeuft') + '</span>';
  if (x.status === 'ausgefallen') return '<span class="marke rot">' + t('st_entfaellt') + '</span>';
  return '<span class="marke aus">' + t('st_geplant') + '</span>';
}

function viewEinsatz() {
  const x = einsatz(zustand.einsatzId); if (!x) return '<p class="leer">' + t('einsatz_nicht_gefunden') + '</p>';
  const laeuft = x.checkin_zeit && !x.checkout_zeit; const fertig = !!x.checkout_zeit;
  return `<div class="karte"><div class="einsatz" style="grid-template-columns:64px 1fr"><div class="zeit">${zeit(x.uhrzeit_von)}<small>${t('bis')} ${zeit(x.uhrzeit_bis)}</small></div><div><div class="titel" style="font-size:19px">${e(x.objekt_name)}</div><div class="sub">${x.datum === heute() ? t('heute') : wochentag(x.datum) + ', ' + datumDe(x.datum)} · ${e(x.leistung)}</div></div></div></div>
    <div id="meldung"></div>
    ${fertig ? `<div class="erfolg">${t('erledigt_zeile')} · ${uhr(x.checkin_zeit)}–${uhr(x.checkout_zeit)}${x.minuten_netto ? ' · ' + minutenText(Number(x.minuten_netto)) : ''}</div>`
      : laeuft ? `<div class="karte mittig"><div class="klein">${t('eingestempelt_um')}</div><div class="gross">${uhr(x.checkin_zeit)}</div></div><button class="knopf rot" data-a="checkout">${t('ausstempeln')}</button>`
      : `<button class="knopf" data-a="checkin">${t('ankommen')}</button>`}
    <h2>${t('fotos')}</h2>
    ${!laeuft && !fertig ? `<p class="klein" style="margin:-4px 0 10px">${t('foto_ablauf')}</p>` : ''}
    <div class="fotoblock ${!laeuft && !fertig ? 'aktiv' : ''}" data-typ="vorher"><div class="fotokopf"><b>${t('vorher')}</b><span>${Number(x.fotos_vorher) || 0} ${Number(x.fotos_vorher) === 1 ? t('foto_eins') : t('foto_mehr')}</span></div>
      <div class="fotoreihe" data-reihe="vorher"></div>
      ${fertig ? '' : `<label class="knopf zweit klein fotoknopf"><span>📷 ${t('foto_vorher_aufnehmen')}</span><input type="file" accept="image/*" capture="environment" data-typ="vorher"></label>`}</div>
    <div class="fotoblock ${laeuft ? 'aktiv' : ''}" data-typ="nachher"><div class="fotokopf"><b>${t('nachher')}</b><span>${Number(x.fotos_nachher) || 0} ${Number(x.fotos_nachher) === 1 ? t('foto_eins') : t('foto_mehr')}</span></div>
      <div class="fotoreihe" data-reihe="nachher"></div>
      ${fertig ? '' : `<label class="knopf ${laeuft ? '' : 'zweit'} klein fotoknopf"><span>📷 ${t('foto_nachher_aufnehmen')}</span><input type="file" accept="image/*" capture="environment" data-typ="nachher"></label>`}</div>
    <p class="klein" style="margin-top:6px">${t('foto_hinweis')}</p>
    <h2>${t('objekt')}</h2>
    <div class="karte"><dl class="fakten"><dt>${t('adresse')}</dt><dd>${e(x.adresse)}, ${e(x.plz)} ${e(x.ort)}</dd>${x.ansprechpartner ? `<dt>${t('vor_ort')}</dt><dd>${e(x.ansprechpartner)}${x.objekt_telefon ? ` · <a href="tel:${e(x.objekt_telefon.replace(/\s/g, ''))}">${e(x.objekt_telefon)}</a>` : ''}</dd>` : ''}${x.zugang_info ? `<dt>${t('zugang')}</dt><dd>${e(x.zugang_info)}</dd>` : ''}<dt>${t('soll')}</dt><dd>${x.dauer_soll_minuten} ${t('minuten')}</dd></dl>
    ${x.reinigung_hinweise ? `<div class="hinweis">${e(x.reinigung_hinweise)}</div>` : ''}${x.notizen_office ? `<div class="hinweis"><b>${t('buero')}:</b> ${e(x.notizen_office)}</div>` : ''}</div>
    <h2>${t('meine_notiz')}</h2>
    <textarea id="notiz" placeholder="${t('notiz_ph')}">${e(x.notizen_mitarbeiter || '')}</textarea>
    <button class="knopf zweit klein" data-a="notiz">${t('notiz_speichern')}</button>
    <a class="knopf zweit klein" href="https://maps.google.com/?q=${encodeURIComponent(x.adresse + ', ' + x.plz + ' ' + x.ort)}" target="_blank" rel="noopener">${t('route')}</a>`;
}

function viewZeiten() {
  return `<div id="zeiten"><div class="laden"><div class="kreis"></div></div></div>`;
}
function viewAbwesenheit() {
  return `<div id="abw"><div class="laden"><div class="kreis"></div></div></div>`;
}
// ---------------------------------------------------------------------
// Web-Push: Abo anlegen (nach Login), Status, Abmelden
// ---------------------------------------------------------------------
function pushMoeglich() { return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window; }
function iosOhneInstallation() { const ios = /iPhone|iPad|iPod/.test(navigator.userAgent); const standalone = window.navigator.standalone === true || matchMedia('(display-mode: standalone)').matches; return ios && !standalone; }
async function pushAnmelden(leise = false) {
  if (!pushMoeglich() || !zustand.token) return false;
  try {
    const perm = await Notification.requestPermission(); if (perm !== 'granted') { localStorage.setItem('push_status', 'abgelehnt'); return false; }
    const reg = await navigator.serviceWorker.ready;
    const key = (await api('app/push/schluessel')).public;
    const raw = Uint8Array.from(atob(key.replace(/-/g, '+').replace(/_/g, '/').padEnd(key.length + (4 - key.length % 4) % 4, '=')), (c) => c.charCodeAt(0));
    let sub = await reg.pushManager.getSubscription(); if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: raw });
    const j = sub.toJSON();
    await api('app/push/abo', { method: 'POST', body: { endpoint: sub.endpoint, p256dh: j.keys && j.keys.p256dh, auth: j.keys && j.keys.auth, geraet: navigator.userAgent.slice(0, 120) } });
    localStorage.setItem('push_status', 'aktiv'); return true;
  } catch (err) { if (!leise) meldung(`<div class="fehler">${e(err.message)}</div>`); localStorage.setItem('push_status', 'fehler'); return false; }
}
async function pushAbmelden() {
  try { const reg = await navigator.serviceWorker.ready; const sub = await reg.pushManager.getSubscription(); if (sub) { await api('app/push/abmelden', { method: 'POST', body: { endpoint: sub.endpoint } }); await sub.unsubscribe(); } } catch (err) {}
  localStorage.setItem('push_status', 'aus');
}
navigator.serviceWorker && navigator.serviceWorker.addEventListener('message', (ev) => { if (ev.data && ev.data.typ === 'nav') { setzeAnsicht(ev.data.ziel); ladePlan(true).then(render); } });

function viewMehr() {
  const p = zustand.profil || {};
  return `<div class="karte"><div class="titel" style="font-weight:700;font-size:19px">${e(p.name || '')}</div><div class="klein">${t('personalnummer')} ${e(p.personalnummer || '')}${p.betrieb ? ' · ' + e(p.betrieb.name) : ''}</div></div>
    <button class="knopf ${p.anfragen > 0 ? '' : 'zweit'}" data-a="nav2" data-z="anfragen">${t('anfragen')}${p.anfragen > 0 ? ' (' + p.anfragen + ')' : ''}</button>
    <button class="knopf zweit" data-a="nav2" data-z="nachrichten">${t('nachrichten')}${p.ungelesen > 0 ? ' ' + t('neu_n', { n: p.ungelesen }) : ''}</button>
    ${pushMoeglich() ? `<div class="karte" style="margin:14px 0"><div class="titel" style="font-size:15px">${t('push_titel')}</div><div class="sub" style="margin:4px 0 10px">${localStorage.getItem('push_status') === 'aktiv' ? t('push_aktiv') : iosOhneInstallation() ? t('push_ios') : t('push_aus')}</div>${localStorage.getItem('push_status') === 'aktiv' ? `<button class="knopf zweit klein" data-a="push_aus" style="margin:0">${t('push_abschalten')}</button>` : iosOhneInstallation() ? '' : `<button class="knopf klein" data-a="push_an" style="margin:0">${t('push_einschalten')}</button>`}</div>` : ''}
    <div class="karte" style="margin:14px 0"><div class="titel" style="font-size:15px">${t('kalender_titel')}</div><div class="sub" style="margin:4px 0 10px">${t('kalender_text')}</div><button class="knopf zweit klein" data-a="kalender" style="margin:0">${t('kalender_link')}</button><div id="kalender-link" class="sub" style="margin-top:8px;word-break:break-all"></div></div>
    <button class="knopf zweit" data-a="nav2" data-z="pin">${t('pin_aendern')}</button>
    ${p.betrieb && p.betrieb.telefon ? `<a class="knopf zweit" href="tel:${e(p.betrieb.telefon.replace(/\s/g, ''))}">${t('buero_anrufen')} · ${e(p.betrieb.telefon)}</a>` : ''}
    <label style="margin-top:14px">${t('sprache')}<select id="sprache">${SPRACHEN.map((l) => `<option value="${l}" ${l === sprache ? 'selected' : ''}>${I18N.sprachen[l]}</option>`).join('')}</select></label>
    <button class="knopf zweit" data-a="neuladen">${t('plan_neu_laden')}</button>
    <button class="knopf zweit" data-a="abmelden" style="color:var(--rot)">${t('abmelden')}</button>
    <p class="klein mittig" style="margin-top:24px">${t('keine_ortung')}</p>`;
}
function viewNachrichten() { return `<div id="nachr"><div class="laden"><div class="kreis"></div></div></div>`; }
function viewPin() {
  return `<div id="meldung"></div><form id="pinform" class="karte"><label for="alt">${t('pin_alt')}</label><input id="alt" type="password" inputmode="numeric" maxlength="4" class="pin" required><label for="neu">${t('pin_neu')}</label><input id="neu" type="password" inputmode="numeric" maxlength="4" pattern="\\d{4}" class="pin" required><label for="neu2">${t('pin_neu2')}</label><input id="neu2" type="password" inputmode="numeric" maxlength="4" class="pin" required><button class="knopf" type="submit">${t('pin_aendern')}</button></form>`;
}

// ---------------------------------------------------------------------
// Ereignisse je Ansicht
// ---------------------------------------------------------------------
function meldung(html) { const m = app.querySelector('#meldung'); if (m) m.innerHTML = html; }

function bindeAnsicht(a) {
  app.querySelectorAll('[data-a=einsatz]').forEach((k) => (k.onclick = () => setzeAnsicht('einsatz', k.dataset.id)));
  app.querySelectorAll('[data-a=nav2]').forEach((k) => (k.onclick = () => setzeAnsicht(k.dataset.z)));
  const scanBtn = app.querySelector('[data-a=scan]'); if (scanBtn) scanBtn.onclick = () => scanner(t('scan_titel'), qrFluss);
  const spr = app.querySelector('#sprache'); if (spr) spr.onchange = (ev) => sprachWechsel(ev.target.value);
  const ab = app.querySelector('[data-a=abmelden]'); if (ab) ab.onclick = () => { if (confirm(t('abmelden_frage'))) abmelden(); };
  const nl = app.querySelector('[data-a=neuladen]'); if (nl) nl.onclick = async () => { nl.disabled = true; await sync(); await ladePlan(); render(); };

  if (a === 'einsatz') {
    const x = einsatz(zustand.einsatzId); if (!x) return;
    ladeFotos(x.id);
    const ci = app.querySelector('[data-a=checkin]'); if (ci) ci.onclick = () => scanner(t('scan_titel'), async (token) => {
      try { const wie = await checkin(x.id, token); render(); meldung(`<div class="erfolg">${t('eingestempelt')}${wie === 'offline' ? t('wird_uebertragen') : ''}.</div>`); }
      catch (err) { render(); meldung(`<div class="fehler">${e(err.message)}</div>`); }
    });
    const co = app.querySelector('[data-a=checkout]'); if (co) co.onclick = async () => {
      const regel = (zustand.profil && zustand.profil.foto_regel) || 'hinweis'; const nachher = Number(x.fotos_nachher) || 0;
      if (nachher === 0 && regel === 'pflicht') { meldung(`<div class="fehler">${t('foto_pflicht')}</div>`); app.querySelector('.fotoblock[data-typ=nachher]').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
      if (nachher === 0 && regel === 'hinweis' && !confirm(t('foto_frage'))) { app.querySelector('.fotoblock[data-typ=nachher]').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
      const p = prompt(t('pause_frage'), '0'); if (p === null) return; const pause = Math.max(0, parseInt(p, 10) || 0);
      co.disabled = true;
      try { const wie = await checkout(x.id, pause); render(); meldung(`<div class="erfolg">${t('ausgestempelt')}${wie === 'offline' ? t('wird_uebertragen') : ''}.</div>`); }
      catch (err) { render(); meldung(`<div class="fehler">${e(err.message)}</div>`); }
    };
    app.querySelectorAll('.fotoknopf input').forEach((inp) => (inp.onchange = async () => {
      const f = inp.files[0]; if (!f) return; const lbl = inp.closest('label'); lbl.querySelector('span').textContent = t('wird_verarbeitet');
      try { const wie = await fotoHochladen(x.id, inp.dataset.typ, f); if (wie === 'offline') { x['fotos_' + inp.dataset.typ] = (Number(x['fotos_' + inp.dataset.typ]) || 0) + 1; x.fotos = (Number(x.fotos) || 0) + 1; lokaleFotos.push({ einsatz_id: x.id, typ: inp.dataset.typ, url: URL.createObjectURL(f) }); } render(); meldung(`<div class="erfolg">${wie === 'offline' ? t('foto_gespeichert') : t('foto_uebertragen')}</div>`); }
      catch (err) { render(); meldung(`<div class="fehler">${e(err.message)}</div>`); }
    }));
    const nb = app.querySelector('[data-a=notiz]'); if (nb) nb.onclick = async () => {
      const text = app.querySelector('#notiz').value; x.notizen_mitarbeiter = text; localStorage.setItem('plan', JSON.stringify(zustand.plan));
      try { if (navigator.onLine) await api(`app/einsatz/${x.id}/notiz`, { method: 'POST', body: { text } }); else await queueAdd({ typ: 'notiz', einsatz_id: x.id, text, zeit: jetztIso() }); meldung('<div class="erfolg">' + t('notiz_gespeichert') + '</div>'); }
      catch (err) { meldung(`<div class="fehler">${e(err.message)}</div>`); }
    };
  }

  if (a === 'zeiten') ladeZeiten();
  if (a === 'abwesenheit') ladeAbwesenheit();
  if (a === 'nachrichten') ladeNachrichten();
  const pa = app.querySelector('[data-a=push_an]'); if (pa) pa.onclick = async () => { pa.disabled = true; const ok = await pushAnmelden(); render(); if (ok) meldung(`<div class="erfolg">${t('push_ok')}</div>`); };
  const px = app.querySelector('[data-a=push_aus]'); if (px) px.onclick = async () => { await pushAbmelden(); render(); };
  const ka = app.querySelector('[data-a=kalender]'); if (ka) ka.onclick = async () => { try { const d = await api('app/kalender/link'); const ziel = app.querySelector('#kalender-link'); ziel.innerHTML = `<a href="${e(d.url.replace(/^https?:/, 'webcal:'))}">${t('kalender_abonnieren')}</a><br><span style="user-select:all">${e(d.url)}</span>`; } catch (err) { meldung(`<div class="fehler">${e(err.message)}</div>`); } };
  if (a === 'anfragen') ladeAnfragen();
  if (a === 'pin') {
    app.querySelector('#pinform').onsubmit = async (ev) => {
      ev.preventDefault(); const f = ev.target;
      if (f.neu.value !== f.neu2.value) return meldung('<div class="fehler">' + t('pin_ungleich') + '</div>');
      try { await api('app/pin', { method: 'POST', body: { alt: f.alt.value, neu: f.neu.value } }); meldung('<div class="erfolg">' + t('pin_geaendert') + '</div>'); f.reset(); }
      catch (err) { meldung(`<div class="fehler">${e(err.message)}</div>`); }
    };
  }
}

async function ladeZeiten() {
  const ziel = app.querySelector('#zeiten'); if (!ziel) return;
  try {
    const d = await api('app/stundenkonto');
    const monate = d.monate.map((m) => `<div class="monat"><span>${String(m.monat).padStart(2, '0')}/${m.jahr}<div class="klein">${m.anzahl_einsaetze} ${t('einsatz_n')}</div></span><b>${minutenText(Number(m.minuten_netto))}</b></div>`).join('') || '<p class="leer">' + t('keine_zeiten') + '</p>';
    const zeiten = d.zeiten.map((z) => `<div class="zeitzeile"><b>${z.checkin_zeit.substring(8, 10)}.${z.checkin_zeit.substring(5, 7)}.</b><div>${e(z.objekt)}<div class="klein">${uhr(z.checkin_zeit)}–${z.checkout_zeit ? uhr(z.checkout_zeit) : '…'}${z.pause_minuten > 0 ? ' · ' + z.pause_minuten + ' ' + t('min_pause') : ''}${z.checkin_methode === 'manuell' ? ' · ' + t('nachgetragen') : ''}</div></div><span>${z.minuten_netto !== null ? minutenText(Number(z.minuten_netto)) : ''}</span></div>`).join('') || '';
    ziel.innerHTML = `<div class="karte">${monate}</div>${zeiten ? `<h2>${t('letzte_einsaetze')}</h2><div class="karte">${zeiten}</div>` : ''}<p class="klein">${t('zeiten_hinweis')}</p>`;
  } catch (err) { ziel.innerHTML = `<p class="leer">${navigator.onLine ? e(err.message) : t('zeiten_offline')}</p>`; }
}

async function ladeAbwesenheit() {
  const ziel = app.querySelector('#abw'); if (!ziel) return;
  const typ = { urlaub: t('typ_urlaub'), krank: t('typ_krank'), zeitausgleich: t('typ_zeitausgleich'), einschulung: t('typ_einschulung'), sonstiges: t('typ_sonstiges') };
  const st = { beantragt: '<span class="marke lauf">' + t('st_beantragt') + '</span>', genehmigt: '<span class="marke ok">' + t('st_genehmigt') + '</span>', abgelehnt: '<span class="marke rot">' + t('st_abgelehnt') + '</span>' };
  const form = `<div id="meldung"></div><form id="abwform" class="karte"><label for="typ">${t('was')}</label><select id="typ"><option value="urlaub">${t('typ_urlaub')}</option><option value="krank">${t('typ_krankmeldung')}</option><option value="zeitausgleich">${t('typ_zeitausgleich')}</option><option value="sonstiges">${t('typ_sonstiges')}</option></select>
    <div class="zeile"><div><label for="von">${t('von')}</label><input id="von" type="date" value="${heute()}" required></div><div><label for="bis">${t('bis_datum')}</label><input id="bis" type="date" value="${heute()}"></div></div>
    <p class="klein" style="margin-top:10px">${t('krank_hinweis')}</p><button class="knopf" type="submit">${t('absenden')}</button></form>`;
  ziel.innerHTML = form;
  try {
    const d = await api('app/abwesenheiten');
    const liste = d.liste.map((x) => `<div class="zeitzeile" style="grid-template-columns:1fr auto"><div><b>${typ[x.typ] || x.typ}</b><div class="klein">${datumDe(x.datum_von)}${x.datum_bis !== x.datum_von ? ' – ' + datumDe(x.datum_bis) : ''} · ${Number(x.tage).toLocaleString('de-AT')} ${Number(x.tage) === 1 ? t('tag_1') : t('tag_n')}${x.kommentar_office ? ' · ' + e(x.kommentar_office) : ''}</div></div>${st[x.status] || ''}</div>`).join('');
    ziel.innerHTML = `<div class="karte mittig"><div class="klein">${t('urlaub_offen', { j: new Date().getFullYear() })}</div><div class="gross">${(d.urlaub_anspruch - d.urlaub_genommen).toLocaleString('de-AT')}</div><div class="klein">${t('von_tagen', { n: d.urlaub_anspruch.toLocaleString('de-AT') })}</div></div>${form}${liste ? `<h2>${t('meine_antraege')}</h2><div class="karte">${liste}</div>` : ''}`;
  } catch (_) { if (!navigator.onLine) ziel.insertAdjacentHTML('afterbegin', '<p class="klein mittig">' + t('abw_offline') + '</p>'); }
  app.querySelector('#abwform').onsubmit = async (ev) => {
    ev.preventDefault(); const f = ev.target; const btn = f.querySelector('button'); btn.disabled = true;
    try { await api('app/abwesenheiten', { method: 'POST', body: { typ: f.typ.value, datum_von: f.von.value, datum_bis: f.bis.value || f.von.value } }); meldung('<div class="erfolg">' + t('gesendet') + '</div>'); setTimeout(ladeAbwesenheit, 800); }
    catch (err) { meldung(`<div class="fehler">${navigator.onLine ? e(err.message) : t('offline_antrag')}</div>`); btn.disabled = false; }
  };
}

function viewAnfragen() { return `<div id="meldung"></div><div id="anfr"><div class="laden"><div class="kreis"></div></div></div>`; }
async function ladeAnfragen() {
  const ziel = app.querySelector('#anfr'); if (!ziel) return;
  try {
    const d = await api('app/anfragen');
    if (!d.length) { ziel.innerHTML = `<p class="leer">${t('keine_anfragen')}</p>`; return; }
    ziel.innerHTML = d.map((x) => `<div class="karte anfrage ${x.status === 'offen' ? 'offen' : ''}"><div class="einsatz" style="grid-template-columns:64px 1fr"><div class="zeit">${zeit(x.uhrzeit_von)}<small>${t('bis')} ${zeit(x.uhrzeit_bis)}</small></div><div><div class="titel">${e(x.objekt_name)}</div><div class="sub">${x.datum === heute() ? t('heute') : wochentag(x.datum) + ', ' + datumDe(x.datum)} · ${e(x.leistung)} · ${x.dauer_soll_minuten} ${t('minuten')}</div><div class="sub">${e(x.adresse)}, ${e(x.plz)}</div>${x.nachricht ? `<div class="hinweis" style="margin-top:8px">${e(x.nachricht)}</div>` : ''}</div></div>
      ${x.status === 'offen' ? `<div class="zeile" style="margin-top:12px"><button class="knopf klein" data-ja="${x.id}" style="margin:0">${t('kann_ich')}</button><button class="knopf zweit klein" data-nein="${x.id}" style="margin:0">${t('kann_nicht')}</button></div>` : `<div style="margin-top:10px"><span class="marke ${x.status === 'zugesagt' ? 'ok' : x.status === 'abgesagt' ? 'rot' : 'aus'}">${t('st_' + x.status)}</span></div>`}</div>`).join('');
    ziel.querySelectorAll('[data-ja],[data-nein]').forEach((b) => (b.onclick = async () => {
      const ja = b.hasAttribute('data-ja'); const id = ja ? b.dataset.ja : b.dataset.nein;
      const text = ja ? '' : (prompt(t('absage_grund_frage'), '') || '');
      b.disabled = true;
      try { await api('app/anfragen/' + id + '/antwort', { method: 'POST', body: { zusage: ja ? 1 : 0, antwort: text } }); await ladePlan(true); render(); meldung(`<div class="erfolg">${ja ? t('zusage_ok') : t('absage_ok')}</div>`); }
      catch (err) { render(); meldung(`<div class="fehler">${e(err.message)}</div>`); }
    }));
  } catch (err) { ziel.innerHTML = `<p class="leer">${navigator.onLine ? e(err.message) : t('offline_nicht')}</p>`; }
}

async function ladeNachrichten() {
  const ziel = app.querySelector('#nachr'); if (!ziel) return;
  try {
    const d = await api('app/nachrichten');
    ziel.innerHTML = d.length ? d.map((n) => `<div class="karte nachricht ${n.gelesen_am ? '' : 'neu'}"><div class="meta">${datumDe(n.erstellt_am.substring(0, 10))} ${uhr(n.erstellt_am)}</div><b>${e(n.titel)}</b>${n.nachricht ? `<div style="margin-top:4px;white-space:pre-line">${e(n.nachricht)}</div>` : ''}</div>`).join('') : '<p class="leer">' + t('keine_nachrichten') + '</p>';
    if (d.some((n) => !n.gelesen_am)) { await api('app/nachrichten/gelesen', { method: 'POST' }); if (zustand.profil) { zustand.profil.ungelesen = 0; localStorage.setItem('profil', JSON.stringify(zustand.profil)); } }
  } catch (err) { ziel.innerHTML = `<p class="leer">${navigator.onLine ? e(err.message) : t('offline_nicht')}</p>`; }
}

// ---------------------------------------------------------------------
// QR-Ablauf: Code → Objekt → passender Einsatz → ein- oder ausstempeln
// ---------------------------------------------------------------------
async function qrFluss(token) {
  zustand.qrToken = null; history.replaceState(null, '', '/app/');
  let einsaetze = [], objektName = '';
  if (navigator.onLine) {
    try { const d = await api('app/objekt/' + token); einsaetze = d.einsaetze; objektName = d.objekt.name; }
    catch (err) { setzeAnsicht('plan'); meldungOben(`<div class="fehler">${e(err.message)}</div>`); return; }
  } else {
    // Offline: Einsätze aus dem Plan nach Objekt-Token können wir nicht zuordnen — Auswahl anbieten
    einsaetze = zustand.plan.filter((x) => x.datum === heute() && !x.checkout_zeit);
  }
  const h = heute();
  const kandidaten = einsaetze.filter((x) => !x.checkout_zeit).sort((a, b) => (a.datum + a.uhrzeit_von).localeCompare(b.datum + b.uhrzeit_von));
  const offen = kandidaten.find((x) => x.checkin_zeit && !x.checkout_zeit);
  const naechster = kandidaten.find((x) => !x.checkin_zeit && x.datum === h) || kandidaten.find((x) => !x.checkin_zeit);
  if (offen) {
    setzeAnsicht('einsatz', offen.id);
    const p = prompt(t('pause_frage_aus'), '0'); if (p === null) return;
    try { const wie = await checkout(offen.id, Math.max(0, parseInt(p, 10) || 0)); render(); meldung(`<div class="erfolg">${t('ausgestempelt')}${wie === 'offline' ? t('wird_uebertragen') : ''}.</div>`); } catch (err) { render(); meldung(`<div class="fehler">${e(err.message)}</div>`); }
    return;
  }
  if (naechster) {
    try { const wie = await checkin(naechster.id, token); setzeAnsicht('einsatz', naechster.id); meldung(`<div class="erfolg">${t('eingestempelt')}${objektName ? ' ' + t('bei') + ' ' + e(objektName) : ''}${wie === 'offline' ? t('wird_uebertragen') : ''}.</div>`); }
    catch (err) { setzeAnsicht('einsatz', naechster.id); meldung(`<div class="fehler">${e(err.message)}</div>`); }
    return;
  }
  setzeAnsicht('plan'); meldungOben(`<div class="fehler">${objektName ? t('kein_einsatz_fuer', { o: e(objektName) }) : t('kein_passender')} ${t('im_buero_melden')}</div>`);
}
function meldungOben(html) { const m = app.querySelector('main'); if (m) m.insertAdjacentHTML('afterbegin', html); }

// ---------------------------------------------------------------------
// Start
// ---------------------------------------------------------------------
(async function start() {
  const params = new URLSearchParams(location.search); const c = params.get('c');
  // Direktzugang vom Anbieter-Dashboard: Einmal-Token gegen App-Token tauschen
  const dk = params.get('direkt');
  if (dk && /^[a-f0-9]{40}$/.test(dk)) {
    try { const r = await fetch('/api/app/direkt', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token: dk }) }); const j = await r.json(); const d = j.data || j; if (d && d.token) { localStorage.setItem('token', d.token); zustand.token = d.token; } } catch (e) {}
    history.replaceState(null, '', '/app/');
  }
  if (c && /^[a-f0-9]{32}$/i.test(c)) zustand.qrToken = c.toLowerCase();
  if (params.get('z') && ['plan', 'anfragen', 'nachrichten'].includes(params.get('z'))) { zustand.ansicht = params.get('z'); history.replaceState(null, '', '/app/'); }
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/app/sw.js').catch(() => {});
  aktualisiereOfflineAnzeige();
  if (zustand.token) {
    render();
    await ladePlan(true); await sync();
    if (zustand.qrToken) return qrFluss(zustand.qrToken);
    render();
  } else {
    render();
  }
})();
