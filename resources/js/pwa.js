// Registers the service worker so Newsstand can be installed as an app and shows an offline page without a
// connection. Service workers need a secure context (HTTPS or localhost).
//
// Also keeps an Alpine store, $store.pwa, for the "Install app" menu item and the "Newsstand was updated" bar:
//   canInstall  the browser offered to install (Chromium browsers; Safari installs via Share → Add to Home Screen)
//   updated     a new release's worker took over this page; reloading picks up the new version
const state = {
    canInstall: false,
    updated: false,
    standalone: window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true,
};

let installPrompt = null;

// Before Alpine starts, write to the plain object; after, through the reactive store.
const set = (key, value) => {
    const store = window.Alpine?.store('pwa');
    (store ?? state)[key] = value;
};

window.addEventListener('beforeinstallprompt', (event) => {
    // Offer it from the user menu instead of the browser's own mini-bar.
    event.preventDefault();
    installPrompt = event;
    set('canInstall', true);
});

window.addEventListener('appinstalled', () => {
    installPrompt = null;
    set('canInstall', false);
});

document.addEventListener('alpine:init', () => {
    window.Alpine.store('pwa', {
        ...state,

        async install() {
            if (!installPrompt) return;

            installPrompt.prompt();
            await installPrompt.userChoice.catch(() => null);
            installPrompt = null;
            this.canInstall = false;
        },

        reload() {
            window.location.reload();
        },

        dismissUpdate() {
            this.updated = false;
        },
    });
});

if ('serviceWorker' in navigator && window.isSecureContext) {
    // Only a worker replacing an earlier one means "updated"; the very first install also fires controllerchange.
    const hadController = !!navigator.serviceWorker.controller;

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (hadController) set('updated', true);
    });

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
}
