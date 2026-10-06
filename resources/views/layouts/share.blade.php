<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <meta name="robots" content="noindex, nofollow">
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-800">
        <div class="mx-auto flex min-h-screen w-full max-w-4xl flex-col gap-6 px-4 py-8">
            <div class="flex items-center gap-2 text-sm text-zinc-500">
                <x-app-logo-icon class="size-5 fill-current" />
                <span>{{ config('app.name') }}</span>
            </div>

            {{ $slot }}
        </div>

        @fluxScripts
    </body>
</html>
