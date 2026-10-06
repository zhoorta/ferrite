@props(['kind', 'url', 'name', 'text' => null])

@switch($kind)
    @case('image')
        <img src="{{ $url }}" alt="{{ $name }}" class="mx-auto max-h-[70vh] max-w-full object-contain">
        @break
    @case('pdf')
        <iframe src="{{ $url }}" title="{{ $name }}" class="h-[70vh] w-full rounded border border-zinc-200 dark:border-zinc-700"></iframe>
        @break
    @case('video')
        <video src="{{ $url }}" controls preload="metadata" class="mx-auto max-h-[70vh] w-full"></video>
        @break
    @case('audio')
        <audio src="{{ $url }}" controls preload="metadata" class="w-full"></audio>
        @break
    @case('text')
        <pre class="max-h-[70vh] overflow-auto whitespace-pre-wrap break-words rounded bg-zinc-50 p-3 text-sm dark:bg-zinc-900" data-test="preview-text">{{ $text['text'] ?? '' }}</pre>
        @if ($text['truncated'] ?? false)
            <flux:text size="sm">{{ __('Only the start of the file is shown.') }}</flux:text>
        @endif
        @break
    @default
        <flux:callout icon="document" :heading="__('No preview available for this file type')" />
@endswitch
