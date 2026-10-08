<?php

namespace App\Models;

use App\Enums\ActivityAction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the activity log. Rows are append-only and pruned by age (`activity:prune`).
 *
 * @property int $id
 * @property int $owner_id
 * @property int|null $actor_id
 * @property int|null $node_id
 * @property string $node_name
 * @property ActivityAction $action
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface $created_at
 */
class Activity extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ActivityAction::class,
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * A plain-text sentence for the person reading the log.
     */
    public function sentence(User $viewer): string
    {
        $who = match (true) {
            $this->actor_id === null => __('Someone'),
            $this->actor_id === $viewer->id => __('You'),
            default => $this->actor->name ?? __('Someone'),
        };
        $name = $this->node_name;
        $meta = $this->meta ?? [];
        $person = $meta['user'] ?? '';

        return match ($this->action) {
            ActivityAction::Uploaded => __(':who uploaded :name', ['who' => $who, 'name' => $name]),
            ActivityAction::CreatedFolder => __(':who created the folder :name', ['who' => $who, 'name' => $name]),
            ActivityAction::Renamed => __(':who renamed :from to :name', ['who' => $who, 'from' => $meta['from'] ?? '?', 'name' => $name]),
            ActivityAction::Moved => __(':who moved :name to :to', ['who' => $who, 'name' => $name, 'to' => $meta['to'] ?? __('My files')]),
            ActivityAction::Copied => __(':who copied :from to :to', ['who' => $who, 'from' => $meta['from'] ?? $name, 'to' => $meta['to'] ?? __('My files')]),
            ActivityAction::Trashed => __(':who moved :name to the trash', ['who' => $who, 'name' => $name]),
            ActivityAction::Restored => __(':who restored :name from the trash', ['who' => $who, 'name' => $name]),
            ActivityAction::Purged => __(':who permanently deleted :name', ['who' => $who, 'name' => $name]),
            ActivityAction::Downloaded => __(':who downloaded :name', ['who' => $who, 'name' => $name]),
            ActivityAction::LinkCreated => __(':who created a share link for :name', ['who' => $who, 'name' => $name]),
            ActivityAction::LinkRevoked => __(':who revoked a share link for :name', ['who' => $who, 'name' => $name]),
            ActivityAction::LinkDownloaded => __(':who downloaded :name through a share link', ['who' => $who, 'name' => $name]),
            ActivityAction::LinkUploaded => __(':name was uploaded through an upload link', ['name' => $name]),
            ActivityAction::Shared => __(':who shared :name with :person (:permission)', ['who' => $who, 'name' => $name, 'person' => $person, 'permission' => ($meta['permission'] ?? 'view') === 'edit' ? __('can edit') : __('can view')]),
            ActivityAction::Unshared => __(':who stopped sharing :name with :person', ['who' => $who, 'name' => $name, 'person' => $person]),
        };
    }
}
