<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Js;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    public string $palette = 'plum';

    public function mount(): void
    {
        $this->palette = Auth::user()->palette;
    }

    public function updatedPalette(): void
    {
        $this->validate(['palette' => ['required', Rule::in(User::PALETTES)]]);

        Auth::user()->forceFill(['palette' => $this->palette])->save();

        $this->js('document.documentElement.dataset.palette = '.Js::from($this->palette).'; document.documentElement.classList.toggle("dark", '.Js::from($this->palette).' !== "light"); try { localStorage.setItem("shed.palette", '.Js::from($this->palette).'); } catch (e) {}');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <div class="grid gap-2">
            <flux:heading>{{ __('Theme') }}</flux:heading>
            <flux:text>{{ __('Pick the look of your Shed.') }}</flux:text>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('Theme') }}">
                @foreach ([
                    'light' => [__('Light'), '#f4ebdb'],
                    'plum' => [__('Plum'), '#4d2f4a'],
                    'wood' => [__('Wood'), '#4a3a2e'],
                    'teal' => [__('Teal'), '#2a4e53'],
                    'forest' => [__('Forest'), '#385436'],
                    'midnight' => [__('Midnight'), '#314a72'],
                    'olive' => [__('Olive'), '#555128'],
                    'berry' => [__('Berry'), '#6b2f4a'],
                    'charcoal' => [__('Charcoal'), '#403d3a'],
                    'sage' => [__('Sage'), '#566b58'],
                    'slate' => [__('Slate'), '#435364'],
                ] as $key => [$label, $colour])
                    <button type="button" role="radio" aria-checked="{{ $palette === $key ? 'true' : 'false' }}" wire:click="$set('palette', '{{ $key }}')"
                        class="flex items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2.5 dark:border-zinc-700 text-start text-sm text-zinc-800 transition hover:border-accent aria-checked:border-accent aria-checked:ring-2 aria-checked:ring-accent/40 dark:text-white">
                        <span class="flex size-8 shrink-0 overflow-hidden rounded-full border border-black/20 dark:border-white/30">
                            <span class="w-1/2" style="background: {{ $colour }}"></span>
                            <span class="w-1/2 bg-accent"></span>
                        </span>
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </x-pages::settings.layout>
</section>
