<?php

use App\Actions\Nodes\CreateFolder;
use App\Actions\Nodes\MoveNode;
use App\Actions\Nodes\RenameNode;
use App\Actions\Nodes\TrashNode;
use App\Models\Node;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Files')] class extends Component {
    public ?int $folderId = null;

    public string $name = '';

    public ?int $renameId = null;

    public ?int $moveId = null;

    /** Folder being browsed inside the move dialog; null is the root. */
    public ?int $moveBrowseId = null;

    public function mount(?Node $folder = null): void
    {
        if ($folder !== null) {
            Gate::authorize('view', $folder);

            abort_unless($folder->isFolder() && ! $folder->isTrashed(), 404);

            $this->folderId = $folder->id;
        }
    }

    #[Computed]
    public function folder(): ?Node
    {
        return $this->folderId === null ? null : Node::findOrFail($this->folderId);
    }

    #[Computed]
    public function canCreate(): bool
    {
        return $this->folder === null || Gate::allows('update', $this->folder);
    }

    /**
     * Folders the user may open, from the top of what they can see down to the current one.
     *
     * @return Collection<int, Node>
     */
    #[Computed]
    public function breadcrumbs(): Collection
    {
        if ($this->folder === null) {
            return new Collection;
        }

        $ids = array_reverse($this->folder->selfAndAncestorIds());
        $nodes = Node::findMany($ids)->keyBy('id');

        return (new Collection($ids))
            ->map(fn (int $id) => $nodes[$id])
            ->filter(fn (Node $node) => Gate::allows('view', $node))
            ->values();
    }

    /**
     * @return Collection<int, Node>
     */
    #[Computed]
    public function items(): Collection
    {
        return Node::query()
            ->where('parent_id', $this->folderId)
            ->when($this->folderId === null, fn ($query) => $query->where('owner_id', Auth::id()))
            ->notTrashed()
            ->orderByDesc('type')
            ->orderBy('name')
            ->get();
    }

    /**
     * Subfolders offered as destinations in the move dialog.
     *
     * @return Collection<int, Node>
     */
    #[Computed]
    public function moveFolders(): Collection
    {
        return Node::query()
            ->where('owner_id', Auth::id())
            ->where('parent_id', $this->moveBrowseId)
            ->where('type', 'folder')
            ->whereKeyNot($this->moveId)
            ->notTrashed()
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function moveBrowseFolder(): ?Node
    {
        return $this->moveBrowseId === null ? null : Node::where('owner_id', Auth::id())->find($this->moveBrowseId);
    }

    public function createFolder(CreateFolder $action): void
    {
        $action->handle(Auth::user(), $this->folder, $this->name);

        $this->reset('name');
        unset($this->items);
        Flux::modal('new-folder')->close();
    }

    public function startRename(int $id): void
    {
        $node = Node::findOrFail($id);
        Gate::authorize('update', $node);

        $this->renameId = $node->id;
        $this->name = $node->name;
        $this->resetErrorBag();
        Flux::modal('rename')->show();
    }

    public function rename(RenameNode $action): void
    {
        $action->handle(Auth::user(), Node::findOrFail($this->renameId), $this->name);

        $this->reset('name', 'renameId');
        unset($this->items);
        Flux::modal('rename')->close();
    }

    public function startMove(int $id): void
    {
        $node = Node::findOrFail($id);
        Gate::authorize('move', $node);

        $this->moveId = $node->id;
        $this->moveBrowseId = $node->parent_id;
        $this->resetErrorBag();
        Flux::modal('move')->show();
    }

    public function browseMove(?int $id): void
    {
        $this->moveBrowseId = $id;
        $this->resetErrorBag();
    }

    public function move(MoveNode $action): void
    {
        $action->handle(Auth::user(), Node::findOrFail($this->moveId), $this->moveBrowseFolder);

        $this->reset('moveId', 'moveBrowseId');
        unset($this->items);
        Flux::modal('move')->close();
        Flux::toast(variant: 'success', text: __('Moved.'));
    }

    public function trash(int $id, TrashNode $action): void
    {
        $action->handle(Auth::user(), Node::findOrFail($id));

        unset($this->items);
        Flux::toast(variant: 'success', text: __('Moved to trash.'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('files')" wire:navigate>{{ __('My files') }}</flux:breadcrumbs.item>
            @foreach ($this->breadcrumbs as $crumb)
                @if ($loop->last)
                    <flux:breadcrumbs.item>{{ $crumb->name }}</flux:breadcrumbs.item>
                @else
                    <flux:breadcrumbs.item :href="route('files', $crumb)" wire:navigate>{{ $crumb->name }}</flux:breadcrumbs.item>
                @endif
            @endforeach
        </flux:breadcrumbs>

        @if ($this->canCreate)
            <flux:modal.trigger name="new-folder">
                <flux:button icon="folder-plus" wire:click="$set('name', '')">{{ __('New folder') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    @if ($this->items->isEmpty())
        <flux:callout icon="folder-open" :heading="__('This folder is empty')" />
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell" align="end">{{ __('Size') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Modified') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->items as $item)
                    <flux:table.row :key="$item->id" data-test="node-row">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:icon :name="$item->isFolder() ? 'folder' : 'document'" class="size-5 shrink-0 text-zinc-400" />
                                @if ($item->isFolder())
                                    <flux:link :href="route('files', $item)" wire:navigate variant="ghost" class="font-medium">{{ $item->name }}</flux:link>
                                @else
                                    <span class="font-medium">{{ $item->name }}</span>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell" align="end">
                            {{ $item->isFile() ? \Illuminate\Support\Number::fileSize($item->size) : '—' }}
                        </flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">{{ $item->updated_at?->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Actions')" />

                                <flux:menu>
                                    @can('update', $item)
                                        <flux:menu.item icon="pencil" wire:click="startRename({{ $item->id }})">{{ __('Rename') }}</flux:menu.item>
                                    @endcan
                                    @can('move', $item)
                                        <flux:menu.item icon="arrow-right-circle" wire:click="startMove({{ $item->id }})">{{ __('Move') }}</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="trash({{ $item->id }})">{{ __('Move to trash') }}</flux:menu.item>
                                    @endcan
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="new-folder" class="w-full max-w-sm">
        <form wire:submit="createFolder" class="space-y-6">
            <flux:heading size="lg">{{ __('New folder') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" autofocus />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="rename" class="w-full max-w-sm">
        <form wire:submit="rename" class="space-y-6">
            <flux:heading size="lg">{{ __('Rename') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" autofocus />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Rename') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="move" class="w-full max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Move to') }}</flux:heading>

            <div class="flex items-center gap-2 text-sm">
                @if ($this->moveBrowseFolder)
                    <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="browseMove({{ $this->moveBrowseFolder->parent_id ?? 'null' }})" :aria-label="__('Up')" />
                    <span class="font-medium">{{ $this->moveBrowseFolder->name }}</span>
                @else
                    <span class="font-medium">{{ __('My files') }}</span>
                @endif
            </div>

            <div class="max-h-64 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                @forelse ($this->moveFolders as $candidate)
                    <button type="button" wire:key="move-{{ $candidate->id }}" wire:click="browseMove({{ $candidate->id }})"
                        class="flex w-full items-center gap-3 px-3 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <flux:icon name="folder" class="size-5 text-zinc-400" />
                        {{ $candidate->name }}
                    </button>
                @empty
                    <flux:text class="p-3">{{ __('No subfolders.') }}</flux:text>
                @endforelse
            </div>

            <flux:error name="destination" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="move">{{ __('Move here') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
