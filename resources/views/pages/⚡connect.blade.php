<?php

use App\Support\ApiAccess;
use App\Support\AppConnect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Browser approval for the native apps: the app opens this page, the user chooses what it may reach, and the
 * browser returns a one-time code to the app (see App\Support\AppConnect and docs/api.md).
 */
new #[Layout('layouts::auth'), Title('Connect an app')] class extends Component {
    /** What the app asked for, checked once on arrival and locked after that. @var array<string, string>|null */
    #[Locked]
    public ?array $request = null;

    /** Where the browser goes next (back to the app); set once the user has decided. */
    #[Locked]
    public ?string $returnUrl = null;

    /** Folder id as a string; empty is the whole drive. */
    public string $folder = '';

    public string $access = 'full';

    public string $expiry = '365';

    public function mount(Request $request): void
    {
        $this->request = app(AppConnect::class)->request($request->query());
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function folders(): array
    {
        return ApiAccess::folders(Auth::user());
    }

    public function approve(AppConnect $connect): void
    {
        abort_if($this->request === null || $this->returnUrl !== null, 403);

        $this->validate([
            'folder' => ['nullable', 'in:'.implode(',', array_keys($this->folders))],
            'access' => ['required', 'in:'.implode(',', array_keys(ApiAccess::LEVELS))],
            'expiry' => ['required', 'in:'.implode(',', array_keys(ApiAccess::EXPIRY_DAYS))],
        ]);

        $code = $connect->approve(Auth::user(), $this->request, $this->folder === '' ? null : (int) $this->folder, $this->access, ApiAccess::EXPIRY_DAYS[$this->expiry]);

        $this->returnUrl = $this->callback(['code' => $code]);
    }

    public function deny(): void
    {
        abort_if($this->request === null || $this->returnUrl !== null, 403);

        $this->returnUrl = $this->callback(['error' => 'access_denied']);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function callback(array $params): string
    {
        $state = (string) ($this->request['state'] ?? '');

        return $this->request['redirect'].'?'.http_build_query($params + ($state === '' ? [] : ['state' => $state]));
    }
}; ?>

<div class="flex flex-col gap-6" data-test="connect">
    @if ($request === null)
        <flux:callout icon="exclamation-triangle" variant="danger" :heading="__('This link is not valid')">
            <flux:callout.text>{{ __('The app asked for something this server does not accept. Go back to the app and try again, or update it.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($returnUrl !== null)
        <div x-data x-init="setTimeout(() => window.location.href = @js($returnUrl), 100)" class="flex flex-col gap-3 text-center">
            <flux:heading size="lg">{{ __('Back to the app…') }}</flux:heading>
            <flux:text>{{ __('You can close this tab once the app has opened.') }}</flux:text>
            <div>
                <flux:button :href="$returnUrl" variant="primary" data-test="return">{{ __('Open :app', ['app' => $request['client']]) }}</flux:button>
            </div>
        </div>
    @else
        <div class="flex flex-col gap-1 text-center">
            <flux:heading size="lg">{{ __(':app wants to connect', ['app' => $request['client']]) }}</flux:heading>
            <flux:text>
                {{ __('Signed in as :user.', ['user' => Auth::user()->email]) }}
                @if ($request['device'] !== '')
                    {{ __('Device: :device.', ['device' => $request['device']]) }}
                @endif
            </flux:text>
        </div>

        <form wire:submit="approve" class="flex flex-col gap-4">
            <flux:select wire:model="folder" :label="__('Folder')" :description="__('The app reaches this folder and what is inside it, nothing else.')">
                <flux:select.option value="">{{ __('Whole drive') }}</flux:select.option>
                @foreach ($this->folders as $id => $path)
                    <flux:select.option :value="$id">{{ $path }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="access" :label="__('Access')">
                <flux:select.option value="read">{{ __('Read') }}</flux:select.option>
                <flux:select.option value="write">{{ __('Read and add files (never delete)') }}</flux:select.option>
                <flux:select.option value="replace">{{ __('Read, add files and replace existing ones (the old content is kept as a version)') }}</flux:select.option>
                <flux:select.option value="trash">{{ __('Read, add files and move files to the trash') }}</flux:select.option>
                <flux:select.option value="full">{{ __('Read, add, replace and move files to the trash') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model="expiry" :label="__('Expires')">
                <flux:select.option value="never">{{ __('Never') }}</flux:select.option>
                <flux:select.option value="30">{{ __('In 30 days') }}</flux:select.option>
                <flux:select.option value="90">{{ __('In 90 days') }}</flux:select.option>
                <flux:select.option value="365">{{ __('In 1 year') }}</flux:select.option>
            </flux:select>

            <flux:text size="sm">{{ __('It appears in Settings > API tokens, where you can revoke it at any time.') }}</flux:text>

            <div class="flex gap-2">
                <flux:button type="button" wire:click="deny" variant="filled" class="flex-1" data-test="deny">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1" data-test="approve">{{ __('Allow') }}</flux:button>
            </div>
        </form>
    @endif
</div>
