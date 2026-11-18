<?php

namespace App\Support\Encryption;

use RuntimeException;

/**
 * Encryption at rest for blobs (docs/encryption.md). Server-side only: it protects a stolen disk, a leaked
 * bucket or a leaked backup, not someone who holds the server and the key.
 *
 * Format of an encrypted blob, which is what a disk stores:
 *
 *   header (HEADER bytes)  "FRT1" | key id (u16) | chunk size (u32) | nonce prefix (16) | wrap nonce (24) | wrapped data key (48)
 *   chunk 0 .. n-1         XChaCha20-Poly1305 of up to CHUNK plaintext bytes, plus a 16 byte tag
 *
 * Every blob has its own random data key; the header carries it wrapped with the master key named by the
 * key id (KeyRing), so a key rotation only rewrites headers. Chunk i is sealed with the nonce `prefix . i`
 * (64 bit big endian), and the additional data says whether it is the last chunk. Chunks are therefore
 * independent: a ranged read, a seek in a video or a ZIP entry decrypts only the chunks it needs, and a
 * reordered, duplicated or truncated blob fails authentication. (libsodium's secretstream would be simpler
 * but cannot start in the middle of a stream.) The plaintext length is known from the node, so the size of
 * the blob and the position of every chunk can be computed without reading anything.
 */
class BlobCipher
{
    public const CHUNK = 65536;

    public const MAGIC = 'FRT1';

    private const TAG = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    /** magic, key id, chunk size, nonce prefix, wrap nonce, wrapped data key */
    public const HEADER = 4 + 2 + 4 + 16 + 24 + 32 + self::TAG;

    public function __construct(private KeyRing $keys) {}

    public static function chunkCount(int $plainSize): int
    {
        return max(1, intdiv($plainSize + self::CHUNK - 1, self::CHUNK));
    }

    /** Plaintext bytes in chunk $index of a blob with $plainSize bytes of content. */
    public static function chunkLength(int $plainSize, int $index): int
    {
        return $index < self::chunkCount($plainSize) - 1 ? self::CHUNK : $plainSize - $index * self::CHUNK;
    }

    /** The size of the encrypted blob for $plainSize bytes of content. */
    public static function cipherSize(int $plainSize): int
    {
        return self::HEADER + $plainSize + self::chunkCount($plainSize) * self::TAG;
    }

    /** The content size of an encrypted blob of $cipherSize bytes, or null when no content has that size. */
    public static function plainSize(int $cipherSize): ?int
    {
        $rest = $cipherSize - self::HEADER;

        if ($rest < self::TAG) {
            return null;
        }

        $plain = $rest - max(1, intdiv($rest + self::CHUNK + self::TAG - 1, self::CHUNK + self::TAG)) * self::TAG;

        return $plain >= 0 && self::cipherSize($plain) === $cipherSize ? $plain : null;
    }

    public static function nonce(string $prefix, int $index): string
    {
        return $prefix.pack('J', $index);
    }

    public static function chunkAd(bool $last): string
    {
        return $last ? "\x01" : "\x00";
    }

    /**
     * The encrypted form of a plaintext stream of $size bytes, as a stream, under the current key.
     *
     * @param  resource  $plain
     * @return resource
     */
    public function encrypt($plain, int $size)
    {
        $dataKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $prefix = random_bytes(16);

        return EncryptStream::open($plain, $size, $this->header($this->keys->currentId(), $this->keys->current(), $dataKey, $prefix), $dataKey, $prefix);
    }

