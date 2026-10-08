<?php

use App\Actions\Sharing\CreateShareLink;
use App\Actions\Sharing\RevokeShareLink;
use App\Actions\Sharing\ShareWithUser;
use App\Actions\Sharing\UnshareWithUser;
use App\Enums\Permission;
use App\Models\Node;
use App\Models\Share;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public ?int $nodeId = null;

    public string $linkPassword = '';

    public string $expiry = 'never';

    public bool $allowDownload = true;

    public string $linkKind = 'view';

    public string $maxSize = '';

    public string $email = '';

    public string $permission = 'view';

    #[On('share-node')]
    public function open(int $id): void
    {
        $node = Node::findOrFail($id);
        Gate::authorize('share', $node);

        $this->reset('linkPassword', 'expiry', 'allowDownload', 'linkKind', 'maxSize', 'email', 'permission');
        $this->resetErrorBag();
        $this->nodeId = $node->id;

        Flux::modal('share')->show();
    }

    #[Computed]
    public function node(): ?Node
    {
        return $this->nodeId === null ? null : Node::findOrFail($this->nodeId);
    }

    /**
     * @return Collection<int, Share>
     */
    #[Computed]
    public function links(): Collection
    {
        return Share::query()
            ->where('node_id', $this->nodeId)
            ->whereNull('revoked_at')
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        return $this->node === null ? new Collection : $this->node->sharedWith()->orderBy('name')->get();
    }

    public function createLink(CreateShareLink $action): void
    {
        $this->validate([
            'expiry' => ['required', 'in:never,1d,7d,30d'],
            'linkPassword' => ['nullable', 'string', 'max:255'],
            'linkKind' => ['required', 'in:view,dropbox'],
            'maxSize' => ['nullable', 'numeric', 'min:0.001', 'max:1000000'],
        ]);

        $expiresAt = match ($this->expiry) {
            '1d' => now()->addDay(),
            '7d' => now()->addDays(7),
            '30d' => now()->addDays(30),
            default => null,
        };

        $dropbox = $this->linkKind === 'dropbox';
        $maxBytes = $dropbox && $this->maxSize !== '' ? (int) round((float) $this->maxSize * 1024 ** 3) : null;

        $action->handle(Auth::user(), Node::findOrFail($this->nodeId), $this->linkPassword, $expiresAt, $this->allowDownload, $dropbox, $maxBytes);

        $this->reset('linkPassword', 'expiry', 'maxSize');
        unset($this->links);
    }

    public function revokeLink(int $id, RevokeShareLink $action): void
    {
        $action->handle(Auth::user(), Share::query()->where('node_id', $this->nodeId)->findOrFail($id));

        unset($this->links);
    }

    public function shareWithUser(ShareWithUser $action): void
    {
        $this->validate(['permission' => ['required', 'in:view,edit']]);

        $action->handle(Auth::user(), Node::findOrFail($this->nodeId), $this->email, Permission::from($this->permission));

        $this->reset('email');
        unset($this->people);
    }

    public function removeUser(int $userId, UnshareWithUser $action): void
    {
        $action->handle(Auth::user(), Node::findOrFail($this->nodeId), User::findOrFail($userId));

        unset($this->people);
    }
}; ?>

