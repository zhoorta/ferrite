<!DOCTYPE html>
<html lang="en" class="dark">
    <head>
        @include('partials.head', ['title' => $title ?? null])
        @isset($description)<meta name="description" content="{{ $description }}">@endisset
        <style>
            .docs-prose { line-height: 1.7; color: var(--color-zinc-700); }
            .dark .docs-prose { color: var(--color-zinc-300); }
            .docs-prose > :first-child { margin-top: 0; }
            .docs-prose h1 { font-family: var(--font-serif, serif); font-size: 2rem; font-weight: 600; margin: 0 0 1rem; line-height: 1.2; }
            .docs-prose h2 { font-family: var(--font-serif, serif); font-size: 1.5rem; font-weight: 600; margin: 2.25rem 0 .75rem; line-height: 1.25; }
            .docs-prose h3 { font-family: var(--font-serif, serif); font-size: 1.15rem; font-weight: 600; margin: 1.75rem 0 .5rem; }
            .docs-prose h4 { font-weight: 600; margin: 1.25rem 0 .4rem; }
            .docs-prose h1, .docs-prose h2, .docs-prose h3, .docs-prose h4 { color: var(--color-zinc-900); scroll-margin-top: 1rem; }
            .dark .docs-prose h1, .dark .docs-prose h2, .dark .docs-prose h3, .dark .docs-prose h4 { color: var(--color-zinc-100); }
            .docs-prose p, .docs-prose ul, .docs-prose ol, .docs-prose pre, .docs-prose table, .docs-prose blockquote { margin: 0 0 1rem; }
            .docs-prose ul { list-style: disc; padding-left: 1.4rem; }
            .docs-prose ol { list-style: decimal; padding-left: 1.4rem; }
            .docs-prose li { margin: .25rem 0; }
            .docs-prose a { color: var(--color-accent, inherit); text-decoration: underline; }
            .docs-prose code { font-size: .875em; padding: .1em .35em; border-radius: .3rem; background: color-mix(in srgb, currentColor 10%, transparent); word-break: break-word; }
            .docs-prose pre { overflow-x: auto; padding: 1rem; border-radius: .75rem; border: 1px solid var(--color-zinc-200); background: var(--color-zinc-100); font-size: .875rem; line-height: 1.55; }
            .dark .docs-prose pre { border-color: var(--color-zinc-800); background: var(--color-zinc-900); }
            .docs-prose pre code { padding: 0; background: none; word-break: normal; }
            .docs-prose blockquote { border-left: 3px solid var(--color-zinc-400); padding-left: 1rem; color: var(--color-zinc-500); }
            .docs-prose table { display: block; overflow-x: auto; border-collapse: collapse; font-size: .9rem; }
            .docs-prose th, .docs-prose td { border: 1px solid var(--color-zinc-200); padding: .4rem .7rem; text-align: left; vertical-align: top; }
            .dark .docs-prose th, .dark .docs-prose td { border-color: var(--color-zinc-800); }
            .docs-prose th { background: color-mix(in srgb, currentColor 6%, transparent); }
            .docs-prose img { max-width: 100%; border-radius: .5rem; }
            .docs-prose hr { margin: 2rem 0; border-color: var(--color-zinc-200); }
        </style>
    </head>
    <body class="cozy-bg min-h-screen antialiased">
        @include('partials.site-header')
        <main class="mx-auto max-w-5xl px-4 pb-20">
            {{ $slot }}
        </main>
        @include('partials.site-footer')
    </body>
</html>
