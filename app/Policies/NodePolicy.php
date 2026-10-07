<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Node;
use App\Models\User;
use App\Support\Demo;

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
     * Rename and upload into.
     */
    public function update(User $user, Node $node): bool
    {
        return $this->owns($user, $node)
            || (! $node->isTrashed() && $node->sharedPermissionFor($user) === Permission::Edit);
    }

    /**
     * Moving and trashing stay with the owner, so a share recipient cannot pull things
     * out of the owner's tree.
     */
    public function move(User $user, Node $node): bool
    {
        return $this->owns($user, $node) && ! $node->isTrashed();
    }

    public function trash(User $user, Node $node): bool
    {
        return $this->move($user, $node);
    }

    public function share(User $user, Node $node): bool
    {
        // No sharing in the demo: files there are only ever visible to the visitor who uploaded them.
        return $this->owns($user, $node) && ! $node->isTrashed() && ! Demo::isDemoUser($user);
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
