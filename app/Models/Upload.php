<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * State of a chunked upload. The bytes received so far live in a temporary file named after the
 * upload id until the last chunk arrives; then a queued job (FinalizeUpload) moves the file to its
 * disk and creates the node. Status: receiving, processing (job queued or running), done or failed.
 *
 * @property string $id
 * @property int $user_id
 * @property int|null $share_id
 * @property int|null $api_token_id
 * @property string|null $api_token_name
 * @property string $on_conflict
 * @property int|null $parent_id
 * @property string $name
 * @property int $size
 * @property int $offset
 * @property string|null $fingerprint
 * @property bool $replace
 * @property string $status
 * @property string|null $error
 * @property int|null $node_id
 * @property int|null $disk_id
 * @property string|null $blob_key
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'size', 'fingerprint'])]
class Upload extends Model
{
    use HasUlids;

    public const RECEIVING = 'receiving';

    public const PROCESSING = 'processing';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'offset' => 'integer',
            'replace' => 'boolean',
            'started_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Share, $this>
     */
    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function tmpPath(): string
    {
        return rtrim(config('ferrite.tmp_path'), '/').'/'.$this->id;
    }

    public function isReceiving(): bool
    {
        return $this->status === self::RECEIVING;
    }

    public function isComplete(): bool
    {
        return $this->offset >= $this->size;
    }

    /**
     * Remove the row and the temporary file.
     */
    public function discard(): void
    {
        @unlink($this->tmpPath());
        $this->delete();
    }
}
