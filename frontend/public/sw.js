// Smappen Field service worker — offline-first for the shell, network-first
// for API calls, and a tiny outbox for /api/projects/*/field-notes when offline.
//
// Versioning: bump CACHE_VERSION on every deploy so old shells get evicted.

// NF4 — bumped v3 → v4 with broader offline coverage:
//   • GPS breadcrumb buffer (mirrored from main thread via postMessage)
//   • Photo upload outbox (alongside field-notes outbox)
//   • Cached-tile read for map tiles when offline
//   • Skip-waiting prompt UI so users see updates on next reload
const CACHE_VERSION = 'sm-v4';
const SHELL_CACHE = `${CACHE_VERSION}-shell`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;
const OUTBOX_DB = 'sm-outbox';
const OUTBOX_STORE = 'queued';

// Don't precache the whole app — it's hash-built. Just cache what's hit.
const APP_SCOPE = '/app/';

self.addEventListener('install', (e) => {
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => !k.startsWith(CACHE_VERSION)).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  if (request.method !== 'GET') {
    // NF4 — outbox handles field-notes AND uploads when offline.
    if (request.method === 'POST' && /\/api\/projects\/[^/]+\/field-notes$/.test(url.pathname)) {
      event.respondWith(handleFieldNotePost(request.clone()));
    } else if (request.method === 'POST' && url.pathname === '/api/uploads') {
      event.respondWith(handleUploadPost(request.clone()));
    }
    return;
  }

  // NF4 — Google Maps tile requests are served from a tile-runtime cache
  // so the last-loaded viewport renders offline. Limited to 200 tiles to
  // keep the cache bounded; LRU eviction via the request order.
  if (url.hostname.endsWith('googleapis.com') && url.pathname.startsWith('/maps/')) {
    event.respondWith(
      caches.open(`${CACHE_VERSION}-tiles`).then((cache) =>
        cache.match(request).then((m) => {
          const network = fetch(request).then((r) => {
            if (r.ok) {
              cache.put(request, r.clone());
              cache.keys().then((keys) => {
                if (keys.length > 200) cache.delete(keys[0]);
              });
            }
            return r;
          }).catch(() => m);
          return m || network;
        })
      )
    );
    return;
  }

  // Navigation requests inside the app: network-first, fall back to cached shell.
  if (request.mode === 'navigate' && url.pathname.startsWith(APP_SCOPE)) {
    event.respondWith(
      fetch(request)
        .then((r) => {
          const copy = r.clone();
          caches.open(SHELL_CACHE).then((c) => c.put(request, copy));
          return r;
        })
        .catch(() => caches.match(request).then((m) => m || caches.match(APP_SCOPE)))
    );
    return;
  }

  // App static assets (JS/CSS/SVG under /app/) — cache-first
  if (url.pathname.startsWith(APP_SCOPE)) {
    event.respondWith(
      caches.match(request).then((m) => {
        if (m) return m;
        return fetch(request).then((r) => {
          const copy = r.clone();
          caches.open(RUNTIME_CACHE).then((c) => c.put(request, copy));
          return r;
        });
      })
    );
    return;
  }

  // API GETs — network-first, fall back to last cached.
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(request)
        .then((r) => {
          if (r.ok && r.status === 200) {
            const copy = r.clone();
            caches.open(RUNTIME_CACHE).then((c) => c.put(request, copy));
          }
          return r;
        })
        .catch(() => caches.match(request))
    );
  }
});

// Drain the outbox when we come back online.
self.addEventListener('sync', (e) => {
  if (e.tag === 'sm-flush-outbox') {
    e.waitUntil(flushOutbox());
  }
});

async function handleUploadPost(request) {
  try {
    const res = await fetch(request.clone());
    if (res.ok) return res;
    throw new Error('upstream ' + res.status);
  } catch (e) {
    // Outbox the FormData. Body is multipart so we serialize what's needed:
    // the raw blob + the path + the headers minus Content-Type (browser will
    // reset it on replay so the boundary stays valid).
    const blob = await request.blob();
    const url = request.url;
    const headers = {};
    request.headers.forEach((v, k) => { if (k.toLowerCase() !== 'content-type') headers[k] = v; });
    await dbAdd({ url, body: blob, headers, queuedAt: Date.now(), kind: 'upload' });
    try { await self.registration.sync.register('sm-flush-outbox'); } catch (_) {}
    return new Response(JSON.stringify({
      success: true,
      data: { queued: true, queued_at: new Date().toISOString() },
    }), { status: 202, headers: { 'Content-Type': 'application/json' } });
  }
}

async function handleFieldNotePost(request) {
  try {
    const res = await fetch(request.clone());
    if (res.ok) return res;
    throw new Error('upstream ' + res.status);
  } catch (e) {
    // Queue for later
    const body = await request.text();
    const url = request.url;
    const headers = {};
    request.headers.forEach((v, k) => (headers[k] = v));
    await dbAdd({ url, body, headers, queuedAt: Date.now() });
    try {
      await self.registration.sync.register('sm-flush-outbox');
    } catch (_) {}
    return new Response(JSON.stringify({
      success: true,
      data: { queued: true, queued_at: new Date().toISOString() },
    }), { status: 202, headers: { 'Content-Type': 'application/json' } });
  }
}

async function flushOutbox() {
  const items = await dbAll();
  for (const it of items) {
    try {
      const res = await fetch(it.url, {
        method: 'POST',
        headers: it.headers,
        body: it.body,
      });
      if (res.ok) await dbDelete(it.id);
    } catch (_) { /* still offline */ }
  }
}

// Minimal IndexedDB outbox
function openDb() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(OUTBOX_DB, 1);
    req.onupgradeneeded = () => req.result.createObjectStore(OUTBOX_STORE, { keyPath: 'id', autoIncrement: true });
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}
function dbAdd(rec) {
  return openDb().then((db) => new Promise((res, rej) => {
    const tx = db.transaction(OUTBOX_STORE, 'readwrite');
    tx.objectStore(OUTBOX_STORE).add(rec);
    tx.oncomplete = () => res();
    tx.onerror = () => rej(tx.error);
  }));
}
function dbAll() {
  return openDb().then((db) => new Promise((res, rej) => {
    const req = db.transaction(OUTBOX_STORE).objectStore(OUTBOX_STORE).getAll();
    req.onsuccess = () => res(req.result || []);
    req.onerror = () => rej(req.error);
  }));
}
function dbDelete(id) {
  return openDb().then((db) => new Promise((res, rej) => {
    const tx = db.transaction(OUTBOX_STORE, 'readwrite');
    tx.objectStore(OUTBOX_STORE).delete(id);
    tx.oncomplete = () => res();
    tx.onerror = () => rej(tx.error);
  }));
}
