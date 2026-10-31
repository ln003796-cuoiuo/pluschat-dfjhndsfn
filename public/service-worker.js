const CACHE_NAME = 'pluschat-shell-v2';
const SHELL_FILES = [
  './',
  './index.html',
  './styles.css',
  './app.js',
  './manifest.webmanifest',
  './icons/icon-192.png',
  './icons/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    await cache.addAll(SHELL_FILES);
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter((name) => name.startsWith('pluschat-shell-') && name !== CACHE_NAME).map((name) => caches.delete(name)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const requestUrl = new URL(request.url);
  const scopeUrl = new URL(self.registration.scope);
  if (requestUrl.origin !== scopeUrl.origin || !requestUrl.href.startsWith(scopeUrl.href)) return;

  const appShellUrl = new URL('./index.html', scopeUrl).href;
  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const response = await fetch(request);
        if (response.status >= 500) return (await caches.match(appShellUrl)) || response;
        return response;
      } catch {
        return (await caches.match(appShellUrl)) || Response.error();
      }
    })());
    return;
  }

  const shellUrl = SHELL_FILES.map((file) => new URL(file, scopeUrl).href);
  if (!shellUrl.includes(requestUrl.href)) return;

  event.respondWith((async () => {
    let response;
    try {
      response = await fetch(request);
    } catch {
      return (await caches.match(requestUrl.href)) || Response.error();
    }
    if (response.ok && response.type !== 'opaque') {
      try {
        const cache = await caches.open(CACHE_NAME);
        await cache.put(request, response.clone());
      } catch { /* Keep the network response even when the cache is unavailable. */ }
    }
    return response;
  })());
});
