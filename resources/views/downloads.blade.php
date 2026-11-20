@php
    $cards = [
        'mac' => [
            'title' => 'Ferrite for Mac',
            'needs' => 'macOS 14 or newer',
            'text' => 'A window with a folder tree, list and grid, drag and drop from Finder, upload with progress, edit in place, a menu bar item and "Upload to Ferrite" in the right-click menu.',
            'steps' => [
                'Open the zip, then drag Ferrite to your Applications folder.',
                'The first time, right-click the app and choose Open, then confirm. macOS asks because the app is not notarized yet.',
                'Enter your server address; the app opens your browser to sign in and approve the connection.',
            ],
        ],
        'android' => [
            'title' => 'Ferrite for Android',
            'needs' => 'Android 8 or newer',
            'text' => 'Browse, search and open files, upload from the share sheet or the photo picker, download folders as a ZIP, with the same themes as the Mac app.',
            'steps' => [
                'Open the download on your phone. Android asks to allow installs from your browser: allow it for this once.',
                'Install, open Ferrite and enter your server address.',
                'Your browser opens to sign in and approve the connection, then returns to the app.',
            ],
        ],
    ];
    $sizeOf = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024).' KB';
@endphp
<x-layouts::site title="Mac and Android apps" description="Download the Ferrite apps for Mac and Android: native companions for browsing, uploading and opening your files on a Ferrite server.">
    <section class="py-10 text-center sm:py-14">
        <h1 class="font-serif text-4xl font-semibold tracking-tight">Ferrite for Mac and Android</h1>
        <p class="mx-auto mt-4 max-w-2xl text-zinc-600 dark:text-zinc-400">
            Native companions for browsing, uploading and opening your files. They are not sync clients: nothing is mirrored to the device, files are fetched when you open them.
            They connect to a Ferrite server, so one has to be running first (<a class="underline" href="{{ route('docs.show', 'install') }}">install guide</a>).
        </p>
    </section>

    <section class="grid gap-6 md:grid-cols-2">
        @foreach ($cards as $key => $card)
            @php $app = $apps[$key] ?? null; @endphp
            <div class="flex flex-col rounded-xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900" data-test="download-{{ $key }}">
                <h2 class="font-serif text-2xl font-semibold">{{ $card['title'] }}</h2>
                <p class="mt-1 text-sm text-zinc-500">{{ $card['needs'] }}</p>
                <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">{{ $card['text'] }}</p>

                <div class="mt-5">
                    @if ($app)
                        <a href="{{ route('downloads.file', $app['file']) }}" class="inline-block rounded-lg bg-accent px-5 py-3 font-medium text-accent-foreground hover:opacity-90">Download {{ $app['file'] }}</a>
                        <p class="mt-2 text-sm text-zinc-500">Version {{ $app['version'] }} · {{ $sizeOf($app['size']) }}</p>
                        <p class="mt-1 break-all text-xs text-zinc-500">SHA-256: <code>{{ $app['sha256'] }}</code></p>
                    @else
                        <p class="rounded-lg border border-dashed border-zinc-300 px-4 py-3 text-sm text-zinc-500 dark:border-zinc-700">Not published on this server yet.</p>
                    @endif
                </div>

                <h3 class="mt-6 text-sm font-semibold">Getting started</h3>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-zinc-600 dark:text-zinc-400">
                    @foreach ($card['steps'] as $step)<li>{{ $step }}</li>@endforeach
                </ol>
            </div>
        @endforeach
    </section>

    <section class="mt-10 grid gap-6 md:grid-cols-2">
        <div>
            <h2 class="font-serif text-xl font-semibold">What the server needs</h2>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Ferrite v0.4.0 or newer, reachable over HTTPS. Sign-in happens in the browser, with two-factor authentication if enabled. There you choose which folder the app may reach and whether it can only read or also change files. The access is listed under Settings, API tokens, and can be revoked there.</p>
        </div>
        <div>
            <h2 class="font-serif text-xl font-semibold">More</h2>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                <a class="underline" href="{{ route('docs.show', 'apps') }}">How the apps work and how to troubleshoot them</a>, and the
                <a class="underline" href="{{ route('docs.show', 'api') }}">API</a> they use. The apps are free software under the AGPL-3.0, like the server. Check the SHA-256 above against the file you downloaded.
            </p>
        </div>
    </section>
</x-layouts::site>
