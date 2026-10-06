<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
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

        <div class="mt-8 grid gap-2" x-data="{
            palette: document.documentElement.dataset.palette || 'plum',
            set(value) {
                this.palette = value;
                document.documentElement.dataset.palette = value;
                try { localStorage.setItem('shed.palette', value); } catch (e) {}
            },
        }">
            <flux:heading>{{ __('Dark palette') }}</flux:heading>
            <flux:text>{{ __('Colours used in dark mode.') }}</flux:text>
            <flux:radio.group variant="segmented" x-model="palette" x-on:change="set($event.target.value)">
                <flux:radio value="plum">{{ __('Plum') }}</flux:radio>
                <flux:radio value="wood">{{ __('Wood') }}</flux:radio>
            </flux:radio.group>
        </div>
    </x-pages::settings.layout>
</section>
