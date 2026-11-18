<?php

namespace App\Support\Encryption;

use RuntimeException;

/**
 * A read-only stream of a string followed by the rest of another stream: a new header on an old blob, for a key
 * rotation that must not touch the encrypted chunks.
 */
class ConcatStream
{
    private const PROTOCOL = 'ferrite-concat';

    /** @var resource|null set by PHP */
    public $context;

    private string $head = '';

    /** @var resource */
    private $tail;

    /**
     * @param  resource  $tail  read from where it is positioned
     * @return resource
     */
    public static function open(string $head, $tail)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $context = stream_context_create([self::PROTOCOL => compact('head', 'tail')]);
        $stream = fopen(self::PROTOCOL.'://blob', 'rb', false, $context);

        return $stream !== false ? $stream : throw new RuntimeException('Cannot open the stream.');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $options = stream_context_get_options($this->context)[self::PROTOCOL] ?? null;

        if ($options === null) {
            return false;
        }

        $this->head = $options['head'];
        $this->tail = $options['tail'];

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->head !== '') {
            $out = substr($this->head, 0, $count);
            $this->head = (string) substr($this->head, $count);

            return $out;
        }

        return fread($this->tail, max(1, $count));
    }

    public function stream_eof(): bool
    {
        return $this->head === '' && feof($this->tail);
    }

    public function stream_close(): void
    {
        if (is_resource($this->tail)) {
            fclose($this->tail);
        }
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
