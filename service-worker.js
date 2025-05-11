const CACHE_NAME = "rota-app-cache-v2";
const urlsToCache = [
    "/rota-app-main/",
    "/rota-app-main/index.php",
    "/rota-app-main/css/styles.css",
    "/rota-app-main/images/logo.png",
    "/rota-app-main/fonts/CooperHewitt-Book.otf"
];

self.addEventListener("install", event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(urlsToCache).catch(error => {
                console.error("Failed to cache some files:", error);
            });
        })
    );
});

self.addEventListener('fetch', event => {
    // For navigation requests, try the network first, falling back to the cache
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(error => {
                console.log('Fetch failed; returning offline page instead.', error);
                return caches.match(event.request);
            })
        );
    } else {
        // For non-navigation requests, use the cache-first strategy
        event.respondWith(
            caches.match(event.request).then(response => {
                return response || fetch(event.request);
            })
        );
    }
});

self.addEventListener("activate", event => {
    const cacheWhitelist = [CACHE_NAME];
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (!cacheWhitelist.includes(cacheName)) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
});