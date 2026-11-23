// Newsstand service worker. Served from /sw.js by PwaController, which fills in the release version so each
// release gets fresh caches.
//
// Pages are never cached: they hold your private reading data, and signing out must leave nothing behind. Pages
// always come from the network, and when that fails you get the offline page. Only the fingerprinted build
// assets and brand images are cached, so the app shell starts fast.
const VERSION = '__NEWSSTAND_VERSION__';
const CACHE = `newsstand-static-${VERSION}`;
const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/favicon.svg', '/brand/newsstand-mark.svg', '/brand/newsstand-mark-dark.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(PRECACHE.map((url) => new Request(url, { cache: 'reload' }))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter((key) => key.startsWith('newsstand-') && key !== CACHE).map((key) => caches.delete(key)));

        if (self.registration.navigationPreload) {
            await self.registration.navigationPreload.enable();
        }

        await self.clients.claim();
    })());
});

const isStaticAsset = (url) => url.pathname.startsWith('/build/') || url.pathname.startsWith('/brand/');

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith((async () => {
            try {
                return (await event.preloadResponse) || (await fetch(request));
            } catch {
                return (await caches.match(OFFLINE_URL)) || Response.error();
            }
        })());

        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith((async () => {
            const cached = await caches.match(request);

            if (cached) {
                return cached;
            }

            const response = await fetch(request);

            if (response.ok && response.type === 'basic') {
                const copy = response.clone();
                event.waitUntil(caches.open(CACHE).then((cache) => cache.put(request, copy)));
            }

            return response;
        })());
    }
});
