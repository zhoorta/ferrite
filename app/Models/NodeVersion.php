<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An earlier content of a file, kept when the file was replaced. `content_at` is when that content was last
 * changed (the file's modification time then), `created_at` when it stopped being current, `user_id` who replaced it.
 *
 * @property int $id
 * @property int $node_id
 * @property int $disk_id
 * @property string $path
 * @property int $size
 * @property string|null $mime
 * @property string|null $sha256
 * @property int|null $encryption_key_id
 * @property int|null $user_id
 * @property CarbonInterface|null $content_at
 * @property CarbonInterface|null $verified_at
 * @property CarbonInterface|null $created_at
 */
class NodeVersion extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'encryption_key_id' => 'integer',
            'content_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return BelongsTo<StorageDisk, $this>
     */
    public function disk(): BelongsTo
    {
        return $this->belongsTo(StorageDisk::class, 'disk_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * An unsaved copy of $node holding this version's content, for the code that serves or reads a node's bytes
     * (NodeResponder, StorageManager, BlobConverter). It keeps the node's id and name, so the response is named
     * like the file.
     */
    public function asNode(?Node $node = null): Node
    {
        $node ??= $this->node;
        $copy = $node->replicate();
        $copy->forceFill([
            'disk_id' => $this->disk_id,
            'path' => $this->path,
            'size' => $this->size,
            'mime' => $this->mime,
            'sha256' => $this->sha256,
            'encryption_key_id' => $this->encryption_key_id,
        ]);
        $copy->setAttribute('id', $node->id);
        $copy->exists = false;
        $copy->setRelation('disk', $this->disk);

        return $copy;
    }
}
