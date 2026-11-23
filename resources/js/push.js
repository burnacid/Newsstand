/**
 * "Push notifications on this device" (Settings → Alerts): subscribes this browser with the server's public key
 * and registers it through the Livewire component ($wire.registerDevice / forgetDevice / knowsDevice).
 */
const toBytes = (base64url) => {
    const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
};

const sameKey = (subscription, key) => {
    const current = subscription.options?.applicationServerKey;

    if (!current) return true;

    const a = new Uint8Array(current);
    const b = toBytes(key);

    return a.length === b.length && a.every((byte, i) => byte === b[i]);
};

// navigator.serviceWorker.ready never settles when the worker failed to register.
const ready = () => Promise.race([
    navigator.serviceWorker.ready,
    new Promise((_, reject) => setTimeout(() => reject(new Error('The service worker is not running. Reload the page and try again.')), 8000)),
]);

export default (key) => ({
    supported: 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext && !!key,
    enabled: false,
    busy: false,
    denied: false,
    error: '',
    /** sha256 of this browser's endpoint, to mark it in the device list. */
    currentHash: null,

    async init() {
        if (!this.supported) return;

        this.denied = Notification.permission === 'denied';

        try {
            const subscription = await (await ready()).pushManager.getSubscription();

            if (!subscription) return;

            // Made for an older key pair, or removed on the server (from another device): start over.
            this.currentHash = sameKey(subscription, key) ? await this.$wire.knowsDevice(subscription.endpoint) : null;

            if (this.currentHash) {
                this.enabled = true;
            } else {
                await subscription.unsubscribe();
            }
        } catch {
            // Leave it off; the button shows the problem when used.
        }
    },

    async enable() {
        this.busy = true;
        this.error = '';

        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                this.denied = permission === 'denied';

                return;
            }

            const registration = await ready();
            let subscription = await registration.pushManager.getSubscription();

            if (subscription && !sameKey(subscription, key)) {
                await subscription.unsubscribe();
                subscription = null;
            }

            subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toBytes(key) });

            const { endpoint, keys } = subscription.toJSON();
            this.currentHash = await this.$wire.registerDevice(endpoint, keys.p256dh, keys.auth);
            this.enabled = !!this.currentHash;
        } catch (e) {
            this.error = e.message || String(e);
        } finally {
            this.busy = false;
        }
    },

    async disable() {
        this.busy = true;

        try {
            const subscription = await (await ready()).pushManager.getSubscription();

            if (subscription) {
                await this.$wire.forgetDevice(subscription.endpoint);
                await subscription.unsubscribe();
            }
        } catch (e) {
            this.error = e.message || String(e);
        } finally {
            this.enabled = false;
            this.currentHash = null;
            this.busy = false;
        }
    },

    /** Remove a device from the list; for this browser, turn push off here as well. */
    async remove(id, hash) {
        if (hash === this.currentHash) {
            await this.disable();
        } else {
            await this.$wire.removeDevice(id);
        }
    },
});
