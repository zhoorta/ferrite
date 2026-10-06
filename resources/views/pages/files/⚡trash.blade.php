<?php

use App\Actions\Nodes\EmptyTrash;
use App\Actions\Nodes\PurgeNode;
use App\Actions\Nodes\RestoreNode;
use App\Models\Node;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Trash')] class extends Component {
    /**
     * @return Collection<int, Node>
     */
    #[Computed]
    public function items(): Collection
    {
        return Node::query()
            ->whereKey(Node::trashRootIds(Auth::user()))
            ->orderByDesc('trashed_at')
            ->get();
    }

    public function purge(int $id, PurgeNode $action): void
    {
        $action->handle(Auth::user(), Node::findOrFail($id));

        unset($this->items);
        Flux::toast(variant: 'success', text: __('Deleted permanently.'));
    }

    public function emptyTrash(EmptyTrash $action): void
    {
        $action->handle(Auth::user());

        unset($this->items);
        Flux::toast(variant: 'success', text: __('The trash is empty.'));
    }

    public function restore(int $id, RestoreNode $action): void
    {
        $action->handle(Auth::user(), Node::findOrFail($id));

        unset($this->items);
        Flux::toast(variant: 'success', text: __('Restored.'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Trash') }}</flux:heading>
            <flux:text>{{ __('Items are deleted for good after :days days.', ['days' => config('shed.trash_days')]) }}</flux:text>
        </div>

        @if ($this->items->isNotEmpty())
            <flux:button variant="danger" icon="trash" wire:click="emptyTrash" wire:confirm="{{ __('Permanently delete everything in the trash? This cannot be undone.') }}">{{ __('Empty trash') }}</flux:button>
        @endif
    </div>

    @if ($this->items->isEmpty())
        <flux:callout icon="trash" :heading="__('The trash is empty')" />
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Deleted') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->items as $item)
                    <flux:table.row :key="$item->id" data-test="trash-row">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:icon :name="$item->isFolder() ? 'folder' : 'document'" class="size-5 shrink-0 text-zinc-400" />
                                <span class="font-medium">{{ $item->name }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">{{ $item->trashed_at?->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" icon="arrow-uturn-left" wire:click="restore({{ $item->id }})">{{ __('Restore') }}</flux:button>
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="purge({{ $item->id }})" wire:confirm="{{ __('Delete permanently? This cannot be undone.') }}" :aria-label="__('Delete permanently')" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
