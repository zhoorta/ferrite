<?php

namespace App\Actions\Sharing;

use App\Enums\ActivityAction;
use App\Models\Node;
use App\Models\Share;
use App\Models\User;
use App\Support\ActivityLog;
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
    public function handle(User $actor, Node $node, ?string $password = null, ?CarbonInterface $expiresAt = null, bool $allowDownload = true, bool $dropbox = false, ?int $maxBytes = null): Share
    {
        Gate::forUser($actor)->authorize('share', $node);

        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw ValidationException::withMessages(['expiry' => __('The expiry date must be in the future.')]);
        }

        if ($dropbox && ! $node->isFolder()) {
            throw ValidationException::withMessages(['kind' => __('An upload link needs a folder.')]);
        }

        if ($maxBytes !== null && $maxBytes < 1) {
            throw ValidationException::withMessages(['maxSize' => __('The size limit must be above zero.')]);
        }

        $share = new Share(['allow_download' => $dropbox ? false : $allowDownload, 'expires_at' => $expiresAt]);
        $share->kind = $dropbox ? Share::DROPBOX : Share::VIEW;
        $share->max_bytes = $dropbox ? $maxBytes : null;
        $share->node_id = $node->id;
        $share->created_by = $actor->id;
        $share->token = Str::random(40);
        $share->password_hash = $password === null || $password === '' ? null : Hash::make($password);
        $share->save();

        ActivityLog::record(ActivityAction::LinkCreated, $node, $actor, ['share_id' => $share->id] + ($dropbox ? ['kind' => Share::DROPBOX] : []));

        return $share;
    }
}
