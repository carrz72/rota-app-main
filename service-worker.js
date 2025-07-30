const CACHE_NAME = "rota-app-cache-v4";
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
    console.log('[ServiceWorker] Install');
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            console.log('[ServiceWorker] Caching app shell');
            return cache.addAll(urlsToCache).catch(error => {
                console.error("Failed to cache some files:", error);
            });
        })
    );
    self.skipWaiting();
});

self.addEventListener('fetch', event => {
    console.log('[ServiceWorker] Fetch', event.request.url);
    
    // Skip cross-origin requests
    if (!event.request.url.startsWith(self.location.origin)) {
        return;
    }

    // Skip requests that shouldn't be cached or handled by SW
    if (event.request.url.includes('/functions/') || 
        event.request.url.includes('logout.php') ||
        event.request.method !== 'GET') {
        return;
    }

    // For navigation requests within the app scope
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then(response => {
                    console.log('[ServiceWorker] Navigation fetch successful:', response.status);
                    // Clone the response since we might use it twice
                    const responseClone = response.clone();
                    
                    // If the response is successful, cache it and return it
                    if (response.ok) {
                        caches.open(CACHE_NAME).then(cache => {
                            cache.put(event.request, responseClone);
                        });
                        return response;
                    }
                    
                    // For error responses, try cache as fallback
                    return caches.match(event.request).then(cachedResponse => {
                        if (cachedResponse) {
                            console.log('[ServiceWorker] Serving from cache due to error');
                            return cachedResponse;
                        }
                        // If no cache available, return the error response
                        return response;
                    });
                })
                .catch(error => {
                    console.log('[ServiceWorker] Navigation fetch failed, trying cache:', error);
                    return caches.match(event.request).then(cachedResponse => {
                        if (cachedResponse) {
                            console.log('[ServiceWorker] Serving from cache after fetch failure');
                            return cachedResponse;
                        }
                        // Return a basic offline page if nothing else works
                        return new Response('<!DOCTYPE html><html><head><title>Offline</title></head><body><h1>You are offline</h1><p>Please check your connection and try again.</p></body></html>', {
                            headers: { 'Content-Type': 'text/html' }
                        });
                    });
                })
        );
    } else {
        // For non-navigation requests, use cache-first strategy
        event.respondWith(
            caches.match(event.request).then(response => {
                if (response) {
                    console.log('[ServiceWorker] Serving from cache:', event.request.url);
                    return response;
                }
                
                console.log('[ServiceWorker] Fetching from network:', event.request.url);
                return fetch(event.request).then(fetchResponse => {
                    // Cache successful responses
                    if (fetchResponse.ok) {
                        const responseClone = fetchResponse.clone();
                        caches.open(CACHE_NAME).then(cache => {
                            cache.put(event.request, responseClone);
                        });
                    }
                    return fetchResponse;
                }).catch(error => {
                    console.log('[ServiceWorker] Fetch failed for resource:', event.request.url, error);
                    // Return a basic response for failed resource requests
                    if (event.request.destination === 'image') {
                        return new Response('', { status: 200, statusText: 'OK' });
                    }
                    throw error;
                });
            })
        );
    }
});

self.addEventListener("activate", event => {
    console.log('[ServiceWorker] Activate');
    const cacheWhitelist = [CACHE_NAME];
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (!cacheWhitelist.includes(cacheName)) {
                        console.log('[ServiceWorker] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    return self.clients.claim();
});