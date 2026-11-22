<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <meta name="robots" content="noindex">
    </head>
    <body class="min-h-screen bg-paper text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-8">
            {{ $slot }}

            <footer class="mt-12 flex items-center justify-center gap-2 border-t border-zinc-200 pt-6 text-sm text-zinc-500 dark:border-zinc-800">
                <x-app-mark size="sm" />
                <span>{{ __('Shared from a self-hosted') }} <span class="font-serif font-semibold text-zinc-700 dark:text-zinc-300">{{ config('app.name', 'Newsstand') }}</span></span>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
