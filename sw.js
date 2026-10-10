/* TG-BASICS service worker — makes the app installable and gives it a branded offline screen.
 *
 * What it deliberately does NOT do: cache pages or data. Signed-in pages carry client, policy and payment records
 * and are sent with Cache-Control: no-store (config/session.php) so a shared shop computer or phone can't show them
 * again after logout. Only static files (CSS, JS, images, fonts under assets/) are cached, and only for speed.
 * Bump VERSION whenever this file changes so old caches are dropped.
 */
const VERSION = "v1";
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

  // Pages: always from the network, never stored. Only when the network is gone does the offline screen show.
  if (req.mode === "navigate") {
    event.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
    return;
  }

  // Static files: answer from cache at once, refresh it in the background (stale-while-revalidate).
  // Every include carries ?v=<filemtime>, so a changed file has a new URL and is fetched fresh anyway.
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
  // Everything else (uploads, AJAX, other sites' CDNs) goes straight to the network, untouched.
});
