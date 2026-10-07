<?php

namespace App\Actions\Uploads;

use App\Actions\Nodes\EnsureFolderPath;
use App\Actions\Nodes\NodeName;
use App\Models\Node;
use App\Models\Upload;
use App\Models\User;
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
     *
     * @throws ValidationException
     */
    public function handle(User $actor, ?Node $parent, string $path, int $size, ?string $fingerprint = null): Upload
    {
        if ($parent !== null) {
            Gate::forUser($actor)->authorize('update', $parent);

            if (! $parent->isFolder() || $parent->isTrashed()) {
                throw ValidationException::withMessages(['parent_id' => __('Files can only be uploaded into an active folder.')]);
            }
        }

        $segments = explode('/', $path);
        $name = NodeName::normalize(array_pop($segments));

        if (count($segments) > self::MAX_DEPTH) {
            throw ValidationException::withMessages(['path' => __('That folder structure is too deep.')]);
        }

        $ownerId = $parent !== null ? $parent->owner_id : $actor->id;
        $remaining = User::query()->findOrFail($ownerId)->remainingBytes();

        if ($remaining !== null && $size > $remaining) {
            throw ValidationException::withMessages(['size' => __('Not enough storage space left.')]);
        }

        $folder = $this->ensureFolderPath->handle($actor, $parent, $segments);

        $existing = Upload::query()
            ->where('user_id', $actor->id)
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
        $upload->user_id = $actor->id;
        $upload->parent_id = $folder?->id;
        $upload->save();

        return $upload;
    }
}
