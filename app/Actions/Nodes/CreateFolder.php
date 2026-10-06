<?php

namespace App\Actions\Nodes;

use App\Enums\NodeType;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateFolder
{
    /**
     * @throws ValidationException
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

        return $folder;
    }
}
