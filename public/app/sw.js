/* Service Worker: App-Hülle offline verfügbar halten. API-Aufrufe gehen immer ans Netz. */
const CACHE = 'einsaetze-v6';
const HUELLE = ['/app/', '/app/index.html', '/app/app.css', '/app/app.js', '/app/i18n.js', '/app/jsqr.js', '/app/manifest.webmanifest', '/app/icon.svg'];

self.addEventListener('install', (ev) => {
  ev.waitUntil(caches.open(CACHE).then((c) => c.addAll(HUELLE)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (ev) => {
  ev.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (ev) => {
  const url = new URL(ev.request.url);
  if (ev.request.method !== 'GET' || url.pathname.startsWith('/api/')) return;
  if (!url.pathname.startsWith('/app/')) return;
  // Hülle: Cache zuerst, dann Netz; Navigationen auf die Startseite fallen auf index.html zurück
  ev.respondWith(
    caches.match(ev.request, { ignoreSearch: true }).then((hit) => hit || fetch(ev.request).then((res) => {
      if (res.ok) { const kopie = res.clone(); caches.open(CACHE).then((c) => c.put(ev.request, kopie)); }
      return res;
    }).catch(() => (ev.request.mode === 'navigate' ? caches.match('/app/index.html') : Response.error())))
  );
});

// ---------------------------------------------------------------------
// Web-Push: Push ohne Nutzlast → Kurzmeldung vom Server holen → Anzeige
// ---------------------------------------------------------------------
self.addEventListener('push', (ev) => {
  ev.waitUntil((async () => {
    let titel = 'Takt', text = 'Neuigkeiten in der App.', ziel = 'plan';
    try {
      const sub = await self.registration.pushManager.getSubscription();
      if (sub) {
        const r = await fetch('/api/app/push/aktuell', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: sub.endpoint }) });
        const j = await r.json(); const d = j.data || j;
        if (d && d.titel) { titel = d.titel; text = d.text || ''; ziel = d.ziel || 'plan'; }
      }
    } catch (e) {}
    await self.registration.showNotification(titel, { body: text, icon: '/app/icon.svg', badge: '/app/icon.svg', tag: 'takt-' + ziel, renotify: true, data: { ziel } });
  })());
});
self.addEventListener('notificationclick', (ev) => {
  ev.notification.close();
  const ziel = (ev.notification.data && ev.notification.data.ziel) || 'plan';
  ev.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((ws) => {
    for (const w of ws) { if (w.url.includes('/app/')) { w.focus(); w.postMessage({ typ: 'nav', ziel }); return; } }
    return clients.openWindow('/app/?z=' + ziel);
  }));
});
