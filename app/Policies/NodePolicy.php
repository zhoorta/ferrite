<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Node;
use App\Models\User;

/**
 * Access is granted to the owner, or to a user the node (or an ancestor) is shared with.
 * Admins get no special access to other people's files. Share-link access is added with share links.
 */
class NodePolicy
{
    public function view(User $user, Node $node): bool
    {
        return $this->owns($user, $node)
            || (! $node->isTrashed() && $node->sharedPermissionFor($user) !== null);
    }

    /**
     * Rename, move, upload into, and trash.
     */
    public function update(User $user, Node $node): bool
    {
        return $this->owns($user, $node)
            || (! $node->isTrashed() && $node->sharedPermissionFor($user) === Permission::Edit);
    }

    public function share(User $user, Node $node): bool
    {
        return $this->owns($user, $node) && ! $node->isTrashed();
    }

    public function restore(User $user, Node $node): bool
    {
        return $this->owns($user, $node);
    }

    public function forceDelete(User $user, Node $node): bool
    {
        return $this->owns($user, $node);
    }

    private function owns(User $user, Node $node): bool
    {
        return $node->owner_id === $user->id;
    }
}
