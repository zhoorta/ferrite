<?php

namespace App\Models;

use App\Enums\NodeType;
use App\Enums\Permission;
use Database\Factories\NodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Files and folders in one table. Trashing sets `trashed_at` on the node only; descendants
 * are trashed implicitly (see isTrashed()), so restoring is a single-row update.
 *
 * @property int $id
 * @property int $owner_id
 * @property int|null $parent_id
 * @property NodeType $type
 * @property string $name
 * @property int|null $disk_id
 * @property string|null $path
 * @property int $size
 * @property string|null $mime
 * @property string|null $sha256
 * @property Carbon|null $trashed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['parent_id', 'type', 'name', 'disk_id', 'path', 'size', 'mime', 'sha256'])]
class Node extends Model
{
    /** @use HasFactory<NodeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NodeType::class,
            'size' => 'integer',
            'trashed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Node, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<StorageDisk, $this>
     */
    public function disk(): BelongsTo
    {
        return $this->belongsTo(StorageDisk::class, 'disk_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'node_user')
            ->withPivot('permission')
            ->withTimestamps();
    }

    public function isFolder(): bool
    {
        return $this->type === NodeType::Folder;
    }

    public function isFile(): bool
    {
        return $this->type === NodeType::File;
    }

    /**
     * Nodes that are not themselves trashed. Combine with isTrashed() when a trashed ancestor matters.
     *
     * @param  Builder<Node>  $query
     */
    public function scopeNotTrashed(Builder $query): void
    {
        $query->whereNull('trashed_at');
    }

    /**
     * @param  Builder<Node>  $query
     */
    public function scopeTrashed(Builder $query): void
    {
        $query->whereNotNull('trashed_at');
    }

    /**
     * IDs of this node and all its ancestors, nearest first.
     *
     * @return list<int>
     */
    public function selfAndAncestorIds(): array
    {
        $rows = DB::select(
            'with recursive tree (id, parent_id, depth) as (
                select id, parent_id, 0 from nodes where id = ?
                union all
                select n.id, n.parent_id, tree.depth + 1 from nodes n join tree on n.id = tree.parent_id
            ) select id from tree order by depth',
            [$this->id],
        );

        return array_values(array_map(fn ($row) => (int) $row->id, $rows));
    }

    /**
     * True when this node or any ancestor is in the trash.
     */
    public function isTrashed(): bool
    {
        if ($this->trashed_at !== null) {
            return true;
        }

        if ($this->parent_id === null) {
            return false;
        }

        return self::query()
            ->whereIn('id', $this->selfAndAncestorIds())
            ->whereNotNull('trashed_at')
            ->exists();
    }

    /**
     * The strongest permission a user was granted through a share on this node or an ancestor.
     */
    public function sharedPermissionFor(User $user): ?Permission
    {
        $permissions = DB::table('node_user')
            ->where('user_id', $user->id)
            ->whereIn('node_id', $this->selfAndAncestorIds())
            ->pluck('permission');

        if ($permissions->contains(Permission::Edit->value)) {
            return Permission::Edit;
        }

        return $permissions->isNotEmpty() ? Permission::View : null;
    }

    /**
     * IDs of the user's trashed nodes that are not inside another trashed node: what the trash
     * view lists, since restoring or purging a folder covers everything in it.
     *
     * @return list<int>
     */
    public static function trashRootIds(User $user): array
    {
        $rows = DB::select(
            'with recursive up (origin, id, parent_id) as (
                select id, id, parent_id from nodes where owner_id = ? and trashed_at is not null
                union all
                select up.origin, n.id, n.parent_id from nodes n join up on n.id = up.parent_id
            ) select id from nodes where owner_id = ? and trashed_at is not null
            and id not in (
                select up.origin from up join nodes a on a.id = up.id
                where up.id <> up.origin and a.trashed_at is not null
            )',
            [$user->id, $user->id],
        );

        return array_values(array_map(fn ($row) => (int) $row->id, $rows));
    }
}
