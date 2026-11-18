<?php

use App\Actions\Nodes\DeleteVersion;
use App\Actions\Nodes\RestoreVersion;
use App\Models\Node;
use App\Models\NodeVersion;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public ?int $nodeId = null;

    #[On('versions-node')]
    public function open(int $id): void
    {
        $node = Node::findOrFail($id);
        Gate::authorize('update', $node);
        abort_unless($node->isFile() && ! $node->isTrashed(), 404);

        $this->nodeId = $node->id;

        Flux::modal('versions')->show();
    }

    #[Computed]
    public function node(): ?Node
    {
        return $this->nodeId === null ? null : Node::findOrFail($this->nodeId);
    }

    /**
     * @return Collection<int, NodeVersion>
     */
    #[Computed]
    public function versions(): Collection
    {
        return $this->nodeId === null ? new Collection : NodeVersion::query()->where('node_id', $this->nodeId)->with('user')->latest('id')->get();
    }

    public function restore(int $id, RestoreVersion $action): void
    {
        $action->handle(Auth::user(), NodeVersion::query()->where('node_id', $this->nodeId)->findOrFail($id));

        unset($this->node, $this->versions);
        $this->dispatch('versions-changed');
        Flux::toast(variant: 'success', text: __('Version restored. The content it replaced is kept as a version.'));
    }

    public function delete(int $id, DeleteVersion $action): void
    {
        $action->handle(Auth::user(), NodeVersion::query()->where('node_id', $this->nodeId)->findOrFail($id));

        unset($this->versions);
        $this->dispatch('versions-changed');
    }
}; ?>

<div>
    <flux:modal name="versions" class="w-full max-w-xl">
        @if ($this->node)
            <div class="space-y-4">
                <flux:heading size="lg" class="truncate pe-8">{{ __('Versions of ":name"', ['name' => $this->node->name]) }}</flux:heading>
                <flux:text size="sm">
                    @if (config('ferrite.versions_keep') > 0)
                        {{ __('Replacing a file keeps its earlier content here, up to :count versions. They count against the storage quota.', ['count' => config('ferrite.versions_keep')]) }}
                    @else
                        {{ __('Versions are turned off on this server: replacing a file deletes its earlier content.') }}
                    @endif
                </flux:text>

                <div class="flex items-center justify-between gap-3 rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800/50" data-test="current-version">
                    <div class="min-w-0">
                        <div class="font-medium">{{ __('Current') }}</div>
                        <div class="text-zinc-500">{{ $this->node->updated_at?->diffForHumans() }} · {{ \Illuminate\Support\Number::fileSize($this->node->size) }}</div>
                    </div>
                    <flux:button size="xs" :href="route('nodes.download', $this->node)" icon="arrow-down-tray">{{ __('Download') }}</flux:button>
                </div>

                @forelse ($this->versions as $version)
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-100 px-3 py-2 text-sm last:border-0 dark:border-zinc-800" wire:key="version-{{ $version->id }}" data-test="version">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ ($version->content_at ?? $version->created_at)?->toDayDateTimeString() }}</div>
                            <div class="truncate text-zinc-500">
                                {{ \Illuminate\Support\Number::fileSize($version->size) }}
                                @if ($version->user) · {{ __('replaced by :name', ['name' => $version->user->name]) }} @endif
                                · {{ $version->created_at?->diffForHumans() }}
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('nodes.version', [$this->node, $version])" :aria-label="__('Download')" />
                            <flux:button size="xs" wire:click="restore({{ $version->id }})" wire:confirm="{{ __('Make this the current version? The current content is kept as a version.') }}" data-test="restore-version">{{ __('Restore') }}</flux:button>
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $version->id }})" wire:confirm="{{ __('Delete this version for good?') }}" :aria-label="__('Delete version')" data-test="delete-version" />
                        </div>
                    </div>
                @empty
                    <flux:text>{{ __('No earlier versions yet.') }}</flux:text>
                @endforelse
            </div>
        @endif
    </flux:modal>
</div>
