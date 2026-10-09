<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Sanctum token that reaches one folder (and what is inside it) instead of the whole account.
 * `folder_id` null means the whole drive. Abilities are `read` and, for uploads, `write`.
 *
 * @property int|null $folder_id
 */
class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'folder_id'];

    /**
     * @return BelongsTo<Node, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'folder_id');
    }

    /**
     * The node the token starts at, or null for the whole drive. Aborts with 404 when the folder is
     * gone or in the trash, which ends the token's access without revoking it.
     */
    public function rootFolder(): ?Node
    {
        if ($this->folder_id === null) {
            return null;
        }

        $folder = $this->folder;
        abort_unless($folder !== null && $folder->isFolder() && ! $folder->isTrashed(), 404);

        return $folder;
    }

    /**
     * Whether $node is inside the token's folder (or is it) and not trashed. Everything else is a 404 for the caller.
     */
    public function reaches(Node $node): bool
    {
        if ($node->isTrashed() || $node->owner_id !== $this->tokenable_id) {
            return false;
        }

        return $this->folder_id === null || in_array($this->folder_id, $node->selfAndAncestorIds(), true);
    }
}
