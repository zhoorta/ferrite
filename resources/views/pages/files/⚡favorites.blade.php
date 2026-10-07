<?php

use App\Models\Node;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Favorites')] class extends Component {
    /**
     * Starred nodes the user can still see: not trashed, not in the trash, still shared with them.
     *
     * @return Collection<int, Node>
     */
    #[Computed]
    public function items(): Collection
    {
        return Auth::user()->favorites()
            ->with(['owner', 'parent'])
            ->notTrashed()
            ->orderByDesc('type')
            ->orderBy('name')
            ->get()
            ->reject(fn (Node $node) => $node->isTrashed() || Gate::denies('view', $node))
            ->values();
    }

    public function remove(int $id): void
    {
        Auth::user()->favorites()->detach($id);
        unset($this->items);
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Favorites') }}</flux:heading>

    @if ($this->items->isEmpty())
        <flux:callout icon="star" :heading="__('No favorites yet')" :text="__('Star a file or folder from its menu to find it here.')" />
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Owner') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->items as $item)
                    <flux:table.row :key="$item->id" data-test="favorite-row">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:icon :name="$item->isFolder() ? 'folder' : 'document'" class="size-5 shrink-0 text-zinc-400" />
                                @if ($item->isFolder())
                                    <flux:link :href="route('files', $item)" wire:navigate variant="ghost" class="font-medium">{{ $item->name }}</flux:link>
                                @else
                                    <flux:link :href="route('nodes.preview', $item)" target="_blank" variant="ghost" class="font-medium">{{ $item->name }}</flux:link>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">{{ $item->owner_id === auth()->id() ? __('You') : $item->owner->name }}</flux:table.cell>
                        <flux:table.cell align="end">
                            {{-- A folder opens itself, a file its folder. Top-level files open the root; a shared item whose parent the user cannot see has no folder to open. --}}
                            @if ($item->isFolder() || $item->parent === null || auth()->user()->can('view', $item->parent))
                                <flux:button size="sm" variant="ghost" icon="folder-open" :aria-label="$item->isFolder() ? __('Open folder') : __('Open containing folder')" data-test="favorite-open-folder"
                                    :href="route('files', $item->isFolder() ? $item : $item->parent)" wire:navigate />
                            @endif
                            <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :aria-label="__('Download')"
                                :href="$item->isFolder() ? route('nodes.zip', $item) : route('nodes.download', $item)" />
                            <flux:button size="sm" variant="ghost" icon="star" wire:click="remove({{ $item->id }})" :aria-label="__('Remove from favorites')" data-test="favorite-remove" />
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
