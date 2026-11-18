<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\StorageDiskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property array<string, mixed>|null $config
 * @property bool $is_default
 * @property bool $encrypt
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'driver', 'config', 'is_default', 'encrypt'])]
#[Hidden(['config'])]
class StorageDisk extends Model
{
    /** @use HasFactory<StorageDiskFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_default' => 'boolean',
            'encrypt' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Node, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class, 'disk_id');
    }

    /**
     * @return HasMany<NodeVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(NodeVersion::class, 'disk_id');
    }
}
