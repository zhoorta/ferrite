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
        ['Storage', 'Local folder, S3-compatible or SFTP (a Hetzner Storage Box works well). Identical files are stored once.'],
        ['Several users', 'Accounts with quotas, an admin screen, two-factor authentication and passkeys.'],
        ['Safe by default', 'Files are served with nosniff and a strict policy; only an allowlist of types opens inline. Trash with restore and auto-purge, and earlier versions of a replaced file.'],
    ];
    $advanced = [
        ['Search inside files', 'Finds words in text, Markdown, code, CSV and PDF, on SQLite, MySQL and MariaDB. Results show the matching line and where it lives.'],
        ['Upload links', 'Give a client or a colleague a link that adds files to one folder without letting them see what is in it. With password and expiry.'],
        ['Versions', 'Replace a file and the earlier content is kept, up to a limit you set, and can be restored. A bad overwrite is no longer a disaster.'],
        ['A token API', 'Personal access tokens limited to one folder and to read, write, replace or trash. Apps connect through a browser approval with PKCE, never a pasted password.'],
        ['Storage check', 'A storage check confirms every blob exists, and with --verify reads each one back and compares its checksum.'],
        ['Disks per user', 'Route each user to their own disk, such as a Storage Box over SFTP or an S3 bucket, with quotas per user. Identical files are stored once.'],
        ['Usage', 'Where the space goes: by file type, biggest folders and files, trash and versions, and the savings from deduplication.'],
        ['Native apps', 'A Mac app with a menu bar item and Finder upload, and an Android app with share-sheet upload, both with themes. Resumable transfers, no mirroring.'],
        ['Sign-in', 'Two-factor authentication and passkeys, registration closed by default, an admin screen and an activity log.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="dark">
    <head>
        @include('partials.head', ['title' => 'Self-hosted file server by StackCare'])
        <meta name="description" content="Ferrite by StackCare is a free, self-hosted file server for one person or a small team: upload, preview and share from the browser, on your own server or storage. Built with Laravel and Livewire.">
    </head>
    <body class="cozy-bg min-h-screen antialiased">
        @include('partials.site-header')

        <main class="mx-auto max-w-5xl px-4">
            <section class="py-16 text-center sm:py-24">
                <h1 class="font-serif text-4xl font-semibold tracking-tight sm:text-6xl">Your files, on your server.</h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">
                    Ferrite is a small, self-hosted file server you use from the browser: upload, preview, share. Without the weight of a full cloud suite.
                    For one person or a small team. No sync clients and no WebDAV; optional apps for Mac and Android.
                </p>
                <div class="mt-10 flex flex-wrap justify-center gap-3">
                    @if ($demo)
                        <a href="{{ $demo }}" class="rounded-lg bg-accent px-5 py-3 font-medium text-accent-foreground hover:opacity-90">Try the live demo</a>
                    @endif
                    <a href="{{ $repo }}" class="rounded-lg border border-zinc-300 px-5 py-3 font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Get the source</a>
                    <a href="{{ route('docs.index') }}" class="rounded-lg border border-zinc-300 px-5 py-3 font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Read the docs</a>
                </div>
                <p class="mt-4 text-sm text-zinc-500">Free software, AGPL-3.0. Laravel 13, Livewire, Flux UI.</p>
            </section>

            <section class="pb-16">
                <img id="app-shot" src="{{ asset('img/themes/ferrite.png') }}" alt="The Ferrite file browser: a sidebar, folders and files in a list with upload and new-folder buttons" width="1280" height="800" class="w-full rounded-xl border border-zinc-200 shadow-2xl dark:border-zinc-800">
            </section>

            <section class="pb-16 text-center">
                <h2 class="font-serif text-2xl font-semibold">Who it is for</h2>
                <p class="mx-auto mt-2 max-w-2xl text-zinc-600 dark:text-zinc-400">Self-hosters, homelabs and small teams who want private file storage on a VPS or a Storage Box.</p>
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
                <h2 class="text-center font-serif text-2xl font-semibold">More than a file list</h2>
                <p class="mx-auto mt-2 max-w-2xl text-center text-zinc-600 dark:text-zinc-400">Features usually found in larger suites, in an app that runs on a small server.</p>
                <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($advanced as [$title, $text])
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
                            <h3 class="font-serif text-lg font-semibold">{{ $title }}</h3>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-4 text-center text-sm text-zinc-500">All of it is in the <a class="underline" href="{{ route('docs.index') }}">documentation</a>.</p>
            </section>

            <section class="pb-16">
                <h2 class="font-serif text-2xl font-semibold">API</h2>
                <p class="mt-2 max-w-3xl text-zinc-600 dark:text-zinc-400">
                    Scripts and other programs can use Ferrite through an HTTP API: list and search, download with Range, upload in resumable chunks, replace files with versions kept.
                    A token is limited to one folder and to the abilities it was given (read, write, replace, trash), and can be revoked in Settings.
                </p>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-100 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900"><code>curl -H "Authorization: Bearer $TOKEN" \
  "https://files.example.com/api/v1/files?parent=root"</code></pre>
                <p class="mt-3 text-sm text-zinc-500">Reference: <a class="underline" href="{{ route('docs.show', 'api') }}">API</a>. The Mac and Android apps use the same API.</p>
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

            <section class="pb-16">
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-6 text-center dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="font-serif text-2xl font-semibold">Apps for Mac and Android</h2>
                    <p class="mx-auto mt-2 max-w-2xl text-zinc-600 dark:text-zinc-400">Browse, upload from the share sheet or Finder, and open files from a native app. Sign-in goes through your server, and nothing is mirrored to the device.</p>
                    <a href="{{ route('downloads') }}" class="mt-5 inline-block rounded-lg bg-accent px-5 py-3 font-medium text-accent-foreground hover:opacity-90">Download the apps</a>
                </div>
            </section>

            <section class="pb-16">
                <h2 class="font-serif text-2xl font-semibold">Install</h2>
                <p class="mt-2 text-zinc-600 dark:text-zinc-400">Two ways, same app. Both need a domain and HTTPS in front of it. The first account to register becomes the admin.</p>

                <div class="mt-6 grid gap-6">
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
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">A server that already runs PHP 8.3+ and nginx or Apache. Add two queue workers and a cron entry.</p>
                        <pre class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-100 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900"><code>git clone {{ $repo }}.git &amp;&amp; cd ferrite
composer install --no-dev -o &amp;&amp; npm ci &amp;&amp; npm run build
cp .env.example .env &amp;&amp; php artisan key:generate
$EDITOR .env          # APP_ENV, APP_URL
php artisan migrate --force &amp;&amp; php artisan optimize</code></pre>
                    </div>
                </div>

                <p class="mt-3 text-sm text-zinc-500">Full guide, with the web server, workers and reverse proxy: <a class="underline" href="{{ route('docs.show', 'install') }}">the install guide</a>. Everything else is in the <a class="underline" href="{{ route('docs.index') }}">documentation</a>.</p>
            </section>

            <section class="pb-20">
                <h2 class="font-serif text-2xl font-semibold">What it is not</h2>
                <p class="mt-2 max-w-3xl text-zinc-600 dark:text-zinc-400">
                    No sync clients, no WebDAV, no office document previews, no real-time collaboration. Replacing a file keeps its earlier versions, but there is no editing history beyond that.
                    Ferrite does one job: keep files on your own disk, and open, share and download them from a browser.
                </p>
            </section>
        </main>

        @include('partials.site-footer')
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
