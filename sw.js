const CACHE = 'fzl-shell-v1';

// Pre-cache only truly static assets
const PRECACHE = [
    '/favicon.svg',
    '/icon.php?size=192',
    '/icon.php?size=512',
];

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE).then(c => c.addAll(PRECACHE))
    );
    self.skipWaiting();
});

self.addEventListener('activate', e => {
    // Remove old caches
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        ).then(() => clients.claim())
    );
});

self.addEventListener('fetch', e => {
    const url = new URL(e.request.url);

    // Never intercept non-GET or PHP pages — always hit the network
    if (e.request.method !== 'GET') return;
    if (url.pathname.endsWith('.php') || url.pathname === '/') return;

    // Cache-first for static assets (CSS/JS/fonts/images from CDN or local)
    e.respondWith(
        caches.match(e.request).then(cached => {
            if (cached) return cached;
            return fetch(e.request).then(res => {
                if (res && res.status === 200 && res.type !== 'opaque') {
                    const clone = res.clone();
                    caches.open(CACHE).then(c => c.put(e.request, clone));
                }
                return res;
            });
        })
    );
});
