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
