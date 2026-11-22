/**
 * Reader page behaviour: keyboard navigation and (in the river) mark-as-read on scroll.
 * Entry rows carry data-entry-id / data-url, river articles data-river-id / data-url;
 * the Livewire component owns all state.
 */
export default () => ({
    pending: new Set(),
    flushTimer: null,

    init() {
        // Don't lose a pending batch when navigating away.
        this.onLeave = () => this.flushSeen();
        document.addEventListener('livewire:navigating', this.onLeave);
        window.addEventListener('pagehide', this.onLeave);
    },

    destroy() {
        this.flushSeen();
        document.removeEventListener('livewire:navigating', this.onLeave);
        window.removeEventListener('pagehide', this.onLeave);
    },

    isRiver() {
        return this.$root.dataset.layout === 'river';
    },

    // --- River: mark as read on scroll ---

    /** Scroll handler for the list area; checks at most once per frame. */
    riverScroll() {
        if (!this.isRiver() || this.scrollQueued) return;

        this.scrollQueued = true;
        requestAnimationFrame(() => {
            this.scrollQueued = false;
            this.markPassed();
        });
    },

    /**
     * Mark every unread article whose top has scrolled past the top of the
     * scroll area. The river scrolls inside a container below the list header,
     * so we compare against that container, not the window. Checking positions
     * (instead of waiting for "left the viewport" events) means fast scrolling
     * or dragging the scrollbar can't skip articles.
     */
    markPassed() {
        const top = this.$refs.list.getBoundingClientRect().top;

        for (const el of this.riverItems()) {
            // Articles are in order: once one hasn't passed, none below it have.
            if (el.getBoundingClientRect().top >= top - 1) break;

            const state = window.Alpine.$data(el);

            if (!state.read) {
                state.read = true;
                this.seen(Number(el.dataset.riverId));
            }
        }
    },

    seen(id) {
        this.pending.add(id);
        clearTimeout(this.flushTimer);
        this.flushTimer = setTimeout(() => this.flushSeen(), 700);
    },

    flushSeen() {
        clearTimeout(this.flushTimer);

        if (this.pending.size === 0) return;

        const ids = [...this.pending];
        this.pending.clear();
        this.$wire.markSeen(ids);
    },

    riverItems() {
        return [...this.$root.querySelectorAll('[data-river-id]')];
    },

    /** The article whose top is nearest the top of the scroll area. */
    riverCurrent() {
        const top = this.$refs.list.getBoundingClientRect().top;
        const items = this.riverItems();
        let index = items.findIndex((el) => el.getBoundingClientRect().bottom > top + 80);

        return { items, index: index < 0 ? items.length - 1 : index };
    },

    riverMove(delta) {
        const { items, index } = this.riverCurrent();
        const target = items[Math.min(Math.max(index + delta, 0), items.length - 1)];

        target?.scrollIntoView({ block: 'start', behavior: 'smooth' });

        if (index + delta >= items.length - 3) this.$wire.loadMore();
    },

    currentId() {
        if (!this.isRiver()) return this.$wire.entryId;

        const { items, index } = this.riverCurrent();

        return items[index] ? Number(items[index].dataset.riverId) : null;
    },

    // --- Keyboard ---

    onKey(event) {
        const target = event.target;
        const typing = target.closest?.('input, textarea, select, [contenteditable]');

        if (typing) {
            if (event.key === 'Escape') target.blur();
            return;
        }

        if (event.metaKey || event.ctrlKey || event.altKey || document.querySelector('dialog[open]')) {
            return;
        }

        const river = this.isRiver();
        const withCurrent = (fn) => () => {
            const id = this.currentId();
            if (id) fn(id);
        };

        const actions = {
            j: () => (river ? this.riverMove(1) : this.move(1)),
            k: () => (river ? this.riverMove(-1) : this.move(-1)),
            o: () => (river || this.$wire.entryId ? null : this.move(1)),
            Enter: () => (river || this.$wire.entryId ? null : this.move(1)),
            v: () => this.openOriginal(),
            s: withCurrent((id) => this.$wire.toggleStar(id)),
            l: withCurrent((id) => this.$wire.toggleLater(id)),
            m: withCurrent((id) => this.$wire.toggleRead(id)),
            t: () => this.openTagMenu(),
            u: () => this.$wire.set('show', this.$wire.show === 'all' ? 'unread' : 'all'),
            A: () => {
                if (confirm('Mark every story here as read?')) this.$wire.markAllRead();
            },
            '/': () => this.$refs.search?.querySelector('input')?.focus() ?? this.$refs.search?.focus(),
            Escape: () => this.$wire.closeEntry(),
            '?': () => this.$flux.modal('shortcuts').show(),
        };

        const action = actions[event.key];

        if (action) {
            event.preventDefault();
            action();
        }
    },

    // --- List / cards ---

    items() {
        return [...this.$root.querySelectorAll('[data-entry-id]')];
    },

    move(delta) {
        const items = this.items();

        if (items.length === 0) return;

        const current = items.findIndex((el) => Number(el.dataset.entryId) === this.$wire.entryId);
        const index = current < 0 ? 0 : Math.min(Math.max(current + delta, 0), items.length - 1);

        if (index === current) return;

        const el = items[index];
        el.scrollIntoView({ block: 'nearest' });
        this.$refs.pane?.scrollTo({ top: 0 });
        this.$wire.select(Number(el.dataset.entryId));

        // Prefetch more when getting close to the end of the list.
        if (index >= items.length - 5) this.$wire.loadMore();
    },

    openTagMenu() {
        let scope = this.$refs.pane?.parentElement;

        if (this.isRiver()) {
            const { items, index } = this.riverCurrent();
            scope = items[index];
        }

        scope?.querySelector('[data-tag-menu]')?.click();
    },

    openOriginal() {
        const id = this.currentId();
        const el = this.$root.querySelector(`[data-entry-id="${id}"], [data-river-id="${id}"]`);

        if (el?.dataset.url) window.open(el.dataset.url, '_blank', 'noopener,noreferrer');
    },
});
