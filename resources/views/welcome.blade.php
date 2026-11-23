<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => null])
    </head>
    <body class="min-h-screen bg-paper text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div class="mx-auto flex min-h-screen max-w-6xl flex-col px-6 sm:px-10">
            {{-- Top bar --}}
            <header class="flex items-center justify-between py-6">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                    <x-app-mark size="sm" />
                    <x-app-wordmark class="text-xl" />
                </a>

                <nav class="flex items-center gap-2">
                    @auth
                        <flux:button :href="route('reader')" variant="primary" size="sm">{{ __('Open Newsstand') }}</flux:button>
                    @else
                        <flux:button :href="route('login')" variant="ghost" size="sm">{{ __('Log in') }}</flux:button>
                        @if (Route::has('register') && \App\Support\Registration::isOpen())
                            <flux:button :href="route('register')" variant="primary" size="sm">{{ __('Create account') }}</flux:button>
                        @endif
                    @endauth
                </nav>
            </header>

            {{-- Masthead --}}
            <main class="flex flex-1 flex-col justify-center py-12">
                <div class="flex items-center justify-between border-y border-zinc-300 py-2 text-[0.7rem] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:border-zinc-700 dark:text-zinc-400">
                    <span>{{ now()->isoFormat('dddd, D MMMM YYYY') }}</span>
                    <span class="hidden sm:inline">{{ __('Tech · Security · Engineering') }}</span>
                    <span>{{ __('Self-hosted edition') }}</span>
                </div>

                <h1 class="mt-10 text-center font-serif text-6xl leading-none font-semibold tracking-tight sm:text-8xl lg:text-9xl">
                    {{ config('app.name', 'Newsstand') }}
                </h1>

                <p class="mx-auto mt-6 max-w-2xl text-center font-serif text-xl text-zinc-600 italic sm:text-2xl dark:text-zinc-300">
                    {{ __('The tech and security news you follow, in one calm place.') }}
                </p>

                <div class="rule-double mt-12 text-zinc-300 dark:text-zinc-700"></div>

                <div class="grid gap-10 pt-8 sm:grid-cols-3 sm:gap-0 sm:divide-x sm:divide-zinc-300 dark:sm:divide-zinc-700">
                    @foreach ([
                        ['rss', __('Follow'), __('Subscribe to any blog, vendor advisory or news site with RSS or Atom. Bring your list along with OPML.')],
                        ['book-open', __('Read'), __('A distraction-free reader with clean typography, full-text fetching and keyboard shortcuts for every action.')],
                        ['shield-check', __('Watch'), __('Surface what matters: CVEs, actively exploited vulnerabilities and stories many sources are covering.')],
                    ] as [$icon, $heading, $body])
                        <section class="sm:px-8 first:sm:pl-0 last:sm:pr-0">
                            <div class="flex items-center gap-2 text-emerald-700 dark:text-emerald-400">
                                <flux:icon :name="$icon" variant="mini" />
                                <h2 class="font-serif text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{{ $heading }}</h2>
                            </div>
                            <p class="mt-3 leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $body }}</p>
                        </section>
                    @endforeach
                </div>
            </main>

            <footer class="flex items-center justify-between border-t border-zinc-300 py-6 text-sm text-zinc-500 dark:border-zinc-800">
                <span>{{ config('app.name', 'Newsstand') }} &middot; {{ __('Your feeds, your server.') }}</span>
                <span class="font-serif italic">{{ __('Est. :year', ['year' => 2026]) }} &middot; v{{ config('newsstand.version') }}</span>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
