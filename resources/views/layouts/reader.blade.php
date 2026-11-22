{{-- Full-height variant of the app layout: no padding, panes scroll independently. --}}
<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="!p-0">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
