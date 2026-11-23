@props([
    'entryId',
    // The user's own tags, then their teams' (with the team loaded).
    'tags',
    // Tags currently on this entry.
    'selected' => collect(),
    'size' => 'sm',
])

@php($selectedIds = collect($selected)->pluck('id')->all())

{{-- Dropdown triggers use a native title, not a Flux tooltip: the tooltip pops up as focus returns to the button
     while the menu closes, and Flux then throws "Invalid to show a popover during another show operation". --}}
<flux:dropdown position="bottom" align="end">
    <flux:button :size="$size" variant="ghost" icon="tag" :aria-label="__('Tags')" :title="__('Tags') . ' (t)'" data-tag-menu />

    <flux:menu class="min-w-56">
        <div class="p-1" x-data="{ name: '' }">
            <flux:input size="sm" x-model="name" :placeholder="__('New tag…')"
                x-on:keydown.enter.prevent="if (name.trim()) { $wire.addTag(name, {{ $entryId }}); name = '' }"
                x-on:keydown.stop />
        </div>

        @if ($tags->isNotEmpty())
            <flux:menu.separator />
        @endif

        @foreach ($tags->groupBy(fn ($tag) => $tag->team?->name ?? '') as $teamName => $group)
            @if ($teamName !== '')
                <flux:menu.heading class="flex items-center gap-1.5">
                    <flux:icon.user-group variant="micro" /> {{ $teamName }}
                </flux:menu.heading>
            @endif
            @foreach ($group as $tag)
                <flux:menu.item wire:click="toggleTag({{ $tag->id }}, {{ $entryId }})" keep-open
                    :icon="in_array($tag->id, $selectedIds, true) ? 'check' : null" wire:key="tag-menu-{{ $entryId }}-{{ $tag->id }}">
                    <span class="flex items-center gap-2">
                        <span @class(['size-2 rounded-full', "bg-{$tag->color}-500"])></span>
                        {{ $tag->name }}
                    </span>
                </flux:menu.item>
            @endforeach
        @endforeach
    </flux:menu>
</flux:dropdown>
