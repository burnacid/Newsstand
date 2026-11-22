@props([
    'size' => 'md',
])

@php
    $sizeClass = match ($size) {
        'sm' => 'size-7',
        'lg' => 'size-12',
        'xl' => 'size-16',
        default => 'size-8',
    };
@endphp

<x-app-logo-icon {{ $attributes->class($sizeClass) }} />
