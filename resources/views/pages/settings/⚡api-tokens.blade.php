<?php

use App\Enums\NodeType;
use App\Models\ApiToken;
use App\Models\Node;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('API tokens')] class extends Component {
    private const EXPIRY_DAYS = ['never' => null, '30' => 30, '90' => 90, '365' => 365];

    public string $name = '';

    /** Folder id as a string; empty is the whole drive. */
    public string $folder = '';

    public string $access = 'read';

    public string $expiry = '90';

    /** The new token in clear text: shown once, never stored. */
    #[Locked]
    public ?string $plainToken = null;

    /**
     * The user's folders as id => "Parent / Child", trashed ones left out.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function folders(): array
    {
        $rows = Node::query()
            ->where('owner_id', Auth::id())
            ->where('type', NodeType::Folder)
            ->whereNull('trashed_at')
            ->get(['id', 'parent_id', 'name'])
            ->keyBy('id');

        $paths = [];
        foreach ($rows as $row) {
            $parts = [];
            for ($node = $row, $guard = 0; $node !== null && $guard < 100; $node = $rows->get($node->parent_id), $guard++) {
                array_unshift($parts, $node->name);
            }
            // A folder whose parent is trashed has a parent missing from $rows: its path is not complete, so skip it.
            if ($row->parent_id === null || $rows->has($row->parent_id)) {
                $paths[$row->id] = implode(' / ', $parts);
            }
        }

        asort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return $paths;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ApiToken>
     */
    #[Computed]
    public function tokens(): \Illuminate\Support\Collection
    {
        return ApiToken::query()
            ->with('folder')
            ->where('tokenable_type', Auth::user()->getMorphClass())
            ->where('tokenable_id', Auth::id())
            ->latest('id')
            ->get();
    }

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'folder' => ['nullable', 'in:'.implode(',', array_keys($this->folders))],
            'access' => ['required', 'in:read,write'],
            'expiry' => ['required', 'in:'.implode(',', array_keys(self::EXPIRY_DAYS))],
        ]);

        $days = self::EXPIRY_DAYS[$this->expiry];
        $abilities = $this->access === 'write' ? ['read', 'write'] : ['read'];

        $created = Auth::user()->createToken($this->name, $abilities, $days === null ? null : now()->addDays($days));
        $created->accessToken->forceFill(['folder_id' => $this->folder === '' ? null : (int) $this->folder])->save();

        $this->plainToken = $created->plainTextToken;
        $this->reset('name', 'folder', 'access');
        unset($this->tokens);
    }

    public function dismiss(): void
    {
        $this->plainToken = null;
    }

    public function revoke(int $id): void
    {
        $this->tokens->firstWhere('id', $id)?->delete();
        unset($this->tokens);

        Flux::toast(variant: 'success', text: __('Token revoked.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('API tokens') }}</flux:heading>

    <x-pages::settings.layout :heading="__('API tokens')" :subheading="__('Let a script or another app reach one folder of your drive.')">
        <div class="flex flex-col gap-8">
            @if ($plainToken)
                <flux:callout icon="key" variant="success" :heading="__('Copy your token now')" data-test="new-token">
                    <flux:callout.text>
                        {{ __('It is shown only once. API address:') }} <code>{{ url('/api/v1') }}</code>
                    </flux:callout.text>
                    <div x-data="{ copied: false }" class="mt-3 flex items-center gap-2">
                        <code class="min-w-0 flex-1 break-all rounded bg-zinc-100 px-2 py-1.5 text-xs dark:bg-zinc-800" data-test="token-value">{{ $plainToken }}</code>
                        <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText(@js($plainToken)); copied = true; setTimeout(() => copied = false, 1500)">
                            <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
                        </flux:button>
                    </div>
                    <div class="mt-3">
                        <flux:button size="sm" variant="ghost" wire:click="dismiss">{{ __('I have saved it') }}</flux:button>
                    </div>
                </flux:callout>
            @endif

            <form wire:submit="create" class="flex flex-col gap-4">
                <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Magnetite')" required />

                <flux:select wire:model="folder" :label="__('Folder')" :description="__('The token reaches this folder and what is inside it, nothing else.')">
                    <flux:select.option value="">{{ __('Whole drive (not recommended)') }}</flux:select.option>
                    @foreach ($this->folders as $id => $path)
                        <flux:select.option :value="$id">{{ $path }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="access" :label="__('Access')">
                    <flux:select.option value="read">{{ __('Read') }}</flux:select.option>
                    <flux:select.option value="write">{{ __('Read and add files (never delete)') }}</flux:select.option>
                </flux:select>

                <flux:select wire:model="expiry" :label="__('Expires')">
                    <flux:select.option value="never">{{ __('Never') }}</flux:select.option>
                    <flux:select.option value="30">{{ __('In 30 days') }}</flux:select.option>
                    <flux:select.option value="90">{{ __('In 90 days') }}</flux:select.option>
                    <flux:select.option value="365">{{ __('In 1 year') }}</flux:select.option>
                </flux:select>

                <div>
                    <flux:button type="submit" variant="primary">{{ __('Create token') }}</flux:button>
                </div>
            </form>

            @if ($this->tokens->isNotEmpty())
                <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                    @foreach ($this->tokens as $token)
                        <li class="flex items-start justify-between gap-4 px-4 py-3 text-sm" wire:key="token-{{ $token->id }}" data-test="token-row">
                            <div class="min-w-0">
                                <div class="font-medium">{{ $token->name }}</div>
                                <div class="text-xs text-zinc-500">
                                    {{ $token->folder_id === null ? __('Whole drive') : ($token->folder?->name ?? __('Folder gone')) }}
                                    · {{ $token->can('write') ? __('read + write') : __('read') }}
                                    · {{ $token->expires_at ? ($token->expires_at->isPast() ? __('expired') : __('expires :when', ['when' => $token->expires_at->diffForHumans()])) : __('never expires') }}
                                </div>
                                <div class="text-xs text-zinc-500">
                                    {{ __('Created :when', ['when' => $token->created_at->diffForHumans()]) }}
                                    · {{ $token->last_used_at ? __('used :when from :ip', ['when' => $token->last_used_at->diffForHumans(), 'ip' => $token->last_used_ip ?? '?']) : __('never used') }}
                                </div>
                            </div>
                            <flux:button size="sm" variant="danger" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('Revoke this token? Anything using it stops working.') }}">{{ __('Revoke') }}</flux:button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
