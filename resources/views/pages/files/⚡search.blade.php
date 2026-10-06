<?php

use App\Support\NodeSearch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Search')] class extends Component {
    #[Url(as: 'q')]
    public string $q = '';

    /**
     * @return Collection<int, array{node: \App\Models\Node, path: string, folder_id: int|null}>
     */
    #[Computed]
    public function results(): Collection
    {
        return app(NodeSearch::class)->search(Auth::user(), $this->q);
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Search') }}</flux:heading>

    <flux:input wire:model.live.debounce.300ms="q" icon="magnifying-glass" type="search" :placeholder="__('Search by name')" :aria-label="__('Search by name')" autofocus clearable />

    @if (trim($q) === '')
        <flux:text>{{ __('Type part of a file or folder name.') }}</flux:text>
    @elseif ($this->results->isEmpty())
        <flux:callout icon="magnifying-glass" :heading="__('No files or folders match :term', ['term' => $q])" />
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Location') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell" align="end">{{ __('Size') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->results as $result)
                    @php($item = $result['node'])
                    <flux:table.row :key="$item->id" data-test="search-row">
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
                        <flux:table.cell class="hidden sm:table-cell">
                            @if ($result['folder_id'])
                                <flux:link :href="route('files', $result['folder_id'])" wire:navigate variant="subtle" class="text-sm">{{ $result['path'] }}</flux:link>
                            @else
                                <span class="text-sm text-zinc-500">{{ $result['path'] }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell" align="end">
                            {{ $item->isFile() ? \Illuminate\Support\Number::fileSize($item->size) : '—' }}
                        </flux:table.cell>
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