<div>
    <flux:modal name="share" class="w-full max-w-xl">
        @if ($this->node)
            <div class="space-y-8">
                <flux:heading size="lg" class="truncate pe-8">{{ __('Share ":name"', ['name' => $this->node->name]) }}</flux:heading>

                <section class="space-y-4">
                    <flux:heading>{{ __('People') }}</flux:heading>

                    <form wire:submit="shareWithUser" class="flex flex-wrap items-start gap-2">
                        <div class="min-w-48 flex-1">
                            <flux:input wire:model="email" type="email" :placeholder="__('E-mail address')" :aria-label="__('E-mail address')" />
                            <flux:error name="email" />
                        </div>
                        <flux:select wire:model="permission" class="!w-32" :aria-label="__('Permission')">
                            <flux:select.option value="view">{{ __('Can view') }}</flux:select.option>
                            <flux:select.option value="edit">{{ __('Can edit') }}</flux:select.option>
                        </flux:select>
                        <flux:button type="submit">{{ __('Share') }}</flux:button>
                    </form>

                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->people as $person)
                            <li class="flex items-center justify-between gap-3 py-2 text-sm" wire:key="person-{{ $person->id }}" data-test="share-person">
                                <div class="min-w-0">
                                    <div class="truncate font-medium">{{ $person->name }}</div>
                                    <div class="truncate text-zinc-500">{{ $person->email }}</div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <flux:badge size="sm">{{ $person->pivot->permission === 'edit' ? __('Can edit') : __('Can view') }}</flux:badge>
                                    <flux:button size="xs" variant="ghost" wire:click="removeUser({{ $person->id }})">{{ __('Remove') }}</flux:button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="space-y-4">
                    <flux:heading>{{ __('Links') }}</flux:heading>
                    <flux:text size="sm">{{ __('Anyone with a link can open this, without an account.') }}</flux:text>

                    <form wire:submit="createLink" class="space-y-3">
                        @if ($this->node->isFolder())
                            <flux:radio.group wire:model.live="linkKind" variant="segmented" :label="__('Type')">
                                <flux:radio value="view" :label="__('Download link')" />
                                <flux:radio value="dropbox" :label="__('Upload link')" />
                            </flux:radio.group>
                            @if ($linkKind === 'dropbox')
                                <flux:text size="sm">{{ __('Guests can add files to this folder. They cannot see, open or change anything in it.') }}</flux:text>
                            @endif
                        @endif
                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:input wire:model="linkPassword" type="password" :label="__('Password (optional)')" autocomplete="new-password" />
                            <flux:select wire:model="expiry" :label="__('Expires')">
                                <flux:select.option value="never">{{ __('Never') }}</flux:select.option>
                                <flux:select.option value="1d">{{ __('In 1 day') }}</flux:select.option>
                                <flux:select.option value="7d">{{ __('In 7 days') }}</flux:select.option>
                                <flux:select.option value="30d">{{ __('In 30 days') }}</flux:select.option>
                            </flux:select>
                        </div>
                        @if ($linkKind === 'dropbox')
                            <div>
                                <flux:input wire:model="maxSize" type="number" step="any" min="0" :label="__('Size limit in GB (optional)')" :description="__('Total the link will accept; leave empty for no limit apart from your quota.')" />
                                <flux:error name="maxSize" />
                            </div>
                        @else
                        <flux:switch wire:model="allowDownload" :label="__('Allow downloads')" :description="__('When off, the page shows no download buttons. Files that can be shown in the browser can still be saved from it.')" />
                        @endif
                        <flux:button type="submit" variant="primary" icon="link">{{ $linkKind === 'dropbox' ? __('Create upload link') : __('Create link') }}</flux:button>
                    </form>

                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->links as $link)
                            <li class="space-y-1 py-2 text-sm" wire:key="link-{{ $link->id }}" data-test="share-link">
                                <div class="flex items-center gap-2">
                                    <input type="text" readonly value="{{ $link->url() }}" class="min-w-0 flex-1 rounded border border-zinc-200 bg-transparent px-2 py-1 text-xs dark:border-zinc-700" x-on:focus="$el.select()">
                                    <flux:button size="xs" x-data="{ copied: false }"
                                        x-on:click="navigator.clipboard.writeText(@js($link->url())); copied = true; setTimeout(() => copied = false, 1500)">
                                        <span x-show="!copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                    </flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="revokeLink({{ $link->id }})">{{ __('Revoke') }}</flux:button>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    @if ($link->isDropbox())
                                        <flux:badge size="sm" icon="arrow-up-tray">{{ __('Upload') }}</flux:badge>
                                        <flux:badge size="sm">{{ $link->max_bytes === null ? \Illuminate\Support\Number::fileSize($link->received_bytes).' '.__('received') : \Illuminate\Support\Number::fileSize($link->received_bytes).' / '.\Illuminate\Support\Number::fileSize($link->max_bytes) }}</flux:badge>
                                    @endif
                                    @if ($link->hasPassword())<flux:badge size="sm" icon="lock-closed">{{ __('Password') }}</flux:badge>@endif
                                    @if (! $link->allow_download && ! $link->isDropbox())<flux:badge size="sm">{{ __('View only') }}</flux:badge>@endif
                                    @if ($link->expires_at)
                                        <flux:badge size="sm" :color="$link->isActive() ? 'zinc' : 'red'">{{ $link->isActive() ? __('Expires :when', ['when' => $link->expires_at->diffForHumans()]) : __('Expired') }}</flux:badge>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>
        @endif
    </flux:modal>
</div>
