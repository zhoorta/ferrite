<x-layouts::site title="Documentation" description="Documentation for Ferrite, the self-hosted file server by StackCare: install, configure, share, use the apps and the API.">
    <section class="py-10 text-center sm:py-14">
        <h1 class="font-serif text-4xl font-semibold tracking-tight">Documentation</h1>
        <p class="mx-auto mt-4 max-w-2xl text-zinc-600 dark:text-zinc-400">How to run Ferrite, what it does, and how to use the API. Start with the <a class="underline" href="{{ route('docs.show', 'install') }}">install guide</a>.</p>
    </section>

    @foreach ($groups as $group => $pages)
        <section class="pb-10">
            <h2 class="font-serif text-2xl font-semibold">{{ $group }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach ($pages as $page)
                    <a href="{{ route('docs.show', $page['slug']) }}" class="block rounded-xl border border-zinc-200 bg-zinc-50 p-5 transition hover:border-accent dark:border-zinc-800 dark:bg-zinc-900">
                        <h3 class="font-serif text-lg font-semibold">{{ $page['title'] }}</h3>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $page['blurb'] }}</p>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
</x-layouts::site>
