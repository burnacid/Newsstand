/**
 * Reader page behaviour: keyboard navigation, (in the river) mark-as-read on scroll,
 * and a once-a-minute check for new stories.
 * Entry rows carry data-entry-id / data-url, river articles data-river-id / data-url;
 * the Livewire component owns all state.
 */
export default () => ({
    pending: new Set(),
    flushTimer: null,

    /** Stories that arrived since the list was loaded (shown as a "N new stories" bar). */
    newCount: 0,
    pollTimer: null,

    init() {
        // Don't lose a pending batch when navigating away.
        this.onLeave = () => this.flushSeen();
        document.addEventListener('livewire:navigating', this.onLeave);
        window.addEventListener('pagehide', this.onLeave);

        // Check for new stories every minute while the tab is visible, and right away when it comes back.
        this.pollTimer = setInterval(() => this.checkNew(), 60_000);
        this.onVisible = () => { if (!document.hidden) this.checkNew(); };
        document.addEventListener('visibilitychange', this.onVisible);
    },

    destroy() {
        // A new-stories check still in flight must not write to a torn-down component: Alpine would re-run the
        // bar's x-show without its scope and log "newCount is not defined".
        this.destroyed = true;
        clearInterval(this.pollTimer);
        this.flushSeen();
        document.removeEventListener('livewire:navigating', this.onLeave);
        window.removeEventListener('pagehide', this.onLeave);
        document.removeEventListener('visibilitychange', this.onVisible);
    },

    // --- New stories ---

    async checkNew() {
        if (document.hidden || this.checking || this.destroyed) return;

        this.checking = true;

        try {
            const count = await this.$wire.countNew();

            if (this.destroyed) return;

            // Sidebar unread counts follow along.
            if (count > this.newCount) window.Livewire.dispatch('unread-changed');

            this.newCount = count;
        } catch {
            // Offline or session gone: try again next minute.
        } finally {
            this.checking = false;
        }
    },

    async showNew() {
        this.newCount = 0;
        await this.$wire.showNew();

        if (this.destroyed) return;

        this.$refs.list?.scrollTo({ top: 0, behavior: 'smooth' });
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

        // Keys pressed inside an open menu or popover belong to it: Escape there closes the menu, not the story.
        // (Closing the story at the same moment re-rendered the menu mid-close and threw a popover error.)
        // (Not the dropdown's trigger button: once the menu is closed, Escape there closes the story again.)
        if (target.closest?.('[popover], ui-menu, [data-flux-menu]')) {
            return;
        }

        // A menu can stay open with focus outside it (a toggle re-renders the story and focus lands on the trigger);
        // Escape then closes that menu first.
        // (Menus only: the toast area and tooltips are popovers too, and must not swallow shortcuts.)
        const openPopover = document.querySelector('ui-menu:popover-open, [data-flux-menu]:popover-open');

        if (openPopover) {
            if (event.key === 'Escape') {
                event.preventDefault();
                openPopover.hidePopover();
            }

            return;
        }

        // Enter on a focused button or link does what that control does (e.g. opens that story).
        if (event.key === 'Enter' && target.closest?.('button, a')) {
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
            Escape: () => {
                const id = this.$wire.entryId;
                this.$wire.closeEntry();
                // Back to the story's row, so keyboard users continue where they were.
                this.$root.querySelector(`[data-entry-id="${id}"] button`)?.focus({ preventScroll: true });
            },
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
        // Focus follows the selection (screen readers announce the story; Tab continues from here).
        el.querySelector('button')?.focus({ preventScroll: true });
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
