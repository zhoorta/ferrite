<?php

use App\Support\StorageUsage;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Usage')] class extends Component {
    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function usage(): array
    {
        return app(StorageUsage::class)->forUser(Auth::user());
    }
}; ?>

@php
    use Illuminate\Support\Number;

    $usage = $this->usage;
    $kinds = [
        'image' => ['label' => __('Images'), 'icon' => 'photo'],
        'video' => ['label' => __('Videos'), 'icon' => 'film'],
        'audio' => ['label' => __('Audio'), 'icon' => 'musical-note'],
        'document' => ['label' => __('Documents'), 'icon' => 'document-text'],
        'archive' => ['label' => __('Archives'), 'icon' => 'archive-box'],
        'other' => ['label' => __('Other'), 'icon' => 'document'],
    ];
    // One accent, stepped in strength, so the chart follows every theme.
    $opacity = ['image' => 1, 'video' => .8, 'audio' => .62, 'document' => .46, 'archive' => .32, 'other' => .2];
    $share = fn (int $bytes, int $total) => $total > 0 ? round($bytes / $total * 100, 1) : 0;
    $quotaPercent = $usage['quota'] ? min(100, $share($usage['used'], $usage['quota'])) : null;
@endphp

<div class="mx-auto flex w-full max-w-5xl flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Usage') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Where your space goes. Shared files count for their owner, not for you.') }}</flux:text>
    </div>

    <section class="flex flex-col gap-3" data-test="usage-summary">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <flux:heading size="lg" level="2">
                {{ Number::fileSize($usage['used']) }}
                <span class="font-normal text-zinc-500">{{ $usage['quota'] ? __('of :quota', ['quota' => Number::fileSize($usage['quota'])]) : __('used, no quota') }}</span>
            </flux:heading>
            @if ($usage['quota'])
                <flux:text>{{ __(':free free', ['free' => Number::fileSize(max(0, $usage['quota'] - $usage['used']))]) }}</flux:text>
            @endif
        </div>

        @if ($quotaPercent !== null)
            <div class="h-2.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $quotaPercent }}" aria-label="{{ __('Quota used') }}">
                <div class="h-full rounded-full bg-accent" style="width: {{ $quotaPercent }}%"></div>
            </div>
        @endif

        <flux:text class="text-sm">
            {{ trans_choice(':count file|:count files', $usage['files']) }}, {{ Number::fileSize($usage['active']) }}
            · {{ __('Trash') }} {{ Number::fileSize($usage['trash']) }}
            @if ($usage['saved'] > 0)
                · {{ __('Deduplication saves :size', ['size' => Number::fileSize($usage['saved'])]) }}
            @endif
        </flux:text>
    </section>

    @if ($usage['files'] === 0)
        <flux:callout icon="chart-pie" :heading="__('Nothing here yet')" :text="__('Upload some files and the breakdown shows up here.')" />
    @else
        <section class="flex flex-col gap-3" data-test="usage-kinds">
            <flux:heading size="lg" level="2">{{ __('By type') }}</flux:heading>

            <div class="flex h-3 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                @foreach ($kinds as $key => $meta)
                    @if ($usage['kinds'][$key]['bytes'] > 0)
                        <div class="h-full bg-accent" style="width: {{ $share($usage['kinds'][$key]['bytes'], $usage['active']) }}%; opacity: {{ $opacity[$key] }}" title="{{ $meta['label'] }}"></div>
                    @endif
                @endforeach
            </div>

            <ul class="grid gap-x-8 gap-y-2 sm:grid-cols-2">
                @foreach ($kinds as $key => $meta)
                    <li class="flex items-center gap-3 text-sm" data-test="usage-kind-{{ $key }}">
                        <span class="size-3 shrink-0 rounded-full bg-accent" style="opacity: {{ $opacity[$key] }}"></span>
                        <span class="flex-1">{{ $meta['label'] }}</span>
                        <span class="text-zinc-500">{{ trans_choice(':count file|:count files', $usage['kinds'][$key]['count']) }}</span>
                        <span class="w-20 text-end font-medium tabular-nums">{{ Number::fileSize($usage['kinds'][$key]['bytes']) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="grid gap-8 lg:grid-cols-2">
            <section class="flex flex-col gap-3" data-test="usage-folders">
                <flux:heading size="lg" level="2">{{ __('Biggest folders') }}</flux:heading>

                <ul class="flex flex-col gap-2">
                    @foreach ($usage['folders'] as $folder)
                        <li class="flex items-center gap-3 text-sm">
                            <flux:icon name="folder" class="size-5 shrink-0 text-zinc-400" />
                            <flux:link :href="route('files', $folder['id'])" wire:navigate variant="ghost" class="flex-1 truncate font-medium">{{ $folder['name'] }}</flux:link>
                            <span class="w-20 text-end font-medium tabular-nums">{{ Number::fileSize($folder['bytes']) }}</span>
                        </li>
                    @endforeach
                    @if ($usage['loose'] > 0)
                        <li class="flex items-center gap-3 text-sm text-zinc-500">
                            <flux:icon name="document" class="size-5 shrink-0 text-zinc-400" />
                            <span class="flex-1">{{ __('Files outside any folder') }}</span>
                            <span class="w-20 text-end tabular-nums">{{ Number::fileSize($usage['loose']) }}</span>
                        </li>
                    @endif
                </ul>
            </section>

            <section class="flex flex-col gap-3" data-test="usage-largest">
                <flux:heading size="lg" level="2">{{ __('Biggest files') }}</flux:heading>

                <ul class="flex flex-col gap-2">
                    @foreach ($usage['largest'] as $file)
                        <li class="flex items-center gap-3 text-sm">
                            <flux:icon name="document" class="size-5 shrink-0 text-zinc-400" />
                            <span class="flex-1 truncate" title="{{ $file['name'] }}">{{ $file['name'] }}</span>
                            <span class="w-20 text-end font-medium tabular-nums">{{ Number::fileSize($file['size']) }}</span>
                            <flux:button size="xs" variant="ghost" icon="folder-open" :href="route('files', $file['parent_id'])" wire:navigate :aria-label="__('Open containing folder')" />
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    @endif
</div>
