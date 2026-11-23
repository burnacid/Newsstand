// Registers the service worker so Newsstand can be installed as an app and shows an offline page without a
// connection. Service workers need a secure context (HTTPS or localhost).
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
}
