// Service worker: makes TG-BASICS installable as an app and shows a branded offline page.
// It never caches client/policy data — only static files (CSS, JS, images) are cached, for speed.
const VERSION = "v2"; // bump this whenever sw.js changes, so old caches get cleared
const STATIC_CACHE = "tg-static-" + VERSION;
const OFFLINE_URL = "offline.html";
const PRECACHE = [OFFLINE_URL];
const MAX_STATIC_ENTRIES = 150;

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(STATIC_CACHE)
      .then((c) => c.addAll(PRECACHE))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((k) => k.startsWith("tg-") && k !== STATIC_CACHE)
            .map((k) => caches.delete(k)),
        ),
      )
      .then(() => self.clients.claim()),
  );
});

const scopePath = new URL(self.registration.scope).pathname; // e.g. /TG-BASICS/

function isStaticAsset(url) {
  return (
    url.origin === self.location.origin &&
    url.pathname.startsWith(scopePath + "assets/")
  );
}

async function trimCache(cache) {
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - MAX_STATIC_ENTRIES; i++)
    await cache.delete(keys[i]);
}

self.addEventListener("fetch", (event) => {
  const req = event.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);

  // Pages always load fresh from the network; offline.html only shows if there's no connection.
  if (req.mode === "navigate") {
    event.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
    return;
  }

  // Static files: show the cached copy instantly, then quietly fetch a fresh one in the background.
  if (isStaticAsset(url)) {
    event.respondWith(
      caches.open(STATIC_CACHE).then(async (cache) => {
        const cached = await cache.match(req);
        const fresh = fetch(req)
          .then((res) => {
            if (res.ok)
              cache.put(req, res.clone()).then(() => trimCache(cache));
            return res;
          })
          .catch(() => cached);
        return cached || fresh;
      }),
    );
  }
  // Everything else (uploads, AJAX, external scripts) just goes straight to the network.
});
