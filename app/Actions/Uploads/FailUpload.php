<?php

namespace App\Actions\Uploads;

use App\Models\Node;
use App\Models\StorageDisk;
use App\Models\Upload;
use App\Support\StorageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FailUpload
{
    public function __construct(private StorageManager $storage) {}

    /**
     * Give up on an upload that is being finalized: mark it failed, so the browser can show why,
     * then remove the temporary file and any blob it wrote that no node uses. Only an upload that
     * is still processing is touched (checked under a lock, see CompleteUpload); returns whether
     * it was.
     */
    public function handle(Upload $upload, string $message): bool
    {
        $claimed = DB::transaction(function () use ($upload, $message) {
            $current = Upload::query()->lockForUpdate()->find($upload->id);

            if ($current === null || $current->status !== Upload::PROCESSING) {
                return false;
            }

            $current->forceFill(['status' => Upload::FAILED, 'error' => Str::limit($message, 500, '')])->save();

            return true;
        });

        $upload->refresh();

        if (! $claimed) {
            return false;
        }

        @unlink($upload->tmpPath());

        if ($upload->blob_key !== null && $upload->disk_id !== null) {
            $this->removeOrphanBlob($upload->disk_id, $upload->blob_key);
        }

        return true;
    }

    private function removeOrphanBlob(int $diskId, string $key): void
    {
        $disk = StorageDisk::query()->find($diskId);

        if ($disk === null || Node::query()->where('disk_id', $diskId)->where('path', $key)->exists()) {
            return;
        }

        try {
            $this->storage->filesystem($disk)->delete($key);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
