<?php

namespace App\Actions\Uploads;

use App\Models\Node;
use App\Models\StorageDisk;
use App\Models\Upload;
use App\Support\StorageManager;
use Illuminate\Support\Str;
use Throwable;

class FailUpload
{
    public function __construct(private StorageManager $storage) {}

    /**
     * Give up on an upload that was being finalized: remove the temporary file and any blob it
     * wrote that no node uses, then keep the row, marked failed, so the browser can show why.
     */
    public function handle(Upload $upload, string $message): void
    {
        @unlink($upload->tmpPath());

        if ($upload->blob_key !== null && $upload->disk_id !== null) {
            $this->removeOrphanBlob($upload->disk_id, $upload->blob_key);
        }

        $upload->forceFill(['status' => Upload::FAILED, 'error' => Str::limit($message, 500, '')])->save();
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
