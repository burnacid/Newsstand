{{-- "Newsstand was updated": shown when a new release's service worker takes over an open page (see resources/js/pwa.js).
     Never reloads by itself, so nothing half-typed is lost. --}}
<div x-data x-show="$store.pwa?.updated" x-cloak x-transition.opacity role="status" data-test="pwa-update"
    class="fixed inset-x-4 bottom-4 z-50 mx-auto flex max-w-md items-center gap-3 rounded-xl border border-emerald-200 bg-white p-3 text-sm shadow-lg dark:border-emerald-900 dark:bg-zinc-900">
    <flux:icon.sparkles variant="mini" class="shrink-0 text-emerald-600 dark:text-emerald-400" />
    <span class="flex-1">{{ __('Newsstand was updated.') }}</span>
    <flux:button size="sm" variant="primary" x-on:click="$store.pwa.reload()" data-test="pwa-update-reload">{{ __('Reload') }}</flux:button>
    <flux:button size="sm" variant="ghost" icon="x-mark" :aria-label="__('Later')" :title="__('Later')" x-on:click="$store.pwa.dismissUpdate()" />
</div>
