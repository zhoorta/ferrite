<?php

use App\Support\NodeSearch;
use App\Support\Search\ContentSearch;
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

    /**
     * Files whose text matches, without those already listed by name.
     *
     * @return Collection<int, array{node: \App\Models\Node, path: string, folder_id: int|null, snippet: string}>
     */
    #[Computed]
    public function contentResults(): Collection
    {
        $named = $this->results->pluck('node.id');

        return app(NodeSearch::class)->contents(Auth::user(), $this->q)->reject(fn (array $result) => $named->contains($result['node']->id))->values();
    }

    #[Computed]
    public function searchesContents(): bool
    {
        return ContentSearch::enabled();
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Search') }}</flux:heading>

    <flux:input wire:model.live.debounce.300ms="q" icon="magnifying-glass" type="search"
        :placeholder="$this->searchesContents ? __('Search names and contents') : __('Search by name')"
        :aria-label="$this->searchesContents ? __('Search names and contents') : __('Search by name')" autofocus clearable />

    @if (trim($q) === '')
        <flux:text>{{ $this->searchesContents ? __('Type part of a name, or words from inside a text file or PDF.') : __('Type part of a file or folder name.') }}</flux:text>
    @elseif ($this->results->isEmpty() && $this->contentResults->isEmpty())
        <flux:callout icon="magnifying-glass" :heading="__('No files or folders match :term', ['term' => $q])" />
    @else
        @if ($this->results->isNotEmpty())
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

        @if ($this->contentResults->isNotEmpty())
            <div class="space-y-3" data-test="content-results">
                <flux:heading size="lg">{{ __('Found inside files') }}</flux:heading>

                <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                    @foreach ($this->contentResults as $result)
                        @php($item = $result['node'])
                        <li class="flex items-start justify-between gap-3 px-4 py-3" data-test="content-row" wire:key="content-{{ $item->id }}">
                            <div class="min-w-0 space-y-1">
                                <div class="flex items-center gap-2">
                                    <flux:icon name="document" class="size-5 shrink-0 text-zinc-400" />
                                    <flux:link :href="route('nodes.preview', $item)" target="_blank" variant="ghost" class="truncate font-medium">{{ $item->name }}</flux:link>
                                </div>
                                <p class="text-sm text-zinc-600 dark:text-zinc-400 [&_mark]:rounded [&_mark]:bg-accent/25 [&_mark]:px-0.5 [&_mark]:text-inherit">{!! \App\Support\Search\ContentSearch::html($result['snippet']) !!}</p>
                                <p class="text-xs text-zinc-500">
                                    @if ($result['folder_id'])
                                        <flux:link :href="route('files', $result['folder_id'])" wire:navigate variant="subtle">{{ $result['path'] }}</flux:link>
                                    @else
                                        {{ $result['path'] }}
                                    @endif
                                </p>
                            </div>
                            <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :aria-label="__('Download')" :href="route('nodes.download', $item)" />
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</div>
