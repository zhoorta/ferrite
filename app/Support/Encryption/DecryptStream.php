<?php

namespace App\Support\Encryption;

use RuntimeException;

/**
 * A read-only PHP stream over the plaintext of an encrypted blob, decrypting one chunk at a time. It starts
 * at a chunk boundary of the raw blob (so a Range request only touches the chunks it needs) and drops
 * `$skip` bytes of the first chunk. Every chunk is authenticated before a byte of it is returned; a
 * damaged, reordered or truncated blob throws instead of returning data.
 */
class DecryptStream
{
    private const PROTOCOL = 'ferrite-dec';

    /** @var resource|null set by PHP */
    public $context;

    /** @var resource */
    private $raw;

    private string $dataKey;

    private string $prefix;

    private int $size;

    private int $chunks;

    private int $index;

    private int $skip;

    private string $buffer = '';

    /**
     * @param  resource  $raw  the blob, positioned at the start of chunk $startChunk
     * @return resource
     */
    public static function open($raw, string $dataKey, string $prefix, int $size, int $startChunk, int $skip)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $context = stream_context_create([self::PROTOCOL => compact('raw', 'dataKey', 'prefix', 'size', 'startChunk', 'skip')]);
        $stream = fopen(self::PROTOCOL.'://blob', 'rb', false, $context);

        return $stream !== false ? $stream : throw new RuntimeException('Cannot open the decrypting stream.');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $options = stream_context_get_options($this->context)[self::PROTOCOL] ?? null;

        if ($options === null) {
            return false;
        }

        $this->raw = $options['raw'];
        $this->dataKey = $options['dataKey'];
        $this->prefix = $options['prefix'];
        $this->size = $options['size'];
        $this->chunks = BlobCipher::chunkCount($this->size);
        $this->index = $options['startChunk'];
        $this->skip = $options['skip'];

        return true;
    }

    public function stream_read(int $count): string|false
    {
        while (strlen($this->buffer) < $count && $this->index < $this->chunks) {
            $this->buffer .= $this->next();
        }

        $out = substr($this->buffer, 0, $count);
        $this->buffer = (string) substr($this->buffer, $count);

        return $out;
    }

    public function stream_eof(): bool
    {
        return $this->buffer === '' && $this->index >= $this->chunks;
    }

    public function stream_close(): void
    {
        if (is_resource($this->raw)) {
            fclose($this->raw);
        }

        $this->buffer = '';
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => $this->size];
    }

    private function next(): string
    {
        $sealedLength = BlobCipher::chunkLength($this->size, $this->index) + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
        $sealed = '';

        while (strlen($sealed) < $sealedLength) {
            $part = fread($this->raw, max(1, $sealedLength - strlen($sealed)));

            if ($part === false || $part === '') {
                throw new DecryptionFailed('The encrypted file is shorter than expected.');
            }

            $sealed .= $part;
        }

        $last = $this->index === $this->chunks - 1;
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($sealed, BlobCipher::chunkAd($last), BlobCipher::nonce($this->prefix, $this->index), $this->dataKey);

        if ($plain === false) {
            throw new DecryptionFailed("Chunk {$this->index} of the encrypted file failed authentication.");
        }

        $this->index++;

        if ($this->skip > 0) {
            $plain = (string) substr($plain, $this->skip);
            $this->skip = 0;
        }

        return $plain;
    }
}
