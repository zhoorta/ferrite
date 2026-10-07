@php
    $repo = config('ferrite.repo_url');
    $demo = config('ferrite.demo_url');
    $features = [
        ['Files and folders', 'Chunked, resumable uploads that cope with multi-gigabyte files and flaky connections. Drag in whole folders, rename, move, copy, download a folder as a ZIP.'],
        ['Previews', 'Images, PDF, video, audio and text open in the browser, with thumbnails and seeking in large videos.'],
        ['Sharing', 'Links with an optional password and expiry, or share with other users as viewer or editor. Revoke any time.'],
        ['Your storage', 'Local folder, S3-compatible or SFTP (a Hetzner Storage Box works well). Identical files are stored once.'],
        ['Small team ready', 'Several users with quotas, an admin screen, two-factor authentication and passkeys.'],
        ['Safe by default', 'Files are served with nosniff and a strict policy; only an allowlist of types opens inline. Trash with restore and auto-purge.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="dark">
    <head>
        @include('partials.head', ['title' => 'Self-hosted file storage'])
        <meta name="description" content="Ferrite is a free, self-hosted file storage app built with Laravel and Livewire: a Google Drive replacement for one person or a small team.">
    </head>
    <body class="cozy-bg min-h-screen bg-white antialiased dark:bg-zinc-950 text-zinc-800 dark:text-zinc-200">
        <header class="mx-auto flex max-w-5xl items-center justify-between px-4 py-5">
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                <img src="{{ asset('favicon.svg') }}" alt="" class="size-8 rounded-lg">
                <x-app-wordmark class="text-xl" />
            </a>
            <nav class="flex items-center gap-4 text-sm">
                <a href="{{ $repo }}" class="hover:underline">Source</a>
                @if ($demo)<a href="{{ $demo }}" class="hover:underline">Demo</a>@endif
                <a href="{{ route('login') }}" class="rounded-lg border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Sign in</a>
            </nav>
        </header>

        <main class="mx-auto max-w-5xl px-4">
            <section class="py-16 text-center sm:py-24">
                <h1 class="font-serif text-4xl font-semibold tracking-tight sm:text-6xl">Your files, on your server.</h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">
                    Ferrite is a self-hosted, web-only file storage app: a Google Drive replacement for one person or a small team.
                    No sync clients, no WebDAV, nothing to install on your devices.
                </p>
                <div class="mt-10 flex flex-wrap justify-center gap-3">
                    @if ($demo)
                        <a href="{{ $demo }}" class="rounded-lg bg-accent px-5 py-3 font-medium text-accent-foreground hover:opacity-90">Try the live demo</a>
                    @endif
                    <a href="{{ $repo }}" class="rounded-lg border border-zinc-300 px-5 py-3 font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Get the source</a>
                </div>
                <p class="mt-4 text-sm text-zinc-500">Free software, AGPL-3.0. Laravel 13, Livewire, Flux UI.</p>
            </section>

            <section class="grid gap-4 pb-16 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($features as [$title, $text])
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
                        <h2 class="font-serif text-lg font-semibold">{{ $title }}</h2>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $text }}</p>
                    </div>
                @endforeach
            </section>

            <section class="pb-16">
                <h2 class="font-serif text-2xl font-semibold">Run it in five minutes</h2>
                <p class="mt-2 text-zinc-600 dark:text-zinc-400">You need a server with Docker and a domain. The first account to register becomes the admin.</p>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-100 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900"><code>git clone {{ $repo }}.git ferrite &amp;&amp; cd ferrite
cp docker/ferrite.env.example ferrite.env
docker compose run --rm ferrite php artisan key:generate --show
$EDITOR ferrite.env   # APP_KEY and APP_URL
docker compose up -d --build</code></pre>
                <p class="mt-3 text-sm text-zinc-500">Full guide: <a class="underline" href="{{ $repo }}/blob/main/docs/install.md">docs/install.md</a>.</p>
            </section>

            <section class="pb-20">
                <h2 class="font-serif text-2xl font-semibold">What it is not</h2>
                <p class="mt-2 max-w-3xl text-zinc-600 dark:text-zinc-400">
                    No sync clients, no WebDAV, no office document previews, no real-time collaboration, no versioning (yet).
                    Ferrite does one job: keep your files on a disk you control, and let you open, share and download them from a browser.
                </p>
            </section>
        </main>

        <footer class="border-t border-zinc-200 py-8 text-center text-sm text-zinc-500 dark:border-zinc-800">
            Ferrite is free software under the <a class="underline" href="{{ $repo }}/blob/main/LICENSE">AGPL-3.0</a>.
            Made by <a class="underline" href="https://stackcare.pt">StackCare</a>.
        </footer>
    </body>
</html>
