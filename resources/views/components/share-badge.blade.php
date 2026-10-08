@props(['item', 'sharing' => null])

{{-- Small icons next to a name: shared by link, by upload link or with people. Click to open the share dialog. --}}
@if ($sharing)
    @php
        $parts = array_filter([
            $sharing['link'] ? __('Shared by link') : null,
            $sharing['upload'] ? __('Upload link') : null,
            $sharing['people'] > 0 ? trans_choice('Shared with :count person|Shared with :count people', $sharing['people']) : null,
        ]);
    @endphp
    <button type="button" wire:click="$dispatch('share-node', { id: {{ $item->id }} })" data-test="share-badge"
        title="{{ implode(', ', $parts) }}" aria-label="{{ implode(', ', $parts) }}"
        {{ $attributes->class(['flex shrink-0 items-center gap-0.5 rounded p-0.5 text-zinc-500 hover:bg-zinc-200 dark:hover:bg-zinc-600']) }}>
        @if ($sharing['link'])<flux:icon name="link" class="size-4" />@endif
        @if ($sharing['upload'])<flux:icon name="arrow-up-tray" class="size-4" />@endif
        @if ($sharing['people'] > 0)<flux:icon name="users" class="size-4" />@endif
    </button>
@endif
