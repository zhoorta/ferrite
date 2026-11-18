<?php

namespace App\Support;

use App\Enums\NodeType;
use App\Models\Node;
use App\Models\StorageDisk;
use App\Models\Upload;
use App\Support\Encryption\BlobCipher;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Compares what the database says is stored with what is on the disks (`ferrite:storage:check`).
 *
 * A "blob" is one (disk, path): identical files share it. The check is cheap (does it exist, does it have the size
 * its file promises); the scrub (`$verify`) reads every blob back, decrypting where needed, and compares the sha256
 * of the content with the one stored for the file, which is how bit rot, a truncated copy or a swapped blob shows up.
 * Nothing here repairs or deletes anything.
 */
class StorageChecker
{
    public const MISSING = 'missing';

    public const SIZE = 'size';

    public const CORRUPT = 'corrupt';

    public const UNREADABLE = 'unreadable';

    public function __construct(private StorageManager $storage) {}

    /**
     * @param  (callable(int, int): void)|null  $progress  called with the number of blobs checked and the number to check
     * @return array{checked: int, bytes: int, problems: list<array{kind: string, disk: string, path: string, node_id: int, name: string, detail: string}>}
     */
    public function blobs(?StorageDisk $only = null, bool $verify = false, int $limit = 0, ?callable $progress = null): array
    {
        $disks = [];
        $problems = [];
        $checked = 0;
        $bytes = 0;
        $total = $this->count($only);
        $total = $limit > 0 ? min($limit, $total) : $total;

        foreach ($this->blobRows($only, $limit) as $row) {
            $sample = Node::query()->find((int) $row->first_id);

            if ($sample === null) {
                continue;
            }

            $disk = $disks[$row->disk_id] ??= StorageDisk::query()->findOrFail((int) $row->disk_id);
            $problem = $this->checkBlob($disk, $sample, $verify);

            if ($problem !== null) {
                $problems[] = ['kind' => $problem[0], 'disk' => $disk->name, 'path' => (string) $row->path, 'node_id' => $sample->id, 'name' => $sample->name, 'detail' => $problem[1]];
            } elseif ($verify) {
                DB::table('nodes')->where('disk_id', $disk->id)->where('path', $row->path)->update(['verified_at' => now()]);
            }

            $checked++;
            $bytes += $sample->size;

            if ($progress !== null) {
                $progress($checked, $total);
            }
        }

        return compact('checked', 'bytes', 'problems');
    }

    /**
     * Blobs on a disk that no file and no unfinished upload points at. Thumbnails are not listed. Needs a listing of
     * the whole disk, which is slow on a big remote one.
     *
     * @return Generator<int, string>
     */
    public function orphans(StorageDisk $disk): Generator
    {
        $filesystem = $this->storage->filesystem($disk);

        if (! $filesystem instanceof FilesystemAdapter) {
            throw new RuntimeException('Cannot list this disk.');
        }

        $batch = [];

        foreach ($filesystem->getDriver()->listContents('', true) as $item) {
            if (! $item->isFile() || str_starts_with($item->path(), 'thumbnails/')) {
                continue;
            }

            $batch[] = $item->path();

            if (count($batch) >= 500) {
                yield from $this->unreferenced($disk, $batch);
                $batch = [];
            }
        }

        yield from $this->unreferenced($disk, $batch);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function unreferenced(StorageDisk $disk, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $used = DB::table('nodes')->where('disk_id', $disk->id)->whereIn('path', $paths)->pluck('path')->all();
        $pending = Upload::query()->where('disk_id', $disk->id)->whereIn('blob_key', $paths)->pluck('blob_key')->all();

        return array_values(array_diff($paths, $used, $pending));
    }

    /**
     * @return array{string, string}|null the kind of problem and what is wrong, null when the blob is fine
     */
    private function checkBlob(StorageDisk $disk, Node $sample, bool $verify): ?array
    {
        $path = (string) $sample->path;
        $expected = $sample->encryption_key_id === null ? $sample->size : BlobCipher::cipherSize($sample->size);

        try {
            $filesystem = $this->storage->filesystem($disk);

            if (! $filesystem->exists($path)) {
                return [self::MISSING, 'the blob is not on the disk'];
            }

            $actual = $filesystem->size($path);

            if ($actual !== $expected) {
                return [self::SIZE, "{$actual} bytes on the disk, expected {$expected}"];
            }

            if (! $verify) {
                return null;
            }

            $stream = $this->storage->openNodeStream($sample, 0, $disk);
            $context = hash_init('sha256');

            try {
                hash_update_stream($context, $stream);
            } finally {
                fclose($stream);
            }

            return hash_equals((string) $sample->sha256, hash_final($context)) ? null : [self::CORRUPT, 'the content does not match its sha256'];
        } catch (Throwable $e) {
            return [self::UNREADABLE, $e->getMessage()];
        }
    }

    /**
     * One row per blob: the first node that uses it, and where it is. With a limit, the least recently verified first
     * (so repeated runs with a limit cover everything); without one, in id order.
     *
     * @return Generator<int, \stdClass>
     */
    private function blobRows(?StorageDisk $only, int $limit): Generator
    {
        if ($limit > 0) {
            yield from $this->query($only)
                ->orderByRaw('min(verified_at) is not null, min(verified_at), min(id)')
                ->limit($limit)
                ->get();

            return;
        }

        $last = 0;

        while (true) {
            $rows = $this->query($only)->havingRaw('min(id) > ?', [$last])->orderByRaw('min(id)')->limit(200)->get();

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $row) {
                $last = max($last, (int) $row->first_id);

                yield $row;
            }
        }
    }

    private function count(?StorageDisk $only): int
    {
        return (int) DB::table(fn ($query) => $this->scope($query->from('nodes'), $only)->groupBy('disk_id', 'path')->selectRaw('min(id) as first_id'), 'blobs')->count();
    }

    private function query(?StorageDisk $only): Builder
    {
        return $this->scope(DB::table('nodes'), $only)
            ->groupBy('disk_id', 'path')
            ->selectRaw('min(id) as first_id, disk_id, path');
    }

    private function scope(Builder $query, ?StorageDisk $only): Builder
    {
        return $query
            ->where('type', NodeType::File->value)
            ->whereNotNull('path')
            ->whereNotNull('disk_id')
            ->when($only !== null, fn ($query) => $query->where('disk_id', $only?->id));
    }
}
