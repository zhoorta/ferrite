<?php

namespace App\Support;

use App\Actions\Nodes\PurgeNode;
use App\Models\Node;
use App\Models\NodeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the earlier contents of replaced files (docs/versions.md). The methods that change rows are meant to run
 * inside the caller's transaction; the blobs they report are deleted by the caller once that has committed
 * (PurgeNode::deleteBlobs, which leaves any blob something still points at).
 */
class VersionKeeper
{
    public function enabled(): bool
    {
        return (int) config('ferrite.versions_keep') > 0;
    }

    /**
     * Whether replacing $node's content with content of hash $newSha256 should keep the old content: versions are on
     * and the content really changes (the same bytes replaced by themselves are not a new version).
     */
    public function wanted(Node $node, string $newSha256): bool
    {
        return $this->enabled() && $node->path !== null && $node->disk_id !== null && $node->sha256 !== $newSha256;
    }

    /**
     * Save the node's current content as a version. The node itself is not changed.
     */
    public function keep(Node $node, ?User $by): NodeVersion
    {
        return NodeVersion::query()->create([
            'node_id' => $node->id,
            'disk_id' => $node->disk_id,
            'path' => $node->path,
            'size' => $node->size,
            'mime' => $node->mime,
            'sha256' => $node->sha256,
            'encryption_key_id' => $node->encryption_key_id,
            'user_id' => $by?->id,
            'content_at' => $node->updated_at,
            'verified_at' => $node->verified_at,
        ]);
    }

    /**
     * Drop the versions of $node over the limit (oldest first) and older than the age limit. Returns what was
     * freed: the bytes (for the owner's quota) and the blobs to delete after the transaction.
     *
     * @return array{int, list<array{size: int, disk_id: int, path: string}>}
     */
    public function prune(Node $node): array
    {
        $keep = max(0, (int) config('ferrite.versions_keep'));
        $days = max(0, (int) config('ferrite.versions_days'));

        $drop = NodeVersion::query()->where('node_id', $node->id)->orderByDesc('id')->get()
            ->filter(fn (NodeVersion $version, int $index) => $index >= $keep || ($days > 0 && $version->created_at !== null && $version->created_at->lt(now()->subDays($days))));

        return $this->drop(array_values($drop->all()));
    }

    /**
     * Delete these versions' rows.
     *
     * @param  list<NodeVersion>  $versions
     * @return array{int, list<array{size: int, disk_id: int, path: string}>}
     */
    public function drop(array $versions): array
    {
        $bytes = 0;
        $blobs = [];

        foreach ($versions as $version) {
            $bytes += $version->size;
            $blobs[] = ['size' => $version->size, 'disk_id' => $version->disk_id, 'path' => $version->path];
            $version->delete();
        }

        return [$bytes, $blobs];
    }

    /**
     * Give back quota to a user, never below zero.
     */
    public function refund(int $ownerId, int $bytes): void
    {
        if ($bytes > 0) {
            $owner = User::query()->lockForUpdate()->find($ownerId);
            $owner?->forceFill(['used_bytes' => max(0, $owner->used_bytes - $bytes)])->save();
        }
    }

    /**
     * Apply the limits to every file: for `versions:prune`, and after the limits were lowered.
     *
     * @return int versions removed
     */
    public function pruneAll(): int
    {
        $removed = 0;

        Node::query()->whereIn('id', NodeVersion::query()->select('node_id')->distinct())->orderBy('id')->each(function (Node $node) use (&$removed) {
            $blobs = [];

            DB::transaction(function () use ($node, &$removed, &$blobs) {
                [$bytes, $blobs] = $this->prune($node);
                $removed += count($blobs);
                $this->refund($node->owner_id, $bytes);
            });

            app(PurgeNode::class)->deleteBlobs($blobs);
        });

        return $removed;
    }
}
