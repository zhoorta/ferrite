<?php

namespace App\Actions\Nodes;

use App\Enums\ActivityAction;
use App\Enums\NodeType;
use App\Models\Node;
use App\Models\User;
use App\Support\ActivityLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateFolder
{
    /**
     * @throws ValidationException
     * @throws QueryException When a parallel request took the name between the check and the insert.
     */
    public function handle(User $actor, ?Node $parent, string $name): Node
    {
        $name = NodeName::normalize($name);

        if ($parent !== null) {
            Gate::forUser($actor)->authorize('update', $parent);

            if (! $parent->isFolder() || $parent->isTrashed()) {
                throw ValidationException::withMessages(['name' => __('Folders can only be created inside an active folder.')]);
            }
        }

        $ownerId = $parent !== null ? $parent->owner_id : $actor->id;

        NodeName::assertAvailable($ownerId, $parent?->id, $name);

        $folder = new Node(['parent_id' => $parent?->id, 'type' => NodeType::Folder, 'name' => $name]);
        $folder->owner_id = $ownerId;
        $folder->save();

        ActivityLog::record(ActivityAction::CreatedFolder, $folder, $actor);

        return $folder;
    }
}
