<?php

namespace App\Support;

use phpseclib3\Net\SFTP;

/**
 * A seekable, lazily read PHP stream over one remote SFTP file. Flysystem's SFTP adapter downloads
 * the whole file into a temp stream before returning it, which makes a Range request (video
 * seeking, resumed downloads) wait for the entire file. Here a seek just moves a pointer and data
 * is fetched in blocks with an offset (SFTP supports reading at any position).
 *
 * Open one with SftpStream::open(); the class is also the stream wrapper behind it.
 */
class SftpStream
{
    private const PROTOCOL = 'ferrite-sftp';

    /** Bytes fetched per request to the server. */
    private const BLOCK = 4 * 1024 * 1024;

    /** @var resource|null set by PHP */
    public $context;

    private SFTP $sftp;

    private string $remote;

    private int $size;

    private int $position = 0;

    private string $buffer = '';

    private int $bufferStart = 0;

    /**
     * @param  string  $remote  full path on the server
     * @param  int  $size  size of the file in bytes
     * @return resource
     */
    public static function open(SFTP $sftp, string $remote, int $size, int $offset = 0)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $context = stream_context_create([self::PROTOCOL => ['sftp' => $sftp, 'remote' => $remote, 'size' => $size]]);
        $stream = fopen(self::PROTOCOL.'://file', 'rb', false, $context);

        if ($stream === false) {
            throw new \RuntimeException('Cannot open the remote file.');
        }

        if ($offset > 0) {
            fseek($stream, $offset);
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $options = stream_context_get_options($this->context)[self::PROTOCOL] ?? null;

        if ($options === null) {
            return false;
        }

        $this->sftp = $options['sftp'];
        $this->remote = $options['remote'];
        $this->size = $options['size'];

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->position >= $this->size) {
            return '';
        }

        $end = $this->bufferStart + strlen($this->buffer);

        if ($this->position < $this->bufferStart || $this->position >= $end) {
            $data = $this->sftp->get($this->remote, false, $this->position, min(self::BLOCK, $this->size - $this->position));

            if (! is_string($data) || $data === '') {
                return false;
            }

            $this->buffer = $data;
            $this->bufferStart = $this->position;
        }

        $chunk = substr($this->buffer, $this->position - $this->bufferStart, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= $this->size;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->size + $offset,
            default => -1,
        };

        if ($target < 0) {
            return false;
        }

        $this->position = $target;

        return true;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => $this->size];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        $this->buffer = '';
    }
}
