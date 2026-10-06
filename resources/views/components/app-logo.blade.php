@props([
    'sidebar' => false,
])

{{-- The shed icon and the hand-drawn "shed" wordmark (single colour, follows the text colour). --}}
<a {{ $attributes->class('flex items-center gap-2.5 rounded-lg px-1 py-1') }}>
    <img src="{{ asset('favicon.svg') }}" alt="" class="size-8 shrink-0 rounded-lg">
    <x-app-wordmark class="h-6 w-auto text-zinc-800 dark:text-white" />
    <span class="sr-only">{{ config('app.name', 'Shed') }}</span>
</a>
