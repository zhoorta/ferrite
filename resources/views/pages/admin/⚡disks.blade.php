<?php

use App\Actions\Storage\DeleteStorageDisk;
use App\Actions\Storage\SaveStorageDisk;
use App\Actions\Storage\SetDefaultStorageDisk;
use App\Actions\Storage\TestStorageDisk;
use App\Enums\DiskDriver;
use App\Models\StorageDisk;
use App\Support\StorageManager;
use App\Support\StorageUsage;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Storage')] class extends Component {
    public ?int $diskId = null;

    public string $name = '';

    public string $driver = 'local';

    /** @var array<string, mixed> */
    public array $config = [];

    /** @var array<int, array{ok: bool, message: string}> */
    public array $results = [];

    public function mount(): void
    {
        Gate::authorize('admin');

        // Make sure the built-in local disk exists, so there is always a default to show.
        app(StorageManager::class)->default();
    }

    /**
     * @return Collection<int, StorageDisk>
     */
    #[Computed]
    public function disks(): Collection
    {
        return StorageDisk::query()
            ->withCount('nodes')
            ->withSum('nodes', 'size')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * Bytes really stored per disk id: a blob shared by several nodes (deduplication) counts once.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function physical(): array
    {
        return app(StorageUsage::class)->physicalByDisk();
    }

    #[Computed]
    public function driverEnum(): DiskDriver
    {
        return DiskDriver::tryFrom($this->driver) ?? DiskDriver::Local;
    }

    public function add(): void
    {
        Gate::authorize('admin');

        $this->reset('diskId', 'name', 'config');
        $this->driver = 'local';
        $this->resetErrorBag();
        Flux::modal('disk')->show();
    }

    public function edit(int $id): void
    {
        Gate::authorize('admin');

        $disk = StorageDisk::findOrFail($id);
        $secrets = DiskDriver::from($disk->driver)->secretKeys();

        $this->diskId = $disk->id;
        $this->name = $disk->name;
        $this->driver = $disk->driver;
        // Secrets are never sent to the browser; leaving them blank keeps the stored value.
        $this->config = collect($disk->config ?? [])->except($secrets)->all();
        $this->resetErrorBag();
        Flux::modal('disk')->show();
    }

    public function save(SaveStorageDisk $action): void
    {
        $disk = $action->handle(Auth::user(), $this->diskId === null ? null : StorageDisk::findOrFail($this->diskId), [
            'name' => $this->name,
            'driver' => $this->driver,
            'config' => $this->config,
        ]);

        unset($this->disks);
        Flux::modal('disk')->close();
        Flux::toast(variant: 'success', text: __('Saved ":name".', ['name' => $disk->name]));
    }

    public function test(int $id, TestStorageDisk $action): void
    {
        $error = $action->handle(Auth::user(), StorageDisk::findOrFail($id));

        $this->results[$id] = ['ok' => $error === null, 'message' => $error ?? __('Connection works.')];
    }

    public function makeDefault(int $id, SetDefaultStorageDisk $action): void
    {
        $action->handle(Auth::user(), StorageDisk::findOrFail($id));

        unset($this->disks);
    }

    public function delete(int $id, DeleteStorageDisk $action): void
    {
        $action->handle(Auth::user(), StorageDisk::findOrFail($id));

        unset($this->disks);
        Flux::toast(variant: 'success', text: __('Disk removed.'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex items-center justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Storage') }}</flux:heading>
            <flux:text>{{ __('New uploads go to the default disk. Files already stored stay on the disk they were written to.') }}</flux:text>
        </div>
        <flux:button icon="plus" variant="primary" wire:click="add">{{ __('Add disk') }}</flux:button>
    </div>

    <flux:error name="disk" />

    <div class="space-y-3">
        @foreach ($this->disks as $disk)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="disk-{{ $disk->id }}" data-test="disk-row">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $disk->name }}</span>
                            <flux:badge size="sm">{{ \App\Enums\DiskDriver::from($disk->driver)->label() }}</flux:badge>
                            @if ($disk->is_default)<flux:badge size="sm" color="green">{{ __('Default') }}</flux:badge>@endif
                        </div>
                        <flux:text size="sm">
                            {{ trans_choice(':count file|:count files', $disk->nodes_count) }},
                            {{ \Illuminate\Support\Number::fileSize($this->physical[$disk->id] ?? 0) }} {{ __('stored') }}
                            @if ((int) $disk->nodes_sum_size > ($this->physical[$disk->id] ?? 0))
                                ({{ __(':size counted in quotas, the rest is shared by duplicates', ['size' => \Illuminate\Support\Number::fileSize((int) $disk->nodes_sum_size)]) }})
                            @endif
                        </flux:text>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" wire:click="test({{ $disk->id }})">{{ __('Test') }}</flux:button>
                        <flux:button size="sm" wire:click="edit({{ $disk->id }})">{{ __('Edit') }}</flux:button>
                        @unless ($disk->is_default)
                            <flux:button size="sm" wire:click="makeDefault({{ $disk->id }})">{{ __('Make default') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="delete({{ $disk->id }})" wire:confirm="{{ __('Remove this disk? Files on the remote storage are not deleted.') }}">{{ __('Remove') }}</flux:button>
                        @endunless
                    </div>
                </div>

                @isset($results[$disk->id])
                    <p class="mt-2 text-sm {{ $results[$disk->id]['ok'] ? 'text-green-600' : 'text-red-600' }}" data-test="disk-result">{{ $results[$disk->id]['message'] }}</p>
                @endisset
            </div>
        @endforeach
    </div>

    <flux:modal name="disk" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $diskId ? __('Edit disk') : __('Add disk') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" />

            @unless ($diskId)
                <flux:select wire:model.live="driver" :label="__('Type')">
                    @foreach (\App\Enums\DiskDriver::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endunless

            @foreach ($this->driverEnum->fields() as $field)
                <div wire:key="field-{{ $driver }}-{{ $field['key'] }}">
                    @if ($field['type'] === 'checkbox')
                        <flux:checkbox wire:model="config.{{ $field['key'] }}" :label="$field['label']" />
                    @elseif ($field['type'] === 'textarea')
                        <flux:textarea wire:model="config.{{ $field['key'] }}" :label="$field['label']" rows="4"
                            :placeholder="$diskId && $field['secret'] ? __('Unchanged') : ''" />
                    @else
                        <flux:input wire:model="config.{{ $field['key'] }}" :type="$field['type']" :label="$field['label']"
                            :required="$field['required'] && ! ($diskId && $field['secret'])"
                            :placeholder="$diskId && $field['secret'] ? __('Unchanged') : ''" autocomplete="off" />
                    @endif
                    @isset($field['hint'])<flux:text size="sm" class="mt-1">{{ $field['hint'] }}</flux:text>@endisset
                    <flux:error name="config.{{ $field['key'] }}" />
                </div>
            @endforeach

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