    /**
     * The plaintext of an encrypted blob from byte $offset on, as a stream.
     *
     * @param  callable(int): resource  $openRaw  opens the blob positioned at the given byte
     * @return resource
     *
     * @throws DecryptionFailed
     */
    public function decrypt(callable $openRaw, int $plainSize, int $offset = 0)
    {
        $raw = $openRaw(0);

        try {
            [$keyId, $prefix, $dataKey] = $this->parseHeader($this->readExactly($raw, self::HEADER));
        } catch (\Throwable $e) {
            fclose($raw);

            throw $e;
        }

        $startChunk = intdiv(min($offset, $plainSize), self::CHUNK);

        if ($offset >= $plainSize) {
            // Nothing to read: an empty content, or a position past the end.
            $startChunk = self::chunkCount($plainSize);
        } elseif ($startChunk > 0) {
            fclose($raw);
            $raw = $openRaw(self::HEADER + $startChunk * (self::CHUNK + self::TAG));
        }

        return DecryptStream::open($raw, $dataKey, $prefix, $plainSize, $startChunk, $offset % self::CHUNK);
    }

    /**
     * Re-wrap a blob's data key with the current master key: the new header for an existing blob, whose
     * chunks stay as they are.
     *
     * @throws DecryptionFailed
     */
    public function rewrapHeader(string $header): string
    {
        [, $prefix, $dataKey] = $this->parseHeader($header);

        return $this->header($this->keys->currentId(), $this->keys->current(), $dataKey, $prefix);
    }

    /** The key id a header says it was wrapped with. */
    public function keyIdOf(string $header): int
    {
        return $this->parseHeader($header)[0];
    }

    public function encryptString(string $plain): string
    {
        $source = self::memory($plain);
        $stream = $this->encrypt($source, strlen($plain));

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
            fclose($source);
        }
    }

    /**
     * @throws DecryptionFailed
     */
    public function decryptString(string $blob): string
    {
        $plain = self::plainSize(strlen($blob));

        if ($plain === null) {
            throw new DecryptionFailed('The encrypted data has an impossible size.');
        }

        $stream = $this->decrypt(fn (int $at) => self::memory($blob, $at), $plain);

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * A readable in-memory stream holding $data, positioned at $at.
     *
     * @return resource
     */
    private static function memory(string $data, int $at = 0)
    {
        $stream = fopen('php://memory', 'w+b') ?: throw new RuntimeException('Cannot open a memory stream.');
        fwrite($stream, $data);
        fseek($stream, $at);

        return $stream;
    }

    private function header(int $keyId, string $masterKey, string $dataKey, string $prefix): string
    {
        $head = self::MAGIC.pack('n', $keyId).pack('N', self::CHUNK).$prefix;
        $wrapNonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return $head.$wrapNonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dataKey, $head, $wrapNonce, $masterKey);
    }

    /**
     * @return array{int, string, string} key id, nonce prefix, data key
     *
     * @throws DecryptionFailed
     */
    private function parseHeader(string $header): array
    {
        if (strlen($header) !== self::HEADER || ! str_starts_with($header, self::MAGIC)) {
            throw new DecryptionFailed('This is not an encrypted Ferrite file.');
        }

        $keyId = (int) (unpack('n', substr($header, 4, 2)) ?: [1 => 0])[1];
        $chunk = (int) (unpack('N', substr($header, 6, 4)) ?: [1 => 0])[1];

        if ($chunk !== self::CHUNK) {
            throw new DecryptionFailed("Unsupported chunk size {$chunk}.");
        }

        $head = substr($header, 0, 26);
        $prefix = substr($header, 10, 16);
        $wrapNonce = substr($header, 26, 24);

        try {
            $masterKey = $this->keys->key($keyId);
        } catch (RuntimeException $e) {
            throw new DecryptionFailed($e->getMessage(), previous: $e);
        }

        $dataKey = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($header, 50), $head, $wrapNonce, $masterKey);

        if ($dataKey === false) {
            throw new DecryptionFailed('The file key does not open this file (wrong FERRITE_FILE_KEY, or a damaged header).');
        }

        return [$keyId, $prefix, $dataKey];
    }

    /**
     * @param  resource  $stream
     *
     * @throws DecryptionFailed
     */
    private function readExactly($stream, int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $part = fread($stream, max(1, $length - strlen($data)));

            if ($part === false || $part === '') {
                throw new DecryptionFailed('The encrypted file is shorter than its header.');
            }

            $data .= $part;
        }

        return $data;
    }
}
