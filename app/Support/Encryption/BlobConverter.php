<?php

namespace App\Support\Encryption;

use App\Models\Node;
use App\Models\StorageDisk;
use App\Support\StorageManager;
use RuntimeException;

/**
 * Rewrites one stored blob in another form, for `files:encrypt` (plaintext to encrypted) and `files:rekey`
 * (re-wrap the data key with the current master key). Each writes a new blob under a new key and checks it by
 * reading it back; the caller points the nodes at it and removes the old one, so a crash at any moment leaves
 * every file readable and the command can simply run again.
 */
class BlobConverter
{
    public function __construct(private StorageManager $storage, private BlobCipher $cipher, private KeyRing $keys) {}

    /**
     * Write an encrypted copy of a plaintext blob (the disk must be set to encrypt).
     *
     * @return array{string, int} the new key and the master key id
     */
    public function encrypt(StorageDisk $disk, Node $sample, bool $verify = true): array
    {
        $key = $this->storage->newKey();
        $source = $this->storage->openStream($disk, (string) $sample->path, $sample->size);

        try {
            $keyId = $this->storage->writeBlob($disk, $key, $source, $sample->size);
        } finally {
            fclose($source);
        }

        if ($keyId === null) {
            $this->discard($disk, $key);

            throw new RuntimeException('The disk is not set to encrypt.');
        }

        $this->check($disk, $key, $sample, $keyId, $verify);

        return [$key, $keyId];
    }

    /**
     * Write a copy of an encrypted blob whose header is wrapped with the current master key; the chunks are copied as
     * they are, so the content is neither decrypted nor re-encrypted.
     *
     * @return array{string, int}
     */
    public function rewrap(StorageDisk $disk, Node $sample, bool $verify = true): array
    {
        $key = $this->storage->newKey();
        $size = BlobCipher::cipherSize($sample->size);
        $source = $this->storage->openStream($disk, (string) $sample->path, $size);

        try {
            $header = (string) stream_get_contents($source, BlobCipher::HEADER);
            $stream = ConcatStream::open($this->cipher->rewrapHeader($header), $source);
            $source = null;
        } catch (\Throwable $e) {
            if (is_resource($source)) {
                fclose($source);
            }

            throw $e;
        }

        try {
            $this->storage->filesystem($disk)->writeStream($key, $stream);
        } finally {
            fclose($stream);
        }

        $this->check($disk, $key, $sample, $this->keys->currentId(), $verify);

        return [$key, $this->keys->currentId()];
    }

    /** Remove a blob that was written but never used. */
    public function discard(StorageDisk $disk, string $key): void
    {
        $filesystem = $this->storage->filesystem($disk);
        $filesystem->delete($key);
        $filesystem->delete("thumbnails/{$key}.jpg");
    }

    /**
     * Read the new blob back and compare it with the sha256 the node has always had; a mismatch removes the new blob.
     */
    private function check(StorageDisk $disk, string $key, Node $sample, int $keyId, bool $verify): void
    {
        $size = $this->storage->filesystem($disk)->size($key);

        if ($size !== BlobCipher::cipherSize($sample->size)) {
            $this->discard($disk, $key);

            throw new RuntimeException("The new blob has {$size} bytes, expected ".BlobCipher::cipherSize($sample->size).'.');
        }

        if (! $verify) {
            return;
        }

        try {
            $stream = $this->storage->openBlobStream($disk, $key, $sample->size, $keyId);
            $context = hash_init('sha256');

            try {
                hash_update_stream($context, $stream);
            } finally {
                fclose($stream);
            }

            $matches = hash_equals((string) $sample->sha256, hash_final($context));
        } catch (DecryptionFailed $e) {
            $this->discard($disk, $key);

            throw $e;
        }

        if (! $matches) {
            $this->discard($disk, $key);

            throw new RuntimeException('The new blob does not read back as the original content.');
        }
    }
}
