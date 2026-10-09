<?php

namespace App\Actions\Uploads;

use App\Actions\Nodes\EnsureFolderPath;
use App\Actions\Nodes\NodeName;
use App\Enums\NodeType;
use App\Models\ApiToken;
use App\Models\Node;
use App\Models\Upload;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StartUpload
{
    private const MAX_DEPTH = 32;

    public function __construct(private EnsureFolderPath $ensureFolderPath) {}

    /**
     * Register an upload of $path (a file name, or a relative path like "photos/2026/a.jpg")
     * into $parent. An unfinished upload of the same file is resumed instead of restarted.
     * (One already being stored, or finished or failed, is not: that is a new upload.)
     * With $replace, a file of the same name in the folder is replaced when the upload is stored
     * (see CompleteUpload); without it both are kept. Through an API token ($token) an upload belongs to that
     * token, is only resumed by it, and fails instead of keeping both if the name is taken when it is stored.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, ?Node $parent, string $path, int $size, ?string $fingerprint = null, bool $replace = false, ?ApiToken $token = null): Upload
    {
        if ($parent !== null) {
            Gate::forUser($actor)->authorize('update', $parent);

            if (! $parent->isFolder() || $parent->isTrashed()) {
                throw ValidationException::withMessages(['parent_id' => __('Files can only be uploaded into an active folder.')]);
            }
        }

        $segments = explode('/', $path);
        $name = NodeName::normalize(array_pop($segments));

        Demo::assertUploadAllowed($actor, $name, $size);

        if (count($segments) > self::MAX_DEPTH) {
            throw ValidationException::withMessages(['path' => __('That folder structure is too deep.')]);
        }

        $ownerId = $parent !== null ? $parent->owner_id : $actor->id;
        $remaining = User::query()->findOrFail($ownerId)->remainingBytes();

        // Replacing a file of the same name only needs room for what the new one adds. Only looked up
        // for a plain name: inside folders that may not exist yet, the full size is required.
        $credit = $replace && $segments === [] ? (int) Node::query()
            ->where('owner_id', $ownerId)
            ->where('parent_id', $parent?->id)
            ->where('type', NodeType::File)
            ->whereNull('trashed_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->value('size') : 0;

        if ($remaining !== null && $size - $credit > $remaining) {
            throw ValidationException::withMessages(['size' => __('Not enough storage space left.')]);
        }

        $folder = $this->ensureFolderPath->handle($actor, $parent, $segments);

        $existing = Upload::query()
            ->where('user_id', $actor->id)
            ->where('api_token_id', $token?->id)
            ->where('status', Upload::RECEIVING)
            ->where('parent_id', $folder?->id)
            ->where('name', $name)
            ->where('size', $size)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($existing !== null) {
            if (! is_file($existing->tmpPath()) || filesize($existing->tmpPath()) < $existing->offset) {
                $existing->offset = 0;
            }

            $existing->touch();

            return $existing;
        }

        $upload = new Upload(['name' => $name, 'size' => $size, 'fingerprint' => $fingerprint]);
        $upload->offset = 0;
        $upload->replace = $replace;
        $upload->api_token_id = $token?->id;
        $upload->api_token_name = $token?->name;
        $upload->on_conflict = $token === null ? 'keep' : 'fail';
        $upload->user_id = $actor->id;
        $upload->parent_id = $folder?->id;
        $upload->save();

        return $upload;
    }
}
