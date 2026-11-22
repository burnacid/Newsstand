<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-paper antialiased dark:bg-zinc-950">
        <div class="relative grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            {{-- Masthead panel --}}
            <aside class="relative hidden overflow-hidden bg-ink p-12 text-zinc-100 lg:flex lg:flex-col dark:border-e dark:border-zinc-800">
                <div class="pointer-events-none absolute -right-24 -bottom-24 size-[28rem] rounded-full bg-emerald-500/10 blur-3xl"></div>

                <a href="{{ route('home') }}" class="relative flex items-center gap-3" wire:navigate>
                    <x-app-mark />
                    <x-app-wordmark class="text-2xl !text-zinc-50" />
                </a>

                <div class="relative mt-auto max-w-md">
                    <div class="flex items-center justify-between border-b border-zinc-700 pb-2 text-[0.7rem] font-medium tracking-[0.2em] text-zinc-400 uppercase">
                        <span>{{ now()->isoFormat('dddd, D MMMM YYYY') }}</span>
                        <span>{{ __('Your edition') }}</span>
                    </div>

                    <p class="mt-6 font-serif text-4xl leading-tight font-medium text-balance text-zinc-50">
                        {{ __('The tech and security news you follow, in one calm place.') }}
                    </p>

                    <div class="rule-double mt-8 pt-6 text-zinc-600"></div>
                    <dl class="-mt-2 grid grid-cols-3 gap-6 text-sm">
                        <div>
                            <dt class="font-serif text-base font-semibold text-zinc-100">{{ __('Follow') }}</dt>
                            <dd class="mt-1 text-zinc-400">{{ __('Any RSS or Atom feed, organized in folders.') }}</dd>
                        </div>
                        <div>
                            <dt class="font-serif text-base font-semibold text-zinc-100">{{ __('Read') }}</dt>
                            <dd class="mt-1 text-zinc-400">{{ __('A quiet reader built for the keyboard.') }}</dd>
                        </div>
                        <div>
                            <dt class="font-serif text-base font-semibold text-zinc-100">{{ __('Watch') }}</dt>
                            <dd class="mt-1 text-zinc-400">{{ __('Security signals and shared dashboards.') }}</dd>
                        </div>
                    </dl>
                </div>
            </aside>

            {{-- Form --}}
            <main class="flex items-center justify-center px-6 py-12 sm:px-10">
                <div class="flex w-full max-w-sm flex-col gap-6">
                    <a href="{{ route('home') }}" class="flex flex-col items-center gap-3 lg:hidden" wire:navigate>
                        <x-app-mark size="lg" />
                        <x-app-wordmark class="text-2xl" />
                    </a>
                    {{ $slot }}
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
