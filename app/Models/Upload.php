<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * State of a chunked upload. The bytes received so far live in a temporary file named after the
 * upload id until the last chunk arrives and the file is moved to its disk.
 *
 * @property string $id
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $name
 * @property int $size
 * @property int $offset
 * @property string|null $fingerprint
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'size', 'fingerprint'])]
class Upload extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'offset' => 'integer',
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
     * @return BelongsTo<Node, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'parent_id');
    }

    public function tmpPath(): string
    {
        return rtrim(config('ferrite.tmp_path'), '/').'/'.$this->id;
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
