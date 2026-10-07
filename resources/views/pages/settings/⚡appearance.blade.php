<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Js;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    public string $palette = 'ferrite';

    public function mount(): void
    {
        $this->palette = Auth::user()->palette;
    }

    public function updatedPalette(): void
    {
        $this->validate(['palette' => ['required', Rule::in(User::PALETTES)]]);

        Auth::user()->forceFill(['palette' => $this->palette])->save();

        $this->js('document.documentElement.dataset.palette = '.Js::from($this->palette).'; document.documentElement.classList.toggle("dark", '.Js::from(User::isDarkPalette($this->palette)).'); try { localStorage.setItem("ferrite.palette", '.Js::from($this->palette).'); } catch (e) {}');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Pick the look of your Ferrite.')">
        <div class="grid gap-2">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('Theme') }}">
                @foreach ([
                    'ferrite' => [__('Ferrite'), 'moon'],
                    'ferrite-light' => [__('Ferrite light'), 'sun'],
                    'mac' => [__('Classic Mac'), 'computer-desktop'],
                    'desk95' => [__('Desktop 95'), 'window'],
                    'zine' => [__('Plain page'), 'newspaper'],
                    'bubblegum' => [__('Bubblegum 98'), 'sparkles'],
                    'amber' => [__('Amber terminal'), 'terminal'],
                    'phosphor' => [__('Green phosphor'), 'tv'],
                    'commodore' => [__('Commodore'), 'gamepad-2'],
                    'amiga' => [__('Amiga'), 'save'],
                ] as $key => [$label, $icon])
                    <button type="button" role="radio" aria-checked="{{ $palette === $key ? 'true' : 'false' }}" wire:click="$set('palette', '{{ $key }}')"
                        class="flex items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2.5 dark:border-zinc-700 text-start text-sm text-zinc-800 transition hover:border-accent aria-checked:border-accent aria-checked:ring-2 aria-checked:ring-accent/40 dark:text-white">
                        <flux:icon :name="$icon" class="size-6 shrink-0" />
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </x-pages::settings.layout>
</section>
