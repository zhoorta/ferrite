<?php

namespace App\Actions\Nodes;

use App\Enums\ActivityAction;
use App\Enums\NodeType;
use App\Models\Node;
use App\Models\StorageDisk;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\StorageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class CopyNode
{
    public function __construct(private StorageManager $storage) {}

    /**
     * Copy a file, or a folder with everything in it (not what is in the trash), into a folder or
     * the root of the destination owner. The copy is owned by whoever owns the destination and is
     * charged to their quota.
     *
     * Within one owner a copy shares the blob (see deduplication), so it costs no storage I/O.
     * Across owners the blob is duplicated, so nobody's file is ever tied to someone else's.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Node $node, ?Node $destination): Node
    {
        Gate::forUser($actor)->authorize('view', $node);

        if ($node->isTrashed()) {
            throw ValidationException::withMessages(['destination' => __('Items in the trash cannot be copied.')]);
        }

        if ($destination !== null) {
            Gate::forUser($actor)->authorize('update', $destination);

            if (! $destination->isFolder() || $destination->isTrashed()) {
                throw ValidationException::withMessages(['destination' => __('Choose an active folder.')]);
            }

            if (in_array($node->id, $destination->selfAndAncestorIds(), true)) {
                throw ValidationException::withMessages(['destination' => __('A folder cannot be copied into itself.')]);
            }
        }

        $ownerId = $destination !== null ? $destination->owner_id : $actor->id;
        $files = $this->files($node);
        $bytes = array_sum(array_map(fn (Node $file) => $file->size, $files));

        // Blobs of another owner are duplicated up front; the rows below only point at them.
        $written = [];

        try {
            if ($node->owner_id !== $ownerId) {
                $written = $this->duplicateBlobs($files);
            }

            $copy = DB::transaction(function () use ($node, $destination, $ownerId, $bytes, $written) {
                $owner = User::query()->lockForUpdate()->findOrFail($ownerId);

                if ($owner->quota_bytes !== null && $bytes > $owner->remainingBytes()) {
                    throw ValidationException::withMessages(['destination' => __('Not enough storage space left.')]);
                }

                // The source rows are locked and checked so a concurrent purge cannot delete a blob we are about to reuse.
                $alive = Node::query()->whereKey($node->id)->lockForUpdate()->exists();

                if (! $alive) {
                    throw ValidationException::withMessages(['destination' => __('The item no longer exists.')]);
                }

                $copy = $this->copyTree($node, $destination?->id, $ownerId, $written, true);

                if ($bytes > 0) {
                    $owner->increment('used_bytes', $bytes);
                }

                return $copy;
            });
        } catch (Throwable $e) {
            $this->deleteBlobs($written);

            throw $e;
        }

        ActivityLog::record(ActivityAction::Copied, $copy, $actor, ['from' => $node->name, 'to' => $destination?->name ?? __('My files')]);

        return $copy;
    }

    /**
     * @param  array<int, array{disk_id: int, path: string}>  $written  new blob per source node id, when blobs were duplicated
     */
    private function copyTree(Node $source, ?int $parentId, int $ownerId, array $written, bool $isRoot): Node
    {
        $copy = new Node([
            'parent_id' => $parentId,
            'type' => $source->type,
            'name' => $isRoot ? NodeName::available($ownerId, $parentId, $source->name, $source->isFile()) : $source->name,
            'disk_id' => $written[$source->id]['disk_id'] ?? $source->disk_id,
            'path' => $written[$source->id]['path'] ?? $source->path,
            'size' => $source->size ?? 0,
            'mime' => $source->mime,
            'sha256' => $source->sha256,
        ]);
        $copy->owner_id = $ownerId;
        $copy->save();

        if ($source->isFolder()) {
            foreach (Node::query()->where('parent_id', $source->id)->notTrashed()->orderBy('id')->get() as $child) {
                $this->copyTree($child, $copy->id, $ownerId, $written, false);
            }
        }

        return $copy;
    }

    /**
     * The files that will be copied: the node itself, or every untrashed file below it.
     *
     * @return list<Node>
     */
    private function files(Node $root): array
    {
        if ($root->isFile()) {
            return [$root];
        }

        $rows = DB::select(
            'with recursive tree (id) as (
                select id from nodes where parent_id = ? and trashed_at is null
                union all
                select n.id from nodes n join tree on n.parent_id = tree.id where n.trashed_at is null
            ) select id from tree',
            [$root->id],
        );

        return Node::query()
            ->whereKey(array_map(fn ($row) => (int) $row->id, $rows))
            ->where('type', NodeType::File)
            ->get()
            ->all();
    }

    /**
     * Write a fresh blob for each file, next to the original.
     *
     * @param  list<Node>  $files
     * @return array<int, array{disk_id: int, path: string}>
     */
    private function duplicateBlobs(array $files): array
    {
        $written = [];
        $disks = [];

        try {
            foreach ($files as $file) {
                if ($file->disk_id === null || $file->path === null) {
                    continue;
                }

                $source = $disks[$file->disk_id] ??= StorageDisk::query()->findOrFail($file->disk_id);
                $stream = $this->storage->openStream($source, $file->path, $file->size);
                $key = $this->storage->newKey();

                try {
                    $this->storage->filesystem($source)->writeStream($key, $stream);
                } finally {
                    fclose($stream);
                }

                $written[$file->id] = ['disk_id' => $file->disk_id, 'path' => $key];
            }
        } catch (Throwable $e) {
            $this->deleteBlobs($written);

            throw $e;
        }

        return $written;
    }

    /**
     * @param  array<int, array{disk_id: int, path: string}>  $blobs
     */
    private function deleteBlobs(array $blobs): void
    {
        foreach ($blobs as $blob) {
            try {
                $this->storage->filesystem(StorageDisk::query()->findOrFail($blob['disk_id']))->delete($blob['path']);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
