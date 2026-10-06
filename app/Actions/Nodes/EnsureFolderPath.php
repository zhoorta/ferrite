<?php

namespace App\Actions\Nodes;

use App\Enums\NodeType;
use App\Models\Node;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class EnsureFolderPath
{
    public function __construct(private CreateFolder $createFolder) {}

    /**
     * Find or create the chain of folders below $parent and return the last one.
     *
     * @param  list<string>  $segments
     *
     * @throws ValidationException
     */
    public function handle(User $actor, ?Node $parent, array $segments): ?Node
    {
        foreach ($segments as $segment) {
            $name = NodeName::normalize($segment);
            $ownerId = $parent !== null ? $parent->owner_id : $actor->id;

            $parent = $this->find($ownerId, $parent?->id, $name) ?? $this->create($actor, $parent, $ownerId, $name);
        }

        return $parent;
    }

    private function find(int $ownerId, ?int $parentId, string $name): ?Node
    {
        $node = Node::query()
            ->where('owner_id', $ownerId)
            ->where('parent_id', $parentId)
            ->whereNull('trashed_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($node !== null && $node->type !== NodeType::Folder) {
            throw ValidationException::withMessages(['path' => __('":name" is a file, so it cannot be used as a folder.', ['name' => $name])]);
        }

        return $node;
    }

    private function create(User $actor, ?Node $parent, int $ownerId, string $name): Node
    {
        try {
            return $this->createFolder->handle($actor, $parent, $name);
        } catch (QueryException $e) {
            // A parallel upload created the same folder first.
            return $this->find($ownerId, $parent?->id, $name) ?? throw $e;
        }
    }
}
