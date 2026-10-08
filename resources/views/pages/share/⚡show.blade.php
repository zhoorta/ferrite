<?php

use App\Models\Node;
use App\Models\Share;
use App\Support\FileKind;
use App\Support\ShareAccess;
use App\Support\TextPreview;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::share')] class extends Component {
    public string $token;

    public ?int $nodeId = null;

    public string $password = '';

    public function mount(string $token, ?Node $node = null): void
    {
        $this->token = $token;
        $this->nodeId = $node?->id;

        // An upload link has one page and nothing to browse.
        abort_if($this->share->isDropbox() && $this->nodeId !== null, 404);

        if ($this->unlocked) {
            $this->node();
        }
    }

    /**
     * Looked up on every request, so a revoked or expired link stops working at once.
     */
    #[Computed]
    public function share(): Share
    {
        return app(ShareAccess::class)->find($this->token);
    }

    #[Computed]
    public function unlocked(): bool
    {
        return app(ShareAccess::class)->isUnlocked($this->share);
    }

    /**
     * The shared node, or a node inside it. The id comes from the client, so it is checked every time.
     */
    #[Computed]
    public function node(): Node
    {
        $node = $this->nodeId === null ? $this->share->node : Node::findOrFail($this->nodeId);

        abort_unless(app(ShareAccess::class)->reaches($this->share, $node), 404);

        return $node;
    }

    /**
     * Folders from the shared one down to the current one.
     *
     * @return Collection<int, Node>
     */
    #[Computed]
    public function breadcrumbs(): Collection
    {
        $ids = array_reverse($this->node->selfAndAncestorIds());
        $ids = array_slice($ids, (int) array_search($this->share->node_id, $ids, true));
        $nodes = Node::findMany($ids)->keyBy('id');

        return new Collection(array_map(fn (int $id) => $nodes[$id], $ids));
    }

    /**
     * @return Collection<int, Node>
     */
    #[Computed]
    public function items(): Collection
    {
        return Node::query()
            ->where('parent_id', $this->node->id)
            ->notTrashed()
            ->orderByDesc('type')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function previewKind(): ?string
    {
        return $this->node->isFile() && FileKind::inlineType($this->node->mime) !== null ? FileKind::of($this->node->mime) : null;
    }

    public function unlock(): void
    {
        $this->resetErrorBag();

        app(ShareAccess::class)->unlock($this->share, $this->password, (string) request()->ip());

        $this->reset('password');
        unset($this->unlocked);
    }
}; ?>

