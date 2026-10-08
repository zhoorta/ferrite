<?php

namespace App\Actions\Uploads;

use App\Actions\Nodes\NodeName;
use App\Models\Share;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class StartDropboxUpload
{
    /**
     * Register an upload by a guest through a drop-box link. The file goes straight into the shared
     * folder: only the last segment of $path is used, so a dropped folder is flattened. The upload
     * belongs to the folder's owner (quota, permissions); `share_id` marks where it came from.
     * An unfinished upload of the same file made in this session ($sessionIds) is resumed.
     *
     * @param  list<string>  $sessionIds
     *
     * @throws ValidationException
     */
    public function handle(Share $share, string $path, int $size, ?string $fingerprint, array $sessionIds): Upload
    {
        $folder = $share->node;

        if (! $share->isDropbox() || ! $share->isActive() || ! $folder->isFolder() || $folder->isTrashed()) {
            throw ValidationException::withMessages(['path' => __('This upload link is no longer active.')]);
        }

        $owner = User::query()->findOrFail($folder->owner_id);

        if ($owner->isDisabled()) {
            throw ValidationException::withMessages(['path' => __('This upload link is no longer active.')]);
        }

        $name = NodeName::normalize(basename(str_replace('\\', '/', $path)));

        $remaining = $owner->remainingBytes();

        if ($remaining !== null && $size > $remaining) {
            throw ValidationException::withMessages(['size' => __('The recipient has no storage space left.')]);
        }

        $this->assertWithinCap($share, $size, $sessionIds);

        $existing = Upload::query()
            ->where('share_id', $share->id)
            ->whereIn('id', $sessionIds)
            ->where('status', Upload::RECEIVING)
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
        $upload->user_id = $owner->id;
        $upload->share_id = $share->id;
        $upload->parent_id = $folder->id;
        $upload->save();

        return $upload;
    }

    /**
     * What the link has received plus what is on its way (any session) must stay within the cap.
     * Checked again when each file is stored, which is what really holds the line.
     *
     * @param  list<string>  $sessionIds
     *
     * @throws ValidationException
     */
    private function assertWithinCap(Share $share, int $size, array $sessionIds): void
    {
        if ($share->max_bytes === null) {
            return;
        }

        $inFlight = (int) Upload::query()
            ->where('share_id', $share->id)
            ->whereIn('status', [Upload::RECEIVING, Upload::PROCESSING])
            ->when($sessionIds !== [], fn ($query) => $query->whereNotIn('id', $sessionIds))
            ->sum('size');

        if ($share->received_bytes + $inFlight + $size > $share->max_bytes) {
            throw ValidationException::withMessages(['size' => __('This upload link has reached its size limit.')]);
        }
    }
}
