<?php

use App\Actions\Users\DeleteUser;
use App\Actions\Users\SaveUser;
use App\Actions\Users\SetUserDisabled;
use App\Enums\UserRole;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Users')] class extends Component {
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = 'user';

    public string $quotaGb = '';

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()->orderBy('name')->get();
    }

    public function add(): void
    {
        Gate::authorize('admin');

        $this->reset('userId', 'name', 'email', 'password', 'quotaGb');
        $this->role = 'user';
        $this->resetErrorBag();
        Flux::modal('user')->show();
    }

    public function edit(int $id): void
    {
        Gate::authorize('admin');

        $user = User::findOrFail($id);

        $this->userId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->role = $user->role->value;
        $this->quotaGb = $user->quota_bytes === null ? '' : (string) round($user->quota_bytes / 1024 ** 3, 2);
        $this->resetErrorBag();
        Flux::modal('user')->show();
    }

    public function save(SaveUser $action): void
    {
        $user = $action->handle(Auth::user(), $this->userId === null ? null : User::findOrFail($this->userId), [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'role' => $this->role,
            'quota_gb' => $this->quotaGb,
        ]);

        $this->reset('password');
        unset($this->users);
        Flux::modal('user')->close();
        Flux::toast(variant: 'success', text: __('Saved :name.', ['name' => $user->name]));
    }

    public function toggleDisabled(int $id, SetUserDisabled $action): void
    {
        $user = User::findOrFail($id);
        $action->handle(Auth::user(), $user, ! $user->isDisabled());

        unset($this->users);
    }

    public function delete(int $id, DeleteUser $action): void
    {
        $action->handle(Auth::user(), User::findOrFail($id));

        unset($this->users);
        Flux::toast(variant: 'success', text: __('User deleted.'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex items-center justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
            <flux:text>{{ __('People who can sign in. Quota limits what each person stores; empty means unlimited.') }}</flux:text>
        </div>
        <flux:button icon="plus" variant="primary" wire:click="add">{{ __('Add user') }}</flux:button>
    </div>

    <flux:error name="user" />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('User') }}</flux:table.column>
            <flux:table.column class="hidden sm:table-cell">{{ __('Storage') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->users as $user)
                <flux:table.row :key="$user->id" data-test="user-row">
                    <flux:table.cell>
                        <div class="flex items-center gap-3">
                            <flux:avatar size="sm" :name="$user->name" :initials="$user->initials()" />
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ $user->name }}</span>
                                    @if ($user->isAdmin())<flux:badge size="sm" color="blue">{{ __('Admin') }}</flux:badge>@endif
                                    @if ($user->isDisabled())<flux:badge size="sm" color="red">{{ __('Disabled') }}</flux:badge>@endif
                                </div>
                                <div class="truncate text-sm text-zinc-500">{{ $user->email }}</div>
                            </div>
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="hidden sm:table-cell">
                        {{ Number::fileSize($user->used_bytes) }}
                        {{ $user->quota_bytes === null ? '' : __('of :quota', ['quota' => Number::fileSize($user->quota_bytes)]) }}
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown position="bottom" align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Actions')" />
                            <flux:menu>
                                <flux:menu.item icon="pencil" wire:click="edit({{ $user->id }})">{{ __('Edit') }}</flux:menu.item>
                                @unless ($user->is(auth()->user()))
                                    <flux:menu.item :icon="$user->isDisabled() ? 'play' : 'pause'" wire:click="toggleDisabled({{ $user->id }})">
                                        {{ $user->isDisabled() ? __('Enable') : __('Disable') }}
                                    </flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $user->id }})"
                                        wire:confirm="{{ __('Delete this user and permanently delete all their files? This cannot be undone.') }}">
                                        {{ __('Delete with files') }}
                                    </flux:menu.item>
                                @endunless
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="user" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $userId ? __('Edit user') : __('Add user') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" />
            <flux:input wire:model="email" type="email" :label="__('E-mail')" />
            <flux:input wire:model="password" type="password" :label="$userId ? __('New password (leave blank to keep)') : __('Password')" autocomplete="new-password" viewable />

            <flux:select wire:model="role" :label="__('Role')">
                <flux:select.option value="user">{{ __('User') }}</flux:select.option>
                <flux:select.option value="admin">{{ __('Admin') }}</flux:select.option>
            </flux:select>
            <flux:error name="role" />

            <flux:input wire:model="quotaGb" type="number" min="0" step="any" :label="__('Quota (GB)')" :placeholder="__('Unlimited')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
