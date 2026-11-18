<?php

namespace App\Support;

use App\Enums\NodeType;
use App\Models\Node;
use App\Models\User;

/**
 * The choices offered when a token is made, in Settings > API tokens and on the app approval page
 * (docs/api.md): what a token may do, how long it lives, and the folders it can be pointed at.
 */
class ApiAccess
{
    /** The access levels and the abilities each one grants. */
    public const LEVELS = [
        'read' => ['read'],
        'write' => ['read', 'write'],
        'replace' => ['read', 'write', 'replace'],
        'trash' => ['read', 'write', 'trash'],
        'full' => ['read', 'write', 'replace', 'trash'],
    ];

    /** Expiry choices: key => days (null: never). */
    public const EXPIRY_DAYS = ['never' => null, '30' => 30, '90' => 90, '365' => 365];

    /**
     * The user's folders as id => "Parent / Child", trashed ones left out.
     *
     * @return array<int, string>
     */
    public static function folders(User $user): array
    {
        $rows = Node::query()
            ->where('owner_id', $user->id)
            ->where('type', NodeType::Folder)
            ->whereNull('trashed_at')
            ->get(['id', 'parent_id', 'name'])
            ->keyBy('id');

        $paths = [];
        foreach ($rows as $row) {
            $parts = [];
            for ($node = $row, $guard = 0; $node !== null && $guard < 100; $node = $rows->get($node->parent_id), $guard++) {
                array_unshift($parts, $node->name);
            }
            // A folder whose parent is trashed has a parent missing from $rows: its path is not complete, so skip it.
            if ($row->parent_id === null || $rows->has($row->parent_id)) {
                $paths[$row->id] = implode(' / ', $parts);
            }
        }

        asort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return $paths;
    }
}
