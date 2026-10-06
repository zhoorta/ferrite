<?php

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

    public function restore(int $id, RestoreNode $action): void
    {
        $action->handle(Auth::user(), Node::findOrFail($id));

        unset($this->items);
        Flux::toast(variant: 'success', text: __('Restored.'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Trash') }}</flux:heading>

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
                            <flux:button size="sm" icon="arrow-uturn-left" wire:click="restore({{ $item->id }})">{{ __('Restore') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
