@props(['node', 'kind', 'text' => null, 'nav'])

{{-- The file preview dialog. The page it sits in provides `previewStep(-1|1)` and `closePreview()`. --}}
<flux:modal name="preview" class="w-full max-w-4xl" x-on:close="$wire.closePreview()">
        @if ($node)
            {{-- Stable wrapper: it goes full screen, so it must survive stepping to another file. --}}
            <div x-data="{ full: false, labels: @js(['enter' => __('Full screen'), 'exit' => __('Exit full screen')]) }" x-on:fullscreenchange="full = document.fullscreenElement === $root"
                class="[&:fullscreen]:flex [&:fullscreen]:flex-col [&:fullscreen]:justify-center [&:fullscreen]:overflow-auto [&:fullscreen]:bg-zinc-50 [&:fullscreen]:p-6 dark:[&:fullscreen]:bg-zinc-800 [&:fullscreen_:is(img,video)]:max-h-[calc(100vh-10rem)] [&:fullscreen_iframe]:h-[calc(100vh-10rem)] [&:fullscreen_pre]:max-h-[calc(100vh-10rem)]">
            <div class="space-y-4" wire:key="preview-{{ $node->id }}"
                x-on:keydown.left.window="if (!$event.target.closest('audio, video, input, textarea')) $wire.previewStep(-1)"
                x-on:keydown.right.window="if (!$event.target.closest('audio, video, input, textarea')) $wire.previewStep(1)">
                <flux:heading size="lg" class="truncate pe-8">{{ $node->name }}</flux:heading>

                <div class="relative" data-test="preview-nav">
                    <x-file-preview
                        :kind="$kind"
                        :url="route('nodes.preview', $node)"
                        :name="$node->name"
                        :text="$text" />

                    @if ($nav['total'] > 1)
                        <div class="absolute inset-y-0 start-2 flex items-center">
                            <button type="button" @disabled($nav['prev'] === null) class="disabled:cursor-not-allowed disabled:opacity-30 flex size-10 items-center justify-center rounded-full bg-black/60 text-white shadow-md ring-1 ring-white/40 backdrop-blur-sm transition-colors enabled:hover:bg-black/80 focus-visible:outline-2 focus-visible:outline-white" wire:click="previewStep(-1)" aria-label="{{ __('Previous') }}" data-test="preview-prev">
                                <flux:icon name="chevron-left" class="size-6" />
                            </button>
                        </div>
                    @endif
                    @if ($nav['total'] > 1)
                        <div class="absolute inset-y-0 end-2 flex items-center">
                            <button type="button" @disabled($nav['next'] === null) class="disabled:cursor-not-allowed disabled:opacity-30 flex size-10 items-center justify-center rounded-full bg-black/60 text-white shadow-md ring-1 ring-white/40 backdrop-blur-sm transition-colors enabled:hover:bg-black/80 focus-visible:outline-2 focus-visible:outline-white" wire:click="previewStep(1)" aria-label="{{ __('Next') }}" data-test="preview-next">
                                <flux:icon name="chevron-right" class="size-6" />
                            </button>
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-between gap-2">
                    <flux:text size="sm">{{ $nav['position'] }} / {{ $nav['total'] }}</flux:text>
                    <div class="flex items-center gap-2">
                        <flux:button icon="arrows-pointing-out" x-show="document.fullscreenEnabled" x-cloak
                            x-on:click="full ? document.exitFullscreen() : $root.requestFullscreen()"
                            x-bind:aria-label="full ? labels.exit : labels.enter" data-test="preview-fullscreen" />
                        <flux:button icon="arrow-down-tray" :href="route('nodes.download', $node)">{{ __('Download') }}</flux:button>
                        <flux:modal.close><flux:button variant="filled" data-test="preview-close">{{ __('Close') }}</flux:button></flux:modal.close>
                    </div>
                </div>
            </div>
            </div>
        @endif
    </flux:modal>
