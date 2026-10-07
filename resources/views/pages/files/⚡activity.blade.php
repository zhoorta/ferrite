<?php

use App\Models\Activity;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activity')] class extends Component {
    use WithPagination;

    /**
     * What happened to the user's files, and what they did themselves.
     *
     * @return Paginator<int, Activity>
     */
    #[Computed]
    public function entries(): Paginator
    {
        return Activity::query()
            ->with('actor')
            ->where(fn ($query) => $query->where('owner_id', Auth::id())->orWhere('actor_id', Auth::id()))
            ->latest('created_at')
            ->latest('id')
            ->simplePaginate(30);
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Activity') }}</flux:heading>
        <flux:text>{{ __('What happened to your files, kept for :days days.', ['days' => config('ferrite.activity_days')]) }}</flux:text>
    </div>

    @if ($this->entries->isEmpty())
        <flux:callout icon="clock" :heading="__('Nothing has happened yet')" />
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
            @foreach ($this->entries as $entry)
                <li class="flex items-baseline justify-between gap-4 px-4 py-3 text-sm" wire:key="activity-{{ $entry->id }}" data-test="activity-row">
                    <span>{{ $entry->sentence(auth()->user()) }}</span>
                    <time class="shrink-0 text-xs text-zinc-500" datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $entry->created_at->toDayDateTimeString() }}">
                        {{ $entry->created_at->diffForHumans() }}
                    </time>
                </li>
            @endforeach
        </ul>

        <div class="flex justify-between">
            <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="previousPage" :disabled="$this->entries->onFirstPage()">{{ __('Newer') }}</flux:button>
            <flux:button size="sm" variant="ghost" icon-trailing="arrow-right" wire:click="nextPage" :disabled="! $this->entries->hasMorePages()">{{ __('Older') }}</flux:button>
        </div>
    @endif
</div>
