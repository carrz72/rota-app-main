// Cache files for offline use
const CACHE_NAME = "rota-app-cache-v1";
const urlsToCache = [
    "/",
    "/index.php",
    "/css/styles.css",
    "/images/apple-touch-icon-192x192.png"
];

self.addEventListener("install", event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(urlsToCache);
        })
    );
});

self.addEventListener("fetch", event => {
    event.respondWith(
        caches.match(event.request).then(response => {
            return response || fetch(event.request);
        })
    );
});