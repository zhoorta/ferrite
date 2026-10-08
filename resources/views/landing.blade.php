@php
    $repo = config('ferrite.repo_url');
    $demo = config('ferrite.demo_url');
    $themes = [
        'ferrite' => 'Ferrite', 'ferrite-light' => 'Ferrite light', 'mac' => 'Classic Mac', 'desk95' => 'Desktop 95', 'zine' => 'Plain page',
        'bubblegum' => 'Bubblegum 98', 'amber' => 'Amber terminal', 'phosphor' => 'Green phosphor', 'commodore' => 'Commodore',
        'amiga' => 'Amiga', 'nokia' => 'Nokia 3310', 'miami' => 'Miami Vice', 'wallstreet' => 'Wall Street', 'lotus' => 'Lotus 1-2-3', 'memphis' => 'Memphis',
    ];
    $features = [
        ['Files and folders', 'Chunked, resumable uploads that cope with multi-gigabyte files and flaky connections. Drag in whole folders, rename, move, copy, download a folder as a ZIP.'],
        ['Previews and search', 'Images, PDF, video, audio and text open in the browser, with thumbnails and seeking in large videos. Search finds words inside text files and PDFs, not just names.'],
        ['Sharing', 'Links with an optional password and expiry, sharing with other users as viewer or editor, and upload links so clients can drop files into one folder. Revoke any time.'],
        ['Your storage', 'Local folder, S3-compatible or SFTP (a Hetzner Storage Box works well). Identical files are stored once.'],
        ['Small team ready', 'Several users with quotas, an admin screen, two-factor authentication and passkeys.'],
        ['Safe by default', 'Files are served with nosniff and a strict policy; only an allowlist of types opens inline. Trash with restore and auto-purge.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="dark">
    <head>
        @include('partials.head', ['title' => 'Self-hosted file server by StackCare'])
        <meta name="description" content="Ferrite by StackCare is a free, self-hosted file server for one person or a small team: upload, preview and share from the browser, on your own server or storage. Built with Laravel and Livewire.">
    </head>
    <body class="cozy-bg min-h-screen antialiased">
        <header class="mx-auto flex max-w-5xl items-center justify-between px-4 py-5">
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                <img src="{{ asset('favicon.svg') }}" alt="" class="size-8 rounded-lg">
                <x-app-wordmark class="text-xl" />
            </a>
            <nav class="flex items-center gap-4 text-sm">
                <a href="{{ $repo }}" class="hover:underline">Source</a>
                @if ($demo)<a href="{{ $demo }}" class="hover:underline">Demo</a>@endif
                <a href="{{ auth()->check() ? route('files') : route('login') }}" class="rounded-lg border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">{{ auth()->check() ? 'Open my files' : 'Sign in' }}</a>
            </nav>
        </header>

        <main class="mx-auto max-w-5xl px-4">
            <section class="py-16 text-center sm:py-24">
                <h1 class="font-serif text-4xl font-semibold tracking-tight sm:text-6xl">Your files, on your server.</h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">
                    Ferrite is a small, self-hosted file server you use from the browser: upload, preview, share. Without the weight of a full cloud suite.
                    For one person or a small team. No sync clients, no WebDAV, nothing to install on your devices.
                </p>
                <div class="mt-10 flex flex-wrap justify-center gap-3">
                    @if ($demo)
                        <a href="{{ $demo }}" class="rounded-lg bg-accent px-5 py-3 font-medium text-accent-foreground hover:opacity-90">Try the live demo</a>
                    @endif
                    <a href="{{ $repo }}" class="rounded-lg border border-zinc-300 px-5 py-3 font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Get the source</a>
                </div>
                <p class="mt-4 text-sm text-zinc-500">Free software, AGPL-3.0. Laravel 13, Livewire, Flux UI.</p>
            </section>

            <section class="pb-16">
                <img id="app-shot" src="{{ asset('img/themes/ferrite.png') }}" alt="The Ferrite file browser: a sidebar, folders and files in a list with upload and new-folder buttons" width="1280" height="800" class="w-full rounded-xl border border-zinc-200 shadow-2xl dark:border-zinc-800">
            </section>

            <section class="pb-16 text-center">
                <h2 class="font-serif text-2xl font-semibold">Who it is for</h2>
                <p class="mx-auto mt-2 max-w-2xl text-zinc-600 dark:text-zinc-400">Self-hosters, homelabs and small teams who want private file storage on a VPS or a Storage Box, without running Nextcloud.</p>
            </section>

            <section class="pb-16 text-center">
                <h2 class="font-serif text-2xl font-semibold">Pick a look</h2>
                <p class="mx-auto mt-2 max-w-2xl text-zinc-600 dark:text-zinc-400">Fifteen themes, from a calm dark default to Classic Mac, Amiga and green phosphor. Try one, this whole page changes. Every user picks their own.</p>
                <div class="mt-6 flex flex-wrap justify-center gap-2" role="radiogroup" aria-label="Theme">
                    @foreach ($themes as $key => $label)
                        <button type="button" role="radio" data-palette-choice="{{ $key }}" aria-checked="false"
                            class="rounded-lg border border-zinc-300 px-3 py-2 text-sm transition hover:border-accent aria-checked:border-accent aria-checked:ring-2 aria-checked:ring-accent/40 dark:border-zinc-700">{{ $label }}</button>
                    @endforeach
                </div>
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
                <p class="mt-2 text-zinc-600 dark:text-zinc-400">Two ways, same app. Either way you need a domain and HTTPS in front of it. The first account to register becomes the admin.</p>

                <div class="mt-6 grid gap-6 lg:grid-cols-2">
                    <div class="min-w-0">
                        <h3 class="font-serif text-lg font-semibold">With Docker</h3>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">A server with Docker. The image brings PHP, the web server, the workers and the scheduler.</p>
                        <pre class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-100 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900"><code>git clone {{ $repo }}.git ferrite &amp;&amp; cd ferrite
cp docker/ferrite.env.example ferrite.env
docker compose run --rm ferrite php artisan key:generate --show
$EDITOR ferrite.env   # APP_KEY and APP_URL
docker compose up -d</code></pre>
                    </div>

                    <div class="min-w-0">
                        <h3 class="font-serif text-lg font-semibold">With Laravel</h3>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">A server that already runs PHP 8.3+ and nginx or Apache. You add two queue workers and a cron entry.</p>
                        <pre class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-100 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900"><code>git clone {{ $repo }}.git &amp;&amp; cd ferrite
composer install --no-dev -o &amp;&amp; npm ci &amp;&amp; npm run build
cp .env.example .env &amp;&amp; php artisan key:generate
$EDITOR .env          # APP_ENV, APP_URL
php artisan migrate --force &amp;&amp; php artisan optimize</code></pre>
                    </div>
                </div>

                <p class="mt-3 text-sm text-zinc-500">Full guide, with the web server, workers and reverse proxy: <a class="underline" href="{{ $repo }}/blob/main/docs/install.md">docs/install.md</a>.</p>
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
            Made by <a class="underline" href="https://stackcare.pt/en">StackCare</a>.
        </footer>
        <script>
            const lightPalettes = {{ \Illuminate\Support\Js::from(\App\Models\User::LIGHT_PALETTES) }};
            const shot = document.getElementById('app-shot');
            const shotBase = {{ \Illuminate\Support\Js::from(asset('img/themes').'/') }};
            const choices = document.querySelectorAll('[data-palette-choice]');
            function setPalette(name) {
                const root = document.documentElement;
                root.dataset.palette = name;
                root.classList.toggle('dark', !lightPalettes.includes(name));
                shot.src = shotBase + name + '.png';
                choices.forEach((b) => b.setAttribute('aria-checked', b.dataset.paletteChoice === name ? 'true' : 'false'));
                try { localStorage.setItem('ferrite.palette', name); } catch (e) {}
            }
            choices.forEach((b) => b.addEventListener('click', () => setPalette(b.dataset.paletteChoice)));
            setPalette(document.documentElement.dataset.palette || 'ferrite');
        </script>
    </body>
</html>
