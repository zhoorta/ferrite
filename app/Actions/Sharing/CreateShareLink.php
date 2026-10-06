<?php

namespace App\Actions\Sharing;

use App\Models\Node;
use App\Models\Share;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateShareLink
{
    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Node $node, ?string $password = null, ?CarbonInterface $expiresAt = null, bool $allowDownload = true): Share
    {
        Gate::forUser($actor)->authorize('share', $node);

        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw ValidationException::withMessages(['expiry' => __('The expiry date must be in the future.')]);
        }

        $share = new Share(['allow_download' => $allowDownload, 'expires_at' => $expiresAt]);
        $share->node_id = $node->id;
        $share->created_by = $actor->id;
        $share->token = Str::random(40);
        $share->password_hash = $password === null || $password === '' ? null : Hash::make($password);
        $share->save();

        return $share;
    }
}
