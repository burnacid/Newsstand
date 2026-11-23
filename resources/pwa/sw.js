// Newsstand service worker. Served from /sw.js by PwaController, which fills in the release version so each
// release gets fresh caches.
//
// It also shows push notifications (see the push handlers below).
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

// Push notifications (alerts sent to a "Push notification" channel). The payload comes from PushService.
self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { body: event.data?.text() ?? '' };
    }

    event.waitUntil(self.registration.showNotification(data.title || 'Newsstand', {
        body: data.body || '',
        icon: '/brand/icon-192.png',
        tag: data.tag,
        data: { url: data.url || '/reader' },
        // Critical alerts (actively exploited) stay until dismissed.
        requireInteraction: data.level === 'critical',
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = new URL(event.notification.data?.url || '/reader', self.location.origin);

    event.waitUntil((async () => {
        // Newsstand pages open in an existing Newsstand window; stories (other sites) in a new one.
        if (url.origin === self.location.origin) {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const open = windows.find((client) => new URL(client.url).origin === self.location.origin);

            if (open) {
                await open.focus();

                return open.navigate(url.href);
            }
        }

        return self.clients.openWindow(url.href);
    })());
});

const isStaticAsset =(url) => url.pathname.startsWith('/build/') || url.pathname.startsWith('/brand/');

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
