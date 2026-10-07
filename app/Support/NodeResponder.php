<?php

namespace App\Support;

use App\Models\Node;
use App\Models\StorageDisk;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Builds the responses that hand user files to the browser. Callers authorize access first.
 *
 * Every response carries `nosniff`. Files are only shown inline when FileKind allows it; the rest
 * is sent as an attachment. Text-like files are sent as text/plain, never as HTML.
 */
class NodeResponder
{
    private const CHUNK = 1024 * 1024;

    /** @var array<int, StorageDisk> */
    private array $zipDisks = [];

    public function __construct(private StorageManager $storage, private Thumbnailer $thumbnails) {}

    /**
     * Whether this request starts a download, as opposed to continuing one with a later Range.
     */
    public static function startsDownload(Request $request): bool
    {
        $range = $request->header('Range');

        return $range === null || str_starts_with(trim($range), 'bytes=0-');
    }

    /**
     * Stream a file with Range and ETag support, as an attachment or, when allowed, inline.
     */
    public function file(Request $request, Node $node, bool $inline = false): Response
    {
        $this->assertServable($node);
        $inlineType = $inline ? FileKind::inlineType($node->mime) : null;
        $size = $node->size;

        $headers = [
            'Content-Type' => $inlineType ?? ($node->mime ?: 'application/octet-stream'),
            'Content-Disposition' => $this->disposition($inlineType !== null ? 'inline' : 'attachment', $node->name),
            'Accept-Ranges' => 'bytes',
            'ETag' => $this->etag($node),
            'Cache-Control' => 'private, no-cache',
            ...$this->safetyHeaders(),
        ];

        if ($inlineType !== null && FileKind::of($node->mime) !== 'pdf') {
            // Even if something is rendered as a document, it cannot run scripts or load anything.
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        if ($this->matchesEtag($request, $headers['ETag'])) {
            return new Response(null, 304, ['ETag' => $headers['ETag'], 'Cache-Control' => $headers['Cache-Control']]);
        }

        $range = $this->range($request->header('Range'), $size);

        if ($range === false) {
            return new Response(null, 416, ['Content-Range' => "bytes */{$size}"] + $headers);
        }

        [$start, $end] = $range ?? [0, max(0, $size - 1)];
        $length = $size === 0 ? 0 : $end - $start + 1;
        $status = 200;

        if ($range !== null) {
            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $headers['Content-Length'] = (string) $length;

        return new StreamedResponse(function () use ($node, $start, $length) {
            // Big files to slow connections outlast any default time limit.
            set_time_limit(0);
            $this->copy($node, $start, $length);
        }, $status, $headers);
    }

    /**
     * A thumbnail JPEG, or null when the file has none.
     */
    public function thumbnail(Request $request, Node $node): ?Response
    {
        $etag = '"thumb-'.substr(hash('sha256', (string) $node->sha256), 0, 32).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, max-age=86400', ...$this->safetyHeaders()];

        if ($this->thumbnails->supports($node) && $this->matchesEtag($request, $etag)) {
            return new Response(null, 304, $headers);
        }

        $jpeg = $this->thumbnails->get($node);

        return $jpeg === null ? null : new Response($jpeg, 200, $headers + [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($jpeg),
        ]);
    }

    /**
     * Stream a folder and everything in it (trash excluded) as a ZIP built on the fly.
     */
    public function zip(Node $folder): StreamedResponse
    {
        return $this->zipResponse($folder->name.'.zip', function (ZipStream $zip) use ($folder) {
            $this->addFolder($zip, $folder);
        });
    }

    /**
     * Several files and folders of one folder in a single archive, each under its own name.
     *
     * @param  iterable<Node>  $nodes
     */
    public function zipMany(iterable $nodes, string $filename): StreamedResponse
    {
        return $this->zipResponse($filename, function (ZipStream $zip) use ($nodes) {
            foreach ($nodes as $node) {
                if ($node->isFolder()) {
                    $this->addFolder($zip, $node, "{$node->name}/");
                } else {
                    $this->addFile($zip, $node, '');
                }
            }
        });
    }

    /**
     * @param  callable(ZipStream): void  $fill
     */
    private function zipResponse(string $filename, callable $fill): StreamedResponse
    {
        return new StreamedResponse(function () use ($fill) {
            set_time_limit(0);
            $zip = new ZipStream(
                defaultCompressionMethod: CompressionMethod::STORE,
                sendHttpHeaders: false,
            );

            $fill($zip);
            $zip->finish();
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => $this->disposition('attachment', $filename),
            'Cache-Control' => 'private, no-store',
            'X-Accel-Buffering' => 'no',
            ...$this->safetyHeaders(),
        ]);
    }

    private function addFolder(ZipStream $zip, Node $root, string $rootPrefix = ''): void
    {
        $queue = [[$root, $rootPrefix]];

        while ($queue !== []) {
            [$folder, $prefix] = array_shift($queue);

            if ($prefix !== '') {
                $zip->addDirectory($prefix);
            }

            foreach (Node::query()->where('parent_id', $folder->id)->notTrashed()->orderBy('name')->get() as $child) {
                if ($child->isFolder()) {
                    $queue[] = [$child, "{$prefix}{$child->name}/"];
                } else {
                    $this->addFile($zip, $child, $prefix);
                }
            }
        }
    }

    private function addFile(ZipStream $zip, Node $file, string $prefix): void
    {
        if ($file->disk_id === null || $file->path === null) {
            return;
        }

        $disk = $this->zipDisks[$file->disk_id] ??= StorageDisk::query()->findOrFail($file->disk_id);
        $stream = $this->storage->openStream($disk, $file->path, $file->size);

        try {
            $zip->addFileFromStream("{$prefix}{$file->name}", $stream, lastModificationDateTime: $file->updated_at);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Write $length bytes starting at $start to the output.
     */
    private function copy(Node $node, int $start, int $length): void
    {
        if ($length === 0) {
            return;
        }

        $stream = $this->storage->openStream($node->disk, (string) $node->path, $node->size, $start);

        try {
            for ($remaining = $length; $remaining > 0 && ! feof($stream) && ! connection_aborted();) {
                $chunk = fread($stream, min(self::CHUNK, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Parse a single "bytes=a-b" range. Null means serve the whole file (no or unsupported
     * header, including multiple ranges), false means the range cannot be satisfied.
     *
     * @return array{int, int}|false|null
     */
    private function range(?string $header, int $size): array|false|null
    {
        if ($header === null || $size === 0 || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches)) {
            return null;
        }

        [, $first, $last] = $matches;

        if ($first === '' && $last === '') {
            return null;
        }

        if ($first === '') {
            $suffix = (int) $last;

            return $suffix === 0 ? false : [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $first;
        $end = $last === '' ? $size - 1 : min((int) $last, $size - 1);

        return $start >= $size || $start > $end ? false : [$start, $end];
    }

    private function assertServable(Node $node): void
    {
        abort_unless($node->isFile() && $node->disk !== null && $node->path !== null, 404);
    }

    private function etag(Node $node): string
    {
        return '"'.($node->sha256 ?? "{$node->id}-{$node->updated_at?->timestamp}").'"';
    }

    private function matchesEtag(Request $request, string $etag): bool
    {
        $given = $request->header('If-None-Match');

        return $given !== null && in_array($etag, array_map(fn ($tag) => trim(ltrim(trim($tag), 'W/')), explode(',', $given)), true);
    }

    private function disposition(string $type, string $name): string
    {
        $fallback = str_replace(['%', '/', '\\', '"'], '_', Str::ascii($name));

        return HeaderUtils::makeDisposition($type, $name, $fallback !== '' ? $fallback : 'download');
    }

    /**
     * @return array<string, string>
     */
    private function safetyHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
