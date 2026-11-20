<x-layouts::site :title="$page['title'].' - Documentation'" :description="$page['blurb']">
    <div class="grid gap-8 pt-4 md:grid-cols-[14rem_minmax(0,1fr)]">
        <nav class="text-sm md:sticky md:top-6 md:self-start" aria-label="Documentation">
            <a href="{{ route('docs.index') }}" class="font-medium hover:underline">All documentation</a>
            @foreach ($groups as $group => $pages)
                <h2 class="mt-5 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $group }}</h2>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($pages as $item)
                        <li>
                            <a href="{{ route('docs.show', $item['slug']) }}" @if ($item['slug'] === $slug) aria-current="page" @endif
                                class="block rounded-md px-2 py-1 hover:bg-zinc-100 aria-[current=page]:bg-zinc-100 aria-[current=page]:font-medium dark:hover:bg-zinc-800 dark:aria-[current=page]:bg-zinc-800">{{ $item['title'] }}</a>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </nav>

        <article class="docs-prose min-w-0">
            {!! $html !!}
            <p class="mt-10 text-sm text-zinc-500">
                Found a mistake? This page is <a href="{{ config('ferrite.repo_url') }}/blob/main/docs/{{ $slug }}.md">docs/{{ $slug }}.md</a> in the repository.
            </p>
        </article>
    </div>
</x-layouts::site>
