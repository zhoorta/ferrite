@php
    $repo = config('ferrite.repo_url');
    $demo = config('ferrite.demo_url');
    $themes = [
        'ferrite' => 'Ferrite', 'ferrite-light' => 'Ferrite light', 'mac' => 'Classic Mac', 'desk95' => 'Desktop 95', 'zine' => 'Plain page',
        'bubblegum' => 'Bubblegum 98', 'amber' => 'Amber terminal', 'phosphor' => 'Green phosphor', 'commodore' => 'Commodore',
        'amiga' => 'Amiga', 'nokia' => 'Nokia 3310', 'miami' => 'Miami Vice', 'wallstreet' => 'Wall Street', 'lotus' => 'Lotus 1-2-3', 'memphis' => 'Memphis',
    ];
    $features = [
        ['Files and folders', 'Upload files or whole folders from the browser. Chunked uploads resume after a dropped connection, including multi-gigabyte files. Rename, move, copy, or download a folder as a ZIP.'],
        ['Previews and search', 'Images, PDFs, video, audio and text open in the browser. Search matches file names and the text inside text files, code, CSV and PDFs, and shows the matching line.'],
        ['Sharing', 'Share with other users as viewer or editor, or by link with an optional password and expiry. An upload link lets someone add files to one folder without seeing what is in it.'],
        ['Storage', 'A local folder, S3-compatible storage or SFTP, such as a Hetzner Storage Box. Each user can be routed to their own disk. Identical files are stored once, and a usage page shows where the space goes.'],
        ['Users and access', 'Accounts with quotas, an admin screen, two-factor authentication and passkeys. Registration is closed by default, and an activity log records file operations.'],
        ['Trash and versions', 'Deleted files go to the trash and can be restored, and are purged after a set time. Replacing a file keeps the earlier content, up to a limit you set.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="dark">
    <head>
        @include('partials.head', ['title' => 'Self-hosted file server by StackCare'])
        <meta name="description" content="Ferrite by StackCare is a free, self-hosted file server with a web interface and an API. Upload, organise, preview and share files, stored on a local disk, an S3 bucket or an SFTP box. For individuals and small teams. Built with Laravel and Livewire.">
    </head>
    <body class="cozy-bg min-h-screen antialiased">
        @include('partials.site-header')

        <main class="mx-auto max-w-5xl px-4">
            <section class="py-16 text-center sm:py-24">
                <h1 class="font-serif text-4xl font-semibold tracking-tight sm:text-6xl">Self-hosted file storage.</h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">
                    Ferrite is a file server with a web interface. Upload, organise, preview and share files, stored on a local disk, an S3 bucket or an SFTP box.
                    For individuals and small teams, with an API for scripts and apps that need storage.
                </p>
                <div class="mt-10 flex flex-wrap justify-center gap-3">
                    @if ($demo)
                        <a href="{{ $demo }}" class="rounded-lg bg-accent px-5 py-3 font-medium text-accent-foreground hover:opacity-90">Try the demo</a>
                    @endif
                    <a href="{{ $repo }}" class="rounded-lg border border-zinc-300 px-5 py-3 font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Source code</a>
                    <a href="{{ route('docs.index') }}" class="rounded-lg border border-zinc-300 px-5 py-3 font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Documentation</a>
                </div>
                <p class="mt-4 text-sm text-zinc-500">Free software, AGPL-3.0. Laravel 13, Livewire, Flux UI.</p>
            </section>

            <section class="pb-16">
                <img id="app-shot" src="{{ asset('img/themes/ferrite.png') }}" alt="The Ferrite file browser: a sidebar, folders and files in a list with upload and new-folder buttons" width="1280" height="800" class="w-full rounded-xl border border-zinc-200 shadow-2xl dark:border-zinc-800">
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
                <h2 class="font-serif text-2xl font-semibold">API</h2>
                <p class="mt-2 max-w-3xl text-zinc-600 dark:text-zinc-400">
                    Scripts and other programs can use Ferrite through an HTTP API: list and search, download, upload in resumable chunks, and replace files.
                    A token is limited to one folder and to the operations it was given (read, write, replace, trash), and can be revoked in Settings.
                </p>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-100 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900"><code>curl -H "Authorization: Bearer $TOKEN" \
  "https://files.example.com/api/v1/files?parent=root"</code></pre>
                <p class="mt-3 text-sm text-zinc-500">Reference: <a class="underline" href="{{ route('docs.show', 'api') }}">API</a>. The Mac and Android apps use the same API.</p>
            </section>

            <section class="pb-16 text-center">
                <h2 class="font-serif text-2xl font-semibold">Themes</h2>
                <p class="mx-auto mt-2 max-w-2xl text-zinc-600 dark:text-zinc-400">Fifteen themes, including Classic Mac, Amiga, Commodore, Nokia 3310 and several terminal designs. Each user picks their own. Choose one below to preview it on this page.</p>
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
                    <p class="mx-auto mt-2 max-w-2xl text-zinc-600 dark:text-zinc-400">The Mac app uploads from Finder and has a menu bar item. The Android app uploads from the share sheet. Both sign in through your server and resume interrupted transfers. Neither mirrors folders to the device.</p>
                    <a href="{{ route('downloads') }}" class="mt-5 inline-block rounded-lg bg-accent px-5 py-3 font-medium text-accent-foreground hover:opacity-90">Download the apps</a>
                </div>
            </section>

            <section class="pb-16">
                <h2 class="font-serif text-2xl font-semibold">Install</h2>
                <p class="mt-2 text-zinc-600 dark:text-zinc-400">Ferrite runs with Docker or as a regular Laravel application. Both need a domain and HTTPS. The first account to register becomes the admin, and registration then closes.</p>

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

                <p class="mt-3 text-sm text-zinc-500">Full guide, with the web server, workers and reverse proxy: <a class="underline" href="{{ route('docs.show', 'install') }}">the install guide</a>. The rest is in the <a class="underline" href="{{ route('docs.index') }}">documentation</a>.</p>
            </section>

            <section class="pb-20">
                <h2 class="font-serif text-2xl font-semibold">Not included</h2>
                <p class="mt-2 max-w-3xl text-zinc-600 dark:text-zinc-400">
                    Ferrite has no sync clients, no WebDAV, no office document previews and no real-time collaboration. It stores and manages files; it is not a document editing suite.
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