<div class="flex flex-col gap-6">
    @if (! $this->unlocked)
        <div class="mx-auto w-full max-w-sm space-y-6 pt-12">
            <div class="space-y-1 text-center">
                <flux:icon name="lock-closed" class="mx-auto size-8 text-zinc-400" />
                <flux:heading size="lg">{{ __('This link is protected') }}</flux:heading>
                <flux:text>{{ __('Enter the password to continue.') }}</flux:text>
            </div>

            <form wire:submit="unlock" class="space-y-4">
                <flux:input wire:model="password" type="password" :label="__('Password')" autofocus viewable />
                <flux:button type="submit" variant="primary" class="w-full">{{ __('Continue') }}</flux:button>
            </form>
        </div>
    @elseif ($this->share->isDropbox())
        <div class="mx-auto w-full max-w-xl space-y-6 pt-8" data-test="dropbox"
            x-data="{ dragging: false, target: { parentId: null, baseUrl: @js('/s/'.$token), conflicts: false } }">
            <div class="space-y-1 text-center">
                <flux:icon name="arrow-up-tray" class="mx-auto size-8 text-zinc-400" />
                <flux:heading size="lg">{{ __('Send files to :name', ['name' => $this->node->name]) }}</flux:heading>
                <flux:text>{{ __('Add files here. You will not see what is in the folder, and files you send cannot be changed afterwards.') }}</flux:text>
            </div>

            <div wire:ignore>
                <div class="rounded-xl border-2 border-dashed border-zinc-300 p-10 text-center transition dark:border-zinc-700"
                    :class="dragging && 'border-accent bg-accent/5'"
                    x-on:dragover.prevent="dragging = true" x-on:dragleave="dragging = false"
                    x-on:drop.prevent="dragging = false; $store.uploads.drop($event, target)">
                    <p class="text-sm text-zinc-500">{{ __('Drop files here, or') }}</p>
                    <flux:button class="mt-3" icon="arrow-up-tray" variant="primary" x-on:click="$refs.files.click()">{{ __('Choose files') }}</flux:button>
                    <input type="file" multiple class="hidden" x-ref="files" x-on:change="$store.uploads.pick($event.target.files, target); $event.target.value = ''" data-test="dropbox-input">
                </div>

                <ul class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800" x-show="$store.uploads.items.length" x-cloak>
                    <template x-for="item in $store.uploads.items" :key="item.key">
                        <li class="space-y-1 py-2 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate" x-text="item.file.name"></span>
                                <span class="shrink-0 text-xs text-zinc-500" x-show="item.status === 'done'">{{ __('Sent') }}</span>
                                <button type="button" class="shrink-0 text-xs text-zinc-500 hover:underline" x-show="['queued', 'uploading'].includes(item.status)" x-on:click="$store.uploads.cancel(item)">{{ __('Cancel') }}</button>
                                <button type="button" class="shrink-0 text-xs text-zinc-500 hover:underline" x-show="item.status === 'error'" x-on:click="$store.uploads.retry(item)">{{ __('Retry') }}</button>
                            </div>
                            <div class="upload-bar h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" x-show="['queued', 'uploading', 'processing', 'done'].includes(item.status)">
                                <div class="upload-fill h-full bg-accent transition-all"
                                    :style="`width: ${item.file.size ? Math.round(item.sent / item.file.size * 100) : (item.status === 'done' ? 100 : 0)}%`"></div>
                            </div>
                            <p class="text-xs text-zinc-500" x-show="item.status === 'processing'">{{ __('Sent. Storing the file…') }}</p>
                            <p class="text-xs text-red-600" x-show="item.status === 'error'" x-text="item.error"></p>
                            <p class="text-xs text-zinc-500" x-show="item.status === 'cancelled'">{{ __('Cancelled') }}</p>
                        </li>
                    </template>
                </ul>
            </div>

            @if ($this->share->max_bytes !== null)
                <flux:text size="sm" class="text-center">{{ __('Room left on this link: :size.', ['size' => \Illuminate\Support\Number::fileSize((int) $this->share->remainingBytes())]) }}</flux:text>
            @endif
        </div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:breadcrumbs>
                @foreach ($this->breadcrumbs as $crumb)
                    @if ($loop->last)
                        <flux:breadcrumbs.item>{{ $crumb->name }}</flux:breadcrumbs.item>
                    @else
                        <flux:breadcrumbs.item :href="route('share.show', [$token, $crumb->id === $this->share->node_id ? null : $crumb->id])" wire:navigate>{{ $crumb->name }}</flux:breadcrumbs.item>
                    @endif
                @endforeach
            </flux:breadcrumbs>

            @if ($this->share->allow_download)
                @if ($this->node->isFolder())
                    <flux:button icon="arrow-down-tray" :href="route('share.zip', [$token, $this->node->id])">{{ __('Download as ZIP') }}</flux:button>
                @else
                    <flux:button icon="arrow-down-tray" variant="primary" :href="route('share.download', [$token, $this->node->id])">{{ __('Download') }}</flux:button>
                @endif
            @endif
        </div>

        @if ($this->node->isFile())
            <x-file-preview
                :kind="$this->previewKind"
                :url="route('share.preview', [$token, $this->node->id])"
                :name="$this->node->name"
                :text="$this->previewKind === 'text' ? TextPreview::read($this->node) : null" />
        @elseif ($this->items->isEmpty())
            <flux:callout icon="folder-open" :heading="__('This folder is empty')" />
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell" align="end">{{ __('Size') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->items as $item)
                        <flux:table.row :key="$item->id" data-test="share-row">
                            <flux:table.cell>
                                <div class="flex items-center gap-3">
                                    <flux:icon :name="$item->isFolder() ? 'folder' : 'document'" class="size-5 shrink-0 text-zinc-400" />
                                    @if ($item->isFolder())
                                        <flux:link :href="route('share.show', [$token, $item->id])" wire:navigate variant="ghost" class="font-medium">{{ $item->name }}</flux:link>
                                    @elseif ($this->share->allow_download || \App\Support\FileKind::inlineType($item->mime))
                                        <flux:link :href="route('share.show', [$token, $item->id])" wire:navigate variant="ghost" class="font-medium">{{ $item->name }}</flux:link>
                                    @else
                                        <span class="font-medium">{{ $item->name }}</span>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="hidden sm:table-cell" align="end">
                                {{ $item->isFile() ? \Illuminate\Support\Number::fileSize($item->size) : '—' }}
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($this->share->allow_download)
                                    <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :aria-label="__('Download')"
                                        :href="$item->isFolder() ? route('share.zip', [$token, $item->id]) : route('share.download', [$token, $item->id])" />
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif
</div>
