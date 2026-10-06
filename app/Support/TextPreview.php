<?php

namespace App\Support;

use App\Models\Node;

class TextPreview
{
    private const LIMIT = 64 * 1024;

    /**
     * The start of a text file, as valid UTF-8, cut at a size that is comfortable to show.
     *
     * @return array{text: string, truncated: bool}
     */
    public static function read(Node $node): array
    {
        if ($node->disk === null || $node->path === null) {
            return ['text' => '', 'truncated' => false];
        }

        $stream = app(StorageManager::class)->filesystem($node->disk)->readStream($node->path);

        if ($stream === null) {
            return ['text' => '', 'truncated' => false];
        }

        $text = (string) stream_get_contents($stream, self::LIMIT);
        fclose($stream);

        return ['text' => mb_scrub($text, 'UTF-8'), 'truncated' => $node->size > self::LIMIT];
    }
}
