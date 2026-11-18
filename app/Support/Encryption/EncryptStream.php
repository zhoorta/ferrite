<?php

namespace App\Support\Encryption;

use RuntimeException;

/**
 * A read-only PHP stream that yields the encrypted form of another stream (header, then one authenticated
 * chunk after the other), so a blob is encrypted while Flysystem copies it to its disk, in constant memory.
 * See BlobCipher for the format. The plaintext length must be known up front: the last chunk is marked as
 * such, which is what makes a truncated blob detectable.
 */
class EncryptStream
{
    private const PROTOCOL = 'ferrite-enc';

    /** @var resource|null set by PHP */
    public $context;

    /** @var resource */
    private $plain;

    private string $dataKey;

    private string $prefix;

    private int $size;

    private int $chunks;

    private int $index = 0;

    private string $buffer = '';

    private bool $headerSent = false;

    private string $header;

    /**
     * @param  resource  $plain  positioned at the start of the content
     * @return resource
     */
    public static function open($plain, int $size, string $header, string $dataKey, string $prefix)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $context = stream_context_create([self::PROTOCOL => compact('plain', 'size', 'header', 'dataKey', 'prefix')]);
        $stream = fopen(self::PROTOCOL.'://blob', 'rb', false, $context);

        return $stream !== false ? $stream : throw new RuntimeException('Cannot open the encrypting stream.');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $options = stream_context_get_options($this->context)[self::PROTOCOL] ?? null;

        if ($options === null) {
            return false;
        }

        $this->plain = $options['plain'];
        $this->size = $options['size'];
        $this->header = $options['header'];
        $this->dataKey = $options['dataKey'];
        $this->prefix = $options['prefix'];
        $this->chunks = BlobCipher::chunkCount($this->size);

        return true;
    }

    public function stream_read(int $count): string|false
    {
        while (strlen($this->buffer) < $count && ! $this->finished()) {
            $this->buffer .= $this->next();
        }

        $out = substr($this->buffer, 0, $count);
        $this->buffer = (string) substr($this->buffer, $count);

        return $out;
    }

    public function stream_eof(): bool
    {
        return $this->buffer === '' && $this->finished();
    }

    public function stream_close(): void
    {
        $this->buffer = '';
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => BlobCipher::cipherSize($this->size)];
    }

    private function finished(): bool
    {
        return $this->headerSent && $this->index >= $this->chunks;
    }

    private function next(): string
    {
        if (! $this->headerSent) {
            $this->headerSent = true;

            return $this->header;
        }

        $length = BlobCipher::chunkLength($this->size, $this->index);
        $plain = '';

        while (strlen($plain) < $length) {
            $part = fread($this->plain, max(1, $length - strlen($plain)));

            if ($part === false || $part === '') {
                throw new RuntimeException('The file is shorter than its declared size.');
            }

            $plain .= $part;
        }

        $last = $this->index === $this->chunks - 1;

        if ($last) {
            $extra = fread($this->plain, 1);

            if ($extra !== '' && $extra !== false) {
                throw new RuntimeException('The file is longer than its declared size.');
            }
        }

        $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, BlobCipher::chunkAd($last), BlobCipher::nonce($this->prefix, $this->index), $this->dataKey);
        $this->index++;

        return $sealed;
    }
}
