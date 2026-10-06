<?php

namespace App\Actions\Uploads;

use App\Actions\Nodes\NodeName;
use App\Enums\NodeType;
use App\Models\Node;
use App\Models\Upload;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CompleteUpload
{
    public function __construct(private StorageManager $storage) {}

    /**
     * Move a fully received upload to its disk and create the file node, charging the owner's quota.
     * Permissions and quota are checked again because they may have changed since the upload started.
     * The temporary file and upload row are removed on success.
     *
     * @throws ValidationException
     */
    public function handle(Upload $upload): Node
    {
        $tmp = $upload->tmpPath();

        if (! $upload->isComplete() || ! is_file($tmp) || filesize($tmp) !== $upload->size) {
            throw new RuntimeException('The upload is not complete.');
        }

        $actor = User::query()->findOrFail($upload->user_id);
        $parent = $upload->parent_id === null ? null : Node::query()->findOrFail($upload->parent_id);

        if ($parent !== null) {
            Gate::forUser($actor)->authorize('update', $parent);

            if ($parent->isTrashed()) {
                throw ValidationException::withMessages(['parent_id' => __('The destination folder is in the trash.')]);
            }
        }

        $ownerId = $parent !== null ? $parent->owner_id : $actor->id;
        $disk = $this->storage->default();
        $filesystem = $this->storage->filesystem($disk);
        $key = $this->storage->newKey();

        $sha256 = hash_file('sha256', $tmp);
        $mime = mime_content_type($tmp) ?: 'application/octet-stream';

        $stream = fopen($tmp, 'rb') ?: throw new RuntimeException('Cannot read the uploaded file.');

        try {
            $filesystem->writeStream($key, $stream);
        } finally {
            fclose($stream);
        }

        try {
            $node = DB::transaction(function () use ($upload, $ownerId, $parent, $disk, $key, $sha256, $mime) {
                $owner = User::query()->lockForUpdate()->findOrFail($ownerId);

                if ($owner->quota_bytes !== null && $upload->size > $owner->remainingBytes()) {
                    throw ValidationException::withMessages(['size' => __('Not enough storage space left.')]);
                }

                $node = new Node([
                    'parent_id' => $parent?->id,
                    'type' => NodeType::File,
                    'name' => NodeName::available($ownerId, $parent?->id, $upload->name, true),
                    'disk_id' => $disk->id,
                    'path' => $key,
                    'size' => $upload->size,
                    'mime' => $mime,
                    'sha256' => $sha256,
                ]);
                $node->owner_id = $ownerId;
                $node->save();

                $owner->increment('used_bytes', $upload->size);

                return $node;
            });
        } catch (Throwable $e) {
            $filesystem->delete($key);

            throw $e;
        }

        $upload->discard();

        return $node;
    }
}
