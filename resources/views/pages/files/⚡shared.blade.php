<?php

use App\Models\Node;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shared with me')] class extends Component {
    /**
     * @return Collection<int, Node>
     */
    #[Computed]
    public function items(): Collection
    {
        return Auth::user()->sharedNodes()
            ->with('owner')
            ->notTrashed()
            ->orderBy('name')
            ->get()
            ->reject(fn (Node $node) => $node->isTrashed())
            ->values();
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Shared with me') }}</flux:heading>

    @if ($this->items->isEmpty())
        <flux:callout icon="users" :heading="__('Nothing has been shared with you yet')" />
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Owner') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Access') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->items as $item)
                    <flux:table.row :key="$item->id" data-test="shared-row">
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
                        <flux:table.cell class="hidden sm:table-cell">{{ $item->owner->name }}</flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">{{ $item->pivot->permission === 'edit' ? __('Can edit') : __('Can view') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :aria-label="__('Download')"
                                :href="$item->isFolder() ? route('nodes.zip', $item) : route('nodes.download', $item)" />
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
