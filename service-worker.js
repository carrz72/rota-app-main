const CACHE_NAME = "rota-app-cache-v3";
const urlsToCache = [
    "./",
    "./index.php",
    "./css/styles.css",
    "./css/navigation.css",
    "./images/icon.png",
    "./js/links.js",
    "./js/menu.js",
    "./fonts/CooperHewitt-Book.otf"
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
    // Skip cross-origin requests
    if (!event.request.url.startsWith(self.location.origin)) {
        return;
    }

    // For navigation requests within the app scope
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then(response => {
                    // If the response is successful, return it
                    if (response.status === 200) {
                        return response;
                    }
                    // If there's an error, try to serve from cache
                    return caches.match(event.request);
                })
                .catch(error => {
                    console.log('Fetch failed; serving from cache if available.', error);
                    return caches.match(event.request);
                })
        );
    } else {
        // For non-navigation requests, use cache-first strategy
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