<?php

namespace App\Actions\Nodes;

use App\Enums\ActivityAction;
use App\Models\Node;
use App\Models\StorageDisk;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\StorageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class PurgeNode
{
    public function __construct(private StorageManager $storage) {}

    /**
     * Permanently delete a trashed node, everything inside it and its files' blobs, and give the
     * space back to the owner's quota.
     */
    public function handle(User $actor, Node $node): void
    {
        Gate::forUser($actor)->authorize('forceDelete', $node);

        abort_unless($node->trashed_at !== null, 422, 'Only items in the trash can be deleted permanently.');

        ActivityLog::record(ActivityAction::Purged, $node, $actor);

        $this->purge($node);
    }

    /**
     * Delete a node tree without any permission or trash checks; for system code such as removing
     * a user or the scheduled trash purge.
     */
    public function purge(Node $node): void
    {
        $files = $this->files($node);
        $bytes = array_sum(array_column($files, 'size'));

        DB::transaction(function () use ($node, $bytes) {
            if ($bytes > 0) {
                $owner = User::query()->lockForUpdate()->find($node->owner_id);
                $owner?->forceFill(['used_bytes' => max(0, $owner->used_bytes - $bytes)])->save();
            }

            // Child rows, shares and user shares go with it through foreign keys.
            $node->delete();
        });

        // The rows are gone first: a blob that survives a failure is only wasted space, whereas a
        // row without a blob would be a broken file.
        $this->deleteBlobs($files);
    }

    /**
     * Every file in the tree, with where its blob is.
     *
     * @return list<array{size: int, disk_id: int, path: string}>
     */
    private function files(Node $root): array
    {
        $rows = DB::select(
            "with recursive tree (id) as (
                select id from nodes where id = ?
                union all
                select n.id from nodes n join tree on n.parent_id = tree.id
            ) select n.size, n.disk_id, n.path from nodes n join tree on n.id = tree.id
            where n.type = 'file' and n.disk_id is not null and n.path is not null",
            [$root->id],
        );

        return array_values(array_map(fn ($row) => [
            'size' => (int) $row->size,
            'disk_id' => (int) $row->disk_id,
            'path' => (string) $row->path,
        ], $rows));
    }

    /**
     * Remove blobs no node uses any more, with their thumbnails. Failures are reported, not thrown: a
     * blob that survives is only wasted space.
     *
     * @param  list<array{size: int, disk_id: int, path: string}>  $files
     */
    public function deleteBlobs(array $files): void
    {
        $disks = StorageDisk::query()->whereKey(array_unique(array_column($files, 'disk_id')))->get()->keyBy('id');

        foreach ($files as $file) {
            // Identical files share a blob; it goes with its last node.
            if (Node::query()->where('disk_id', $file['disk_id'])->where('path', $file['path'])->exists()) {
                continue;
            }

            try {
                $filesystem = $this->storage->filesystem($disks[$file['disk_id']]);
                $filesystem->delete($file['path']);
                $filesystem->delete("thumbnails/{$file['path']}.jpg");
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
