<?php

namespace App\Support;

use App\Models\Node;
use App\Models\Share;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Rules for guests using a share link: finding a usable link, the password gate and which nodes
 * the link reaches. Anything not allowed is a plain 404, so a link does not reveal what exists.
 */
class ShareAccess
{
    private const SESSION_KEY = 'share_unlocks';

    /**
     * The link for a token if it exists, is not revoked or expired, and its node is not in the trash.
     */
    public function find(string $token): Share
    {
        $share = Share::query()->with('node')->where('token', $token)->first();

        // The database may compare case-insensitively; the token must match exactly.
        abort_unless(
            $share !== null && hash_equals($share->token, $token) && $share->isActive() && ! $share->node->isTrashed(),
            404,
        );

        return $share;
    }

    public function isUnlocked(Share $share): bool
    {
        if (! $share->hasPassword()) {
            return true;
        }

        $unlocks = session(self::SESSION_KEY, []);

        // The stored value is tied to the current password, so changing it locks everyone out again.
        return isset($unlocks[$share->id]) && hash_equals($this->fingerprint($share), (string) $unlocks[$share->id]);
    }

    /**
     * Check a password guess and, if right, remember the unlock in the session.
     *
     * @throws ValidationException
     */
    public function unlock(Share $share, string $password, string $ip): void
    {
        $keys = ["share-unlock:{$share->id}:{$ip}" => 5, "share-unlock:{$share->id}" => 30];

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'password' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
                ]);
            }
        }

        if (! $share->hasPassword() || ! Hash::check($password, (string) $share->password_hash)) {
            foreach ($keys as $key => $max) {
                RateLimiter::hit($key, 300);
            }

            throw ValidationException::withMessages(['password' => __('Incorrect password.')]);
        }

        RateLimiter::clear("share-unlock:{$share->id}:{$ip}");

        session()->put(self::SESSION_KEY.".{$share->id}", $this->fingerprint($share));
    }

    /**
     * Whether $node is the shared node or inside it, and not trashed.
     */
    public function reaches(Share $share, Node $node): bool
    {
        return in_array($share->node_id, $node->selfAndAncestorIds(), true) && ! $node->isTrashed();
    }

    private function fingerprint(Share $share): string
    {
        return hash('sha256', (string) $share->password_hash);
    }
}
