<?php

namespace App\Support;

use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\Node;
use App\Models\User;

class ActivityLog
{
    /**
     * Record something that happened to a node. The owner is taken from the node, so they see what
     * collaborators and guests do with their files.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function record(ActivityAction $action, Node $node, ?User $actor, array $meta = []): Activity
    {
        $activity = new Activity;
        $activity->owner_id = $node->owner_id;
        $activity->actor_id = $actor?->id;
        $activity->node_id = $node->id;
        $activity->node_name = $node->name;
        $activity->action = $action;
        $activity->meta = $meta === [] ? null : $meta;
        $activity->save();

        return $activity;
    }
}
