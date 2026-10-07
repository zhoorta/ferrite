@props([
    'sidebar' => false,
])

{{-- The Ferrite cassette (themed) and the "ferrite" wordmark; both follow the text colour. --}}
<a {{ $attributes->class('flex items-center gap-2.5 rounded-lg px-1 py-1') }}>
    <x-app-logo-icon class="h-7 w-auto shrink-0 text-zinc-800 dark:text-white" />
    <x-app-wordmark class="text-2xl text-zinc-800 dark:text-white" />
    <span class="sr-only">{{ config('app.name', 'Ferrite') }}</span>
</a>
