<?php

namespace App\Actions\Uploads;

use App\Models\Upload;
use InvalidArgumentException;
use RuntimeException;

class AppendChunk
{
    /**
     * Append the bytes read from $stream at $offset. Returns false, leaving the upload untouched,
     * when the chunk would run past the declared size.
     *
     * @param  resource  $stream
     */
    public function handle(Upload $upload, int $offset, $stream): bool
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('The offset cannot be negative.');
        }

        $directory = dirname($upload->tmpPath());

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the upload directory.');
        }

        $handle = fopen($upload->tmpPath(), 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Cannot open the upload file.');
        }

        try {
            // Drop bytes from an earlier attempt that died before the offset was saved.
            ftruncate($handle, $offset);
            fseek($handle, $offset);

            $allowed = $upload->size - $offset;
            stream_copy_to_stream($stream, $handle, $allowed + 1);
            $written = ftell($handle) - $offset;

            if ($written > $allowed) {
                ftruncate($handle, $offset);

                return false;
            }
        } finally {
            fclose($handle);
        }

        $upload->offset = $offset + $written;
        $upload->save();

        return true;
    }
}
