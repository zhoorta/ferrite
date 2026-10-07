<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => \App\Models\User::isDarkPalette(auth()->user()?->palette)]) @auth data-palette="{{ auth()->user()->palette }}" @endauth>
    <head>
        @include('partials.head')
        <meta name="robots" content="noindex, nofollow">
    </head>
    <body class="cozy-bg min-h-screen antialiased">
        <div class="mx-auto flex min-h-screen w-full max-w-4xl flex-col gap-6 px-4 py-8">
            <div class="flex items-center gap-2 text-sm text-zinc-500">
                <x-app-logo-icon class="h-5 w-auto text-zinc-700 dark:text-zinc-200" />
                <x-app-wordmark class="text-xl text-zinc-700 dark:text-zinc-200" />
                <span class="sr-only">{{ config('app.name') }}</span>
            </div>

            {{ $slot }}
        </div>

        @fluxScripts
    </body>
</html>
