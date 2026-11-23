<footer class="border-t border-zinc-200 py-8 text-center text-sm text-zinc-500 dark:border-zinc-800">
    <p>
        Documentation:
        <a class="underline" href="{{ route('docs.show', 'install') }}">Install</a> ·
        <a class="underline" href="{{ route('docs.show', 'api') }}">API</a> ·
        <a class="underline" href="{{ route('docs.show', 'sharing') }}">Sharing</a> ·
        <a class="underline" href="{{ route('docs.show', 'apps') }}">Apps</a> ·
        <a class="underline" href="{{ route('docs.show', 'security') }}">Security</a> ·
        <a class="underline" href="{{ route('docs.index') }}">All docs</a>
    </p>
    <p class="mt-2">
        Ferrite is free software under the <a class="underline" href="{{ config('ferrite.repo_url') }}/blob/main/LICENSE">AGPL-3.0</a>.
        Made by <a class="underline" href="https://stackcare.pt/en">StackCare</a>.
    </p>
</footer>
