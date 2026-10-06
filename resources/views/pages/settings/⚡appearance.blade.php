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

        $this->js('document.documentElement.dataset.palette = '.Js::from($this->palette).'; try { localStorage.setItem("shed.palette", '.Js::from($this->palette).'); } catch (e) {}');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
        </flux:radio.group>

        <div class="mt-8 grid gap-2">
            <flux:heading>{{ __('Dark palette') }}</flux:heading>
            <flux:text>{{ __('Colours used in dark mode.') }}</flux:text>
            <flux:radio.group variant="segmented" wire:model.live="palette">
                <flux:radio value="plum">{{ __('Plum') }}</flux:radio>
                <flux:radio value="wood">{{ __('Wood') }}</flux:radio>
            </flux:radio.group>
        </div>
    </x-pages::settings.layout>
</section>
