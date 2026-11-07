/* Rivsenn A.I. chat: service worker. Bewaart alleen de app-bestanden; chat-verzoeken
   en de gedeelde instellingen (chat-config.php) gaan altijd rechtstreeks naar het netwerk. */
const VERSION = 'rv-chat-v2';
const SHELL = ['./', 'index.html', 'manifest.webmanifest', 'icons/icon.svg', 'icons/icon-192.png', 'icons/icon-512.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const r = e.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.origin !== location.origin || /chat-(config|run)\.php$/.test(u.pathname)) return;
  if (r.mode === 'navigate') {
    // pagina: eerst netwerk (altijd de nieuwste versie), anders de bewaarde kopie
    e.respondWith(fetch(r).then(res => { const cp = res.clone(); caches.open(VERSION).then(c => c.put('index.html', cp)); return res; })
      .catch(() => caches.match('index.html')));
    return;
  }
  // overige bestanden: bewaarde kopie meteen, op de achtergrond verversen
  e.respondWith(caches.match(r).then(hit => {
    const net = fetch(r).then(res => { if (res.ok) { const cp = res.clone(); caches.open(VERSION).then(c => c.put(r, cp)); } return res; }).catch(() => hit);
    return hit || net;
  }));
});
