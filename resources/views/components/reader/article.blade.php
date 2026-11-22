@props([
    'entry',
    // 'pane': single article in the reading pane. 'river': a preview in the scrolling river.
    'variant' => 'pane',
    // River only: show the full text instead of the preview.
    'expanded' => false,
])

@php($river = $variant === 'river')
@php($preview = $river && ! $expanded)

<article {{ $attributes->class(['mx-auto max-w-[44rem] px-6 sm:px-10', 'py-10 lg:py-14' => ! $river, 'py-8' => $river]) }}>
    <div class="flex items-center gap-3">
        <a href="{{ route('reader', ['s' => 'feed:'.$entry->feed_id]) }}" wire:navigate
            class="text-xs font-semibold tracking-[0.15em] text-emerald-700 uppercase hover:underline dark:text-emerald-400">
            {{ $entry->feed->displayTitle() }}
        </a>
        {{ $badges ?? '' }}
    </div>

    <h1 @class([
        'mt-3 font-serif leading-tight font-semibold tracking-tight text-balance text-zinc-900 dark:text-zinc-50',
        'text-3xl sm:text-4xl' => ! $river,
        'text-2xl sm:text-3xl' => $river,
    ])>
        @if ($entry->url)
            <a href="{{ $entry->url }}" target="_blank" rel="noopener noreferrer" class="hover:text-emerald-800 dark:hover:text-emerald-300">{{ $entry->title ?? __('Untitled') }}</a>
        @else
            {{ $entry->title ?? __('Untitled') }}
        @endif
    </h1>

    <div @class([
        'mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400',
        'border-b border-zinc-200 pb-6 dark:border-zinc-700' => ! $preview,
    ])>
        @if ($entry->author)
            <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $entry->author }}</span>
            <span aria-hidden="true">&middot;</span>
        @endif
        <time datetime="{{ $entry->published_at->toIso8601String() }}" title="{{ $entry->published_at->diffForHumans() }}">
            {{ $entry->published_at->isoFormat('D MMMM YYYY, HH:mm') }}
        </time>
        <span aria-hidden="true">&middot;</span>
        <span>{{ trans_choice(':count min read|:count min read', $entry->readingMinutes()) }}</span>
        {{ $actions ?? '' }}
    </div>

    @if ($preview)
        {{-- River preview: lead image and summary only. --}}
        @if ($entry->image_url)
            <img src="{{ $entry->image_url }}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"
                class="mt-6 aspect-[2/1] w-full rounded-lg bg-zinc-100 object-cover dark:bg-zinc-800">
        @endif

        @if ($entry->summary)
            <p class="mt-5 line-clamp-4 font-serif text-lg leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $entry->summary }}</p>
        @endif

        <div class="mt-5 flex flex-wrap items-center gap-2">
            @if ($entry->content || $entry->summary)
                <flux:button size="sm" wire:click="expand({{ $entry->id }})" icon:trailing="chevron-down">{{ __('Continue reading') }}</flux:button>
            @endif
            @if ($entry->url)
                <flux:button size="sm" variant="ghost" :href="$entry->url" target="_blank" rel="noopener noreferrer" icon:trailing="arrow-top-right-on-square">
                    {{ parse_url($entry->url, PHP_URL_HOST) ?: __('Original') }}
                </flux:button>
            @endif
        </div>
    @else
        @if ($entry->image_url && ! $entry->imageInContent())
            <img src="{{ $entry->image_url }}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"
                class="mt-8 w-full rounded-lg bg-zinc-100 object-cover dark:bg-zinc-800">
        @endif

        <div class="prose-article mt-8">
            @if ($entry->content)
                {!! $entry->content !!}
            @elseif ($entry->summary)
                <p>{{ $entry->summary }}</p>
            @else
                <p class="text-zinc-500 italic">{{ __('This story has no text in the feed.') }}</p>
            @endif
        </div>

        <div @class(['flex flex-wrap items-center gap-2 pt-6 text-zinc-300 dark:text-zinc-700', 'rule-double mt-12' => ! $river, 'mt-8' => $river])>
            @if ($river)
                <flux:button size="sm" wire:click="expand({{ $entry->id }})" icon:trailing="chevron-up">{{ __('Show less') }}</flux:button>
            @endif
            @if ($entry->url)
                <flux:button :href="$entry->url" target="_blank" rel="noopener noreferrer" icon:trailing="arrow-top-right-on-square" :size="$river ? 'sm' : null" :variant="$river ? 'ghost' : null">
                    {{ __('Read on :site', ['site' => parse_url($entry->url, PHP_URL_HOST) ?: __('the original site')]) }}
                </flux:button>
            @endif
        </div>
    @endif
</article>
