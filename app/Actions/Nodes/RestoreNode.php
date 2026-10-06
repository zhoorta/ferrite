<?php

namespace App\Actions\Nodes;

use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RestoreNode
{
    /**
     * Put a trashed node back where it was. If the folder it lived in is gone or trashed too, it
     * goes to the root; if its name is taken meanwhile, a " (2)", " (3)"... suffix is added.
     */
    public function handle(User $actor, Node $node): Node
    {
        Gate::forUser($actor)->authorize('restore', $node);

        if ($node->trashed_at === null) {
            return $node;
        }

        $parent = $node->parent_id === null ? null : Node::find($node->parent_id);
        $parentId = $parent !== null && ! $parent->isTrashed() ? $parent->id : null;

        $name = $this->availableName($node, $parentId);

        $node->forceFill(['parent_id' => $parentId, 'name' => $name, 'trashed_at' => null])->save();

        return $node;
    }

    private function availableName(Node $node, ?int $parentId): string
    {
        if (! NodeName::taken($node->owner_id, $parentId, $node->name, $node->id)) {
            return $node->name;
        }

        [$base, $extension] = $node->isFile() && str_contains($node->name, '.') && ! str_starts_with($node->name, '.')
            ? [pathinfo($node->name, PATHINFO_FILENAME), '.'.pathinfo($node->name, PATHINFO_EXTENSION)]
            : [$node->name, ''];

        for ($i = 2; ; $i++) {
            $candidate = "{$base} ({$i}){$extension}";

            if (! NodeName::taken($node->owner_id, $parentId, $candidate, $node->id)) {
                return $candidate;
            }
        }
    }
}
