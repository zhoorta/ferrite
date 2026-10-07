<?php

namespace App\Models;

use App\Enums\UserRole;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property string $palette
 * @property int|null $quota_bytes
 * @property int $used_bytes
 * @property CarbonInterface|null $disabled_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** Themes a user can pick in the appearance settings: the default Ferrite theme and the vintage ones. */
    public const PALETTES = ['ferrite', 'ferrite-light', 'mac', 'desk95', 'zine', 'bubblegum', 'amber', 'phosphor', 'commodore', 'amiga'];

    /** Palettes that render in light mode (no `dark` class on `<html>`); every other one is dark. */
    public const LIGHT_PALETTES = ['ferrite-light', 'mac', 'desk95', 'zine', 'bubblegum'];

    public static function isDarkPalette(?string $palette): bool
    {
        return ! in_array($palette, self::LIGHT_PALETTES, true);
    }

    /** @var array<string, mixed> */
    protected $attributes = ['palette' => 'ferrite'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'quota_bytes' => 'integer',
            'used_bytes' => 'integer',
            'disabled_at' => 'datetime',
        ];
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Bytes still available, or null when the quota is unlimited.
     */
    public function remainingBytes(): ?int
    {
        return $this->quota_bytes === null ? null : max(0, $this->quota_bytes - $this->used_bytes);
    }

    /**
     * @return HasMany<Node, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class, 'owner_id');
    }

    /**
     * Nodes other users shared with this user.
     *
     * @return BelongsToMany<Node, $this>
     */
    public function sharedNodes(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'node_user')
            ->withPivot('permission')
            ->withTimestamps();
    }

    /**
     * Nodes the user starred. Any node they can view may be starred.
     *
     * @return BelongsToMany<Node, $this>
     */
    public function favorites(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'favorites')->withTimestamps();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
