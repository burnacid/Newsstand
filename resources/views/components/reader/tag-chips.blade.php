@props([
    'tags' => collect(),
])

@if (collect($tags)->isNotEmpty())
    <span {{ $attributes->class('flex flex-wrap items-center gap-1') }}>
        @foreach ($tags as $tag)
            <a href="{{ route('reader', ['s' => 'tag:'.$tag->id]) }}" wire:navigate x-on:click.stop>
                <flux:badge size="sm" :color="$tag->color" :icon="($tag->team_id ?? null) ? 'user-group' : null"
                    :title="($tag->team_id ?? null) ? __('Team tag') : null">#{{ $tag->name }}</flux:badge>
            </a>
        @endforeach
    </span>
@endif
