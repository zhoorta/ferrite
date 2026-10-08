<?php

namespace App\Actions\Uploads;

use App\Actions\Nodes\NodeName;
use App\Enums\ActivityAction;
use App\Enums\NodeType;
use App\Models\Node;
use App\Models\Share;
use App\Models\Upload;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\Demo;
use App\Support\StorageManager;
use Illuminate\Contracts\Filesystem\Filesystem;
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
     * On success the temporary file is removed and the upload is marked done; on failure nothing is
     * left behind on the disk (the caller marks the upload failed, see FailUpload).
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

        $sha256 = hash_file('sha256', $tmp) ?: throw new RuntimeException('Cannot read the uploaded file.');
        $mime = mime_content_type($tmp) ?: 'application/octet-stream';

        Demo::assertContentAllowed($actor, $mime);

        // Identical content of the same owner shares one blob; see duplicateOf().
        $written = null;

        if ($this->duplicateOf($ownerId, $disk->id, $sha256, $upload->size) === null) {
            $written = $this->write($upload, $disk->id, $filesystem, $key, $tmp);
        }

        try {
            $node = DB::transaction(function () use ($upload, $ownerId, $parent, $disk, $filesystem, $tmp, &$key, &$written, $sha256, $mime) {
                // FailUpload (a timeout in uploads:prune) may have given up on it meanwhile and
                // removed what it wrote; the same lock decides which of the two wins.
                $current = Upload::query()->lockForUpdate()->find($upload->id);

                if ($current === null || $current->status !== Upload::PROCESSING) {
                    throw new RuntimeException('The upload is no longer being processed.');
                }

                $owner = User::query()->lockForUpdate()->findOrFail($ownerId);
                $share = $this->claimLink($upload);

                if ($owner->quota_bytes !== null && $upload->size > $owner->remainingBytes()) {
                    throw ValidationException::withMessages(['size' => __('Not enough storage space left.')]);
                }

                // Locking the source row keeps a concurrent purge from deleting the blob under us:
                // it either finishes first (and we write our own copy) or sees our new node.
                $source = $this->duplicateOf($ownerId, $disk->id, $sha256, $upload->size, lock: true);

                if ($source !== null) {
                    $key = (string) $source->path;
                } elseif ($written === null) {
                    $written = $this->write($upload, $disk->id, $filesystem, $key, $tmp);
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
                $share?->increment('received_bytes', $upload->size);
                $current->forceFill(['status' => Upload::DONE, 'node_id' => $node->id, 'error' => null])->save();

                return $node;
            });
        } catch (Throwable $e) {
            if ($written !== null) {
                $filesystem->delete($written);
            }

            throw $e;
        }

        // A blob written for a lookup that was beaten by a duplicate in the meantime is not used.
        if ($written !== null && $written !== $node->path) {
            $filesystem->delete($written);
        }

        // Done as soon as the node exists; what follows must not turn a stored file into a failure.
        @unlink($tmp);
        $upload->refresh();

        if ($upload->share_id !== null) {
            ActivityLog::record(ActivityAction::LinkUploaded, $node, null, ['size' => $node->size, 'share_id' => $upload->share_id]);
        } else {
            ActivityLog::record(ActivityAction::Uploaded, $node, $actor, ['size' => $node->size]);
        }

        return $node;
    }

    /**
     * For an upload through a drop-box link: the link, locked, if it still accepts this file. A link
     * revoked or expired while the file was on its way, or one the file would push over its size
     * limit, refuses it. Null for an ordinary upload.
     *
     * @throws ValidationException
     */
    private function claimLink(Upload $upload): ?Share
    {
        if ($upload->share_id === null) {
            return null;
        }

        $share = Share::query()->lockForUpdate()->find($upload->share_id);

        if ($share === null || ! $share->isDropbox() || ! $share->isActive()) {
            throw ValidationException::withMessages(['size' => __('This upload link is no longer active.')]);
        }

        if ($share->max_bytes !== null && $share->received_bytes + $upload->size > $share->max_bytes) {
            throw ValidationException::withMessages(['size' => __('This upload link has reached its size limit.')]);
        }

        return $share;
    }

    /**
     * A file of the same owner on the same disk with identical content. Dedup is per owner, so
     * nobody can find out whether someone else stores a given file; quota is still charged per node.
     * The size is compared as well as the hash as a cheap guard.
     */
    private function duplicateOf(int $ownerId, int $diskId, string $sha256, int $size, bool $lock = false): ?Node
    {
        $query = Node::query()
            ->where('type', NodeType::File)
            ->where('owner_id', $ownerId)
            ->where('disk_id', $diskId)
            ->where('sha256', $sha256)
            ->where('size', $size)
            ->whereNotNull('path');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * Copy the file to the disk under $key. The key is noted on the upload first, so a crash
     * halfway can still be cleaned up, and a failed copy removes what it left on the disk.
     *
     * @return string the key written
     */
    private function write(Upload $upload, int $diskId, Filesystem $filesystem, string $key, string $tmp): string
    {
        $stream = fopen($tmp, 'rb') ?: throw new RuntimeException('Cannot read the uploaded file.');

        $upload->forceFill(['disk_id' => $diskId, 'blob_key' => $key])->saveQuietly();

        try {
            $filesystem->writeStream($key, $stream);
        } catch (Throwable $e) {
            try {
                $filesystem->delete($key);
            } catch (Throwable) {
                // The disk is probably unreachable; FailUpload tries again.
            }

            throw $e;
        } finally {
            fclose($stream);
        }

        return $key;
    }
}
