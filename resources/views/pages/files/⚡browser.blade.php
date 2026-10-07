<?php

use App\Actions\Nodes\CreateFolder;
use App\Actions\Nodes\MoveNode;
use App\Actions\Nodes\RenameNode;
use App\Actions\Nodes\TrashNode;
use App\Models\Node;
use App\Support\FileKind;
use App\Support\TextPreview;
use App\Support\Thumbnailer;
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

    public ?int $previewId = null;

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

    /** Where ".." leads: the parent folder if it is visible, else the top of what the user sees; null at the top. */
    #[Computed]
    public function upUrl(): ?string
    {
        if ($this->folder === null) {
            return null;
        }

        $parent = $this->breadcrumbs->reverse()->values()->get(1);

        if ($parent !== null) {
            return route('files', $parent);
        }

        return $this->folder->owner_id === Auth::id() ? route('files') : route('shared');
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

    #[Computed]
    public function previewNode(): ?Node
    {
        return $this->previewId === null ? null : Node::find($this->previewId);
    }

    /**
     * How the previewed file is shown: image, pdf, video, audio, text, or null for none.
     */
    #[Computed]
    public function previewKind(): ?string
    {
        $node = $this->previewNode;

        return $node !== null && FileKind::inlineType($node->mime) !== null ? FileKind::of($node->mime) : null;
    }

    /**
     * @return array{text: string, truncated: bool}|null
     */
    #[Computed]
    public function previewText(): ?array
    {
        $node = $this->previewNode;

        return $node !== null && $this->previewKind === 'text' ? TextPreview::read($node) : null;
    }

    public function preview(int $id): void
    {
        $node = Node::findOrFail($id);
        Gate::authorize('view', $node);

        abort_unless($node->isFile() && ! $node->isTrashed(), 404);

        $this->previewId = $node->id;
        unset($this->previewNode, $this->previewKind, $this->previewText, $this->previewNeighbours);
        Flux::modal('preview')->show();
    }

    /**
     * Position of the previewed file among the files of this folder, with its neighbours.
     *
     * @return array{prev: ?int, next: ?int, position: int, total: int}
     */
    #[Computed]
    public function previewNeighbours(): array
    {
        $ids = $this->items->filter(fn (Node $node) => $node->isFile())->pluck('id')->values();
        $index = $ids->search($this->previewId);

        return [
            'prev' => $index === false ? null : $ids->get($index - 1 < 0 ? -1 : $index - 1),
            'next' => $index === false ? null : $ids->get($index + 1),
            'position' => $index === false ? 0 : $index + 1,
            'total' => $ids->count(),
        ];
    }

    /** Preview the previous (-1) or next (1) file of the folder. */
    public function previewStep(int $direction): void
    {
        $id = $this->previewNeighbours[$direction < 0 ? 'prev' : 'next'];

        if ($id !== null) {
            $this->preview($id);
        }
    }

    public function closePreview(): void
    {
        $this->previewId = null;
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

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6"
    x-data="{ dragging: false, target: { parentId: @js($folderId), baseUrl: @js(url('/')) } }"
    x-on:shed-uploaded.window="$wire.$refresh()"
    @if ($this->canCreate)
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.self="dragging = false"
        x-on:drop.prevent="dragging = false; $store.uploads.drop($event, target)"
    @endif
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:breadcrumbs>
            @if ($this->folder && $this->folder->owner_id !== auth()->id())
                <flux:breadcrumbs.item :href="route('shared')" wire:navigate>{{ __('Shared with me') }}</flux:breadcrumbs.item>
            @else
                <flux:breadcrumbs.item :href="route('files')" wire:navigate>{{ __('My files') }}</flux:breadcrumbs.item>
            @endif
            @foreach ($this->breadcrumbs as $crumb)
                @if ($loop->last)
                    <flux:breadcrumbs.item>{{ $crumb->name }}</flux:breadcrumbs.item>
                @else
                    <flux:breadcrumbs.item :href="route('files', $crumb)" wire:navigate>{{ $crumb->name }}</flux:breadcrumbs.item>
                @endif
            @endforeach
        </flux:breadcrumbs>

        @if ($this->canCreate)
            <div class="flex flex-wrap gap-2">
                <input type="file" multiple class="hidden" x-ref="files" x-on:change="$store.uploads.pick($event.target.files, target); $event.target.value = ''" data-test="upload-input">
                <input type="file" webkitdirectory class="hidden" x-ref="folder" x-on:change="$store.uploads.pick($event.target.files, target); $event.target.value = ''">

                <flux:button icon="arrow-up-tray" variant="primary" x-on:click="$refs.files.click()">{{ __('Upload') }}</flux:button>
                <flux:button icon="arrow-up-tray" x-on:click="$refs.folder.click()">{{ __('Upload folder') }}</flux:button>

                <flux:modal.trigger name="new-folder">
                    <flux:button icon="folder-plus" wire:click="$set('name', '')">{{ __('New folder') }}</flux:button>
                </flux:modal.trigger>
            </div>
        @endif
    </div>

    @if ($this->items->isEmpty() && ! $this->upUrl)
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
                @if ($this->upUrl)
                    <flux:table.row data-test="up-row" class="cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700/60"
                        :data-href="$this->upUrl" x-on:click="if ($event.target.closest('a')) return; Livewire.navigate($el.dataset.href)">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:icon name="arrow-uturn-left" class="size-5 shrink-0 text-zinc-400" />
                                <flux:link :href="$this->upUrl" wire:navigate variant="ghost" class="font-medium" :aria-label="__('Up one level')">..</flux:link>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell" />
                        <flux:table.cell class="hidden sm:table-cell" />
                        <flux:table.cell />
                    </flux:table.row>
                    @if ($this->items->isEmpty())
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-zinc-500">{{ __('This folder is empty') }}</flux:table.cell>
                        </flux:table.row>
                    @endif
                @endif
                @foreach ($this->items as $item)
                    <flux:table.row :key="$item->id" data-test="node-row" class="cursor-pointer transition-colors hover:bg-zinc-100 has-[[data-flux-dropdown][data-open]]:bg-zinc-100 dark:hover:bg-zinc-700/60 dark:has-[[data-flux-dropdown][data-open]]:bg-zinc-700/60"
                        :data-href="$item->isFolder() ? route('files', $item) : null" :data-preview="$item->isFile() ? $item->id : null"
                        x-on:click="if ($event.target.closest('a, button, [data-flux-dropdown]') || window.getSelection().toString()) return; $el.dataset.href ? Livewire.navigate($el.dataset.href) : $wire.preview(Number($el.dataset.preview))"
                        x-on:contextmenu="if ($event.shiftKey) return; $event.preventDefault(); const c = $el.querySelector('[data-test=row-context]'); c.style.left = $event.clientX + 'px'; c.style.top = $event.clientY + 'px'; c.querySelector('button').click()">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                @if ($item->isFile() && app(Thumbnailer::class)->supports($item))
                                    <span x-data="{ failed: false }" class="flex size-8 shrink-0 items-center justify-center">
                                        <img x-show="!failed" x-on:error="failed = true" loading="lazy" alt=""
                                            src="{{ route('nodes.thumbnail', $item) }}" class="size-8 rounded object-cover">
                                        <flux:icon x-show="failed" name="photo" class="size-5 text-zinc-400" />
                                    </span>
                                @else
                                    <flux:icon :name="$item->isFolder() ? 'folder' : 'document'" class="size-5 shrink-0 text-zinc-400" />
                                @endif
                                @if ($item->isFolder())
                                    <flux:link :href="route('files', $item)" wire:navigate variant="ghost" class="font-medium">{{ $item->name }}</flux:link>
                                @else
                                    <button type="button" wire:click="preview({{ $item->id }})" class="text-start font-medium hover:underline">{{ $item->name }}</button>
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

                                <x-node-menu :item="$item" />
                            </flux:dropdown>

                            {{-- Same menu, anchored to the cursor: a zero-size trigger is moved to the click point on right-click. --}}
                            <flux:dropdown position="bottom" align="start" class="fixed" data-test="row-context">
                                <button type="button" class="size-0" tabindex="-1" aria-hidden="true"></button>

                                <x-node-menu :item="$item" />
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <livewire:pages::files.share-dialog />

    <flux:modal name="preview" class="w-full max-w-4xl" x-on:close="$wire.closePreview()">
        @if ($this->previewNode)
            <div class="space-y-4" wire:key="preview-{{ $this->previewNode->id }}"
                x-on:keydown.left.window="if (!$event.target.closest('audio, video, input, textarea')) $wire.previewStep(-1)"
                x-on:keydown.right.window="if (!$event.target.closest('audio, video, input, textarea')) $wire.previewStep(1)">
                <flux:heading size="lg" class="truncate pe-8">{{ $this->previewNode->name }}</flux:heading>

                <x-file-preview
                    :kind="$this->previewKind"
                    :url="route('nodes.preview', $this->previewNode)"
                    :name="$this->previewNode->name"
                    :text="$this->previewText" />

                <div class="flex items-center justify-between gap-2">
                    @php($nav = $this->previewNeighbours)
                    <div class="flex items-center gap-2" data-test="preview-nav">
                        <flux:button size="sm" icon="chevron-left" wire:click="previewStep(-1)" :disabled="$nav['prev'] === null" :aria-label="__('Previous')" />
                        <flux:text size="sm">{{ $nav['position'] }} / {{ $nav['total'] }}</flux:text>
                        <flux:button size="sm" icon="chevron-right" wire:click="previewStep(1)" :disabled="$nav['next'] === null" :aria-label="__('Next')" />
                    </div>
                    <flux:button icon="arrow-down-tray" :href="route('nodes.download', $this->previewNode)">{{ __('Download') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

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

    <div x-show="dragging" x-cloak
        class="pointer-events-none fixed inset-0 z-40 flex items-center justify-center bg-zinc-900/60 text-xl font-medium text-white">
        {{ __('Drop to upload') }}
    </div>
</div>
