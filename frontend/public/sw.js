// Self-uninstalling service worker.
//
// Earlier versions (v3, v4, v5) cached the app shell + assets aggressively
// for offline use, but the cache-vs-deploy edge cases (stale chunks after
// build, stuck workers serving old HTML, locked PHP-FPM SSE backlogs) ended
// up hurting users more than the offline benefit helped. So we turn it off
// at the source: every existing client that still has a SW registered will
// auto-update to this kill-switch on their next visit, then unregister
// itself + purge its caches, and on the visit after that the browser will
// no longer route through any SW.
//
// Restoring offline behavior in the future means writing a new SW file,
// not editing this one (browsers compare byte-for-byte to detect updates).

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    try {
      const keys = await caches.keys();
      await Promise.all(keys.map((k) => caches.delete(k)));
    } catch (_) {}
    try {
      await self.registration.unregister();
    } catch (_) {}
    // Reload every client so they pick up the un-SW'd response from the
    // network on next request. Without this they keep the dead SW reference
    // for the rest of the tab's life and don't actually escape its bugs.
    const clients = await self.clients.matchAll({ type: 'window' });
    for (const c of clients) {
      try { c.navigate(c.url); } catch (_) {}
    }
  })());
});

// Pass everything through to the network — no caching, no interception.
self.addEventListener('fetch', () => {});
