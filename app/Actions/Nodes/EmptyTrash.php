<?php

namespace App\Actions\Nodes;

use App\Models\Node;
use App\Models\User;

class EmptyTrash
{
    public function __construct(private PurgeNode $purge) {}

    /**
     * Permanently delete everything in the user's trash. Returns how many top-level items went.
     */
    public function handle(User $actor): int
    {
        $count = 0;

        foreach (Node::trashRootIds($actor) as $id) {
            // An earlier purge may already have removed it as part of its tree.
            if (($node = Node::query()->find($id)) !== null) {
                $this->purge->handle($actor, $node);
                $count++;
            }
        }

        return $count;
    }
}
