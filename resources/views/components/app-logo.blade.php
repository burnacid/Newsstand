@props([
    'sidebar' => false,
])

@php
    $name = new Illuminate\Support\HtmlString(
        '<span class="font-serif text-[1.15rem] font-semibold tracking-tight">'.e(config('app.name', 'Newsstand')).'</span>'
    );
@endphp

@if($sidebar)
    <flux:sidebar.brand :name="$name" {{ $attributes }}>
        <x-slot name="logo" class="!size-8 !rounded-none !overflow-visible">
            <x-app-mark />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$name" {{ $attributes }}>
        <x-slot name="logo" class="!size-8 !rounded-none !overflow-visible">
            <x-app-mark />
        </x-slot>
    </flux:brand>
@endif
