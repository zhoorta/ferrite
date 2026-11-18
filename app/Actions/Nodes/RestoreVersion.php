<?php

namespace App\Actions\Nodes;

use App\Enums\ActivityAction;
use App\Models\Node;
use App\Models\NodeVersion;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\VersionKeeper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RestoreVersion
{
    public function __construct(private VersionKeeper $versions) {}

    /**
     * Make an earlier version the file's current content. The content it replaces becomes a version in its place, so
     * nothing is lost and restoring can be undone; the quota does not change (the same blobs, only swapped). Same node:
     * share links, favorites and history stay.
     */
    public function handle(User $actor, NodeVersion $version): Node
    {
        $node = Node::query()->findOrFail($version->node_id);

        Gate::forUser($actor)->authorize('update', $node);

        abort_if($node->isTrashed() || ! $node->isFile(), 422, 'Restore the file from the trash first.');

        return DB::transaction(function () use ($actor, $version, $node) {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            $version = NodeVersion::query()->lockForUpdate()->findOrFail($version->id);

            // The current content goes into the version's place (same quota), then the version's content moves up.
            $this->versions->keep($node, $actor);

            $node->forceFill([
                'disk_id' => $version->disk_id,
                'path' => $version->path,
                'size' => $version->size,
                'mime' => $version->mime,
                'sha256' => $version->sha256,
                'encryption_key_id' => $version->encryption_key_id,
                'verified_at' => $version->verified_at,
            ])->save();

            $version->delete();

            ActivityLog::record(ActivityAction::RestoredVersion, $node, $actor, ['date' => $version->content_at?->toDateTimeString()]);

            return $node;
        });
    }
}
