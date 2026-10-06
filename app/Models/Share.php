<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A public link to a file or folder. The token is the credential, so it is long and random;
 * an optional password and expiry narrow it, and revoking it ends access at once.
 *
 * @property int $id
 * @property int $node_id
 * @property int $created_by
 * @property string $token
 * @property string|null $password_hash
 * @property CarbonInterface|null $expires_at
 * @property bool $allow_download
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['allow_download', 'expires_at'])]
#[Hidden(['password_hash'])]
class Share extends Model
{
    /** @use HasFactory<ShareFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_download' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Links that are neither revoked nor expired.
     *
     * @param  Builder<Share>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasPassword(): bool
    {
        return $this->password_hash !== null;
    }

    public function url(): string
    {
        return route('share.show', ['token' => $this->token]);
    }
}
