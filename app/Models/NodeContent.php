<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The text extracted from one distinct file content (by sha256), for content search. Which nodes
 * and users may see it is decided by the nodes that use that content, never by this table.
 *
 * @property int $id
 * @property string $sha256
 * @property string $status
 * @property string|null $reason
 * @property string|null $text
 * @property CarbonInterface|null $extracted_at
 */
class NodeContent extends Model
{
    public const INDEXED = 'indexed';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['extracted_at' => 'datetime'];
    }
}
