@php
    $repo = config('ferrite.repo_url');
    $demo = config('ferrite.demo_url');
@endphp
<header class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-y-3 px-4 py-5">
    <a href="{{ route('home') }}" class="flex items-center gap-2">
        <img src="{{ asset('favicon.svg') }}" alt="" class="size-8 rounded-lg">
        <x-app-wordmark class="text-xl" />
    </a>
    <nav class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        <a href="{{ route('docs.index') }}" class="hover:underline">Docs</a>
        <a href="{{ route('downloads') }}" class="hover:underline">Apps</a>
        <a href="{{ $repo }}" class="hover:underline">Source</a>
        @if ($demo)<a href="{{ $demo }}" class="hover:underline">Demo</a>@endif
        <a href="{{ auth()->check() ? route('files') : route('login') }}" class="rounded-lg border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">{{ auth()->check() ? 'Open my files' : 'Sign in' }}</a>
    </nav>
</header>
