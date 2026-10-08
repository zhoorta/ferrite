<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        @if (\App\Support\Demo::isDemoUser(auth()->user()))
            <div class="mb-4 rounded-lg border border-zinc-200 bg-zinc-100 px-4 py-2 text-center text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300" data-test="demo-banner">
                {{ __('Public demo: everything is deleted after :minutes minutes, files are only visible to you, and uploads are limited to small images, PDFs and text. Do not upload anything private or illegal.', ['minutes' => config('ferrite.demo.ttl_minutes')]) }}
                @if (filled(config('ferrite.demo.contact')))
                    {{ __('Report abuse:') }} <a class="underline" href="mailto:{{ config('ferrite.demo.contact') }}">{{ config('ferrite.demo.contact') }}</a>
                @endif
            </div>
        @endif

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
