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

class DeleteVersion
{
    public function __construct(private VersionKeeper $versions, private PurgeNode $purge) {}

    /**
     * Delete one earlier version for good: its row, its blob (unless something else still points at it) and its bytes
     * in the owner's quota.
     */
    public function handle(User $actor, NodeVersion $version): void
    {
        $node = Node::query()->findOrFail($version->node_id);

        Gate::forUser($actor)->authorize('update', $node);

        $blobs = [];

        DB::transaction(function () use ($version, $node, &$blobs) {
            [$bytes, $blobs] = $this->versions->drop([$version]);
            $this->versions->refund($node->owner_id, $bytes);
        });

        $this->purge->deleteBlobs($blobs);

        ActivityLog::record(ActivityAction::DeletedVersion, $node, $actor);
    }
}
