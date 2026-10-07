@props(['item', 'favorite' => false])

<flux:menu>
    @if ($item->isFolder())
        <flux:menu.item icon="arrow-down-tray" :href="route('nodes.zip', $item)">{{ __('Download as ZIP') }}</flux:menu.item>
    @else
        <flux:menu.item icon="arrow-down-tray" :href="route('nodes.download', $item)">{{ __('Download') }}</flux:menu.item>
    @endif
    <flux:menu.item icon="star" wire:click="toggleFavorite({{ $item->id }})" data-test="favorite-toggle">{{ $favorite ? __('Remove from favorites') : __('Add to favorites') }}</flux:menu.item>
    <flux:menu.item icon="document-duplicate" wire:click="startCopy({{ $item->id }})">{{ __('Copy') }}</flux:menu.item>
    @can('update', $item)
        <flux:menu.item icon="pencil" wire:click="startRename({{ $item->id }})">{{ __('Rename') }}</flux:menu.item>
    @endcan
    @can('share', $item)
        <flux:menu.item icon="share" wire:click="$dispatch('share-node', { id: {{ $item->id }} })">{{ __('Share') }}</flux:menu.item>
    @endcan
    @can('move', $item)
        <flux:menu.item icon="arrow-right-circle" wire:click="startMove({{ $item->id }})">{{ __('Move') }}</flux:menu.item>
        <flux:menu.separator />
        <flux:menu.item icon="trash" variant="danger" wire:click="trash({{ $item->id }})">{{ __('Move to trash') }}</flux:menu.item>
    @endcan
</flux:menu>
