@props(['item', 'favorite' => false])

{{-- Clickable star: always shown when favorited, otherwise only while the row is hovered or focused (always on touch). --}}
<button type="button" wire:click="toggleFavorite({{ $item->id }})" data-test="favorite-star"
    :aria-label="$favorite ? __('Remove from favorites') : __('Add to favorites')"
    {{ $attributes->class(['shrink-0 rounded p-0.5 text-accent-content transition-opacity hover:bg-zinc-200 focus-visible:opacity-100 dark:hover:bg-zinc-600', 'sm:opacity-0 sm:group-hover/row:opacity-100' => ! $favorite]) }}>
    <flux:icon name="star" :variant="$favorite ? 'solid' : 'outline'" class="size-4" :data-test="$favorite ? 'favorite-mark' : null" />
</button>
