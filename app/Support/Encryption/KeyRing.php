<?php

namespace App\Support\Encryption;

use RuntimeException;

/**
 * The master keys that wrap each blob's own data key. `FERRITE_FILE_KEY` (32 random bytes, base64) is the
 * current key, stored with the id `FERRITE_FILE_KEY_ID` (default 1) in every blob it wraps; keys retired by
 * a rotation stay readable through `FERRITE_FILE_KEYS_OLD` ("id:base64,id:base64") until `files:rekey` has
 * re-wrapped every blob. Not `APP_KEY`: rotating one of them must never break the other.
 */
class KeyRing
{
    /** @var array<int, string>|null */
    private ?array $keys = null;

    public function configured(): bool
    {
        return isset($this->keys()[$this->currentId()]);
    }

    public function currentId(): int
    {
        return max(1, (int) config('ferrite.file_key_id', 1));
    }

    /**
     * @throws RuntimeException when no current key is set
     */
    public function current(): string
    {
        return $this->keys()[$this->currentId()] ?? throw new RuntimeException('FERRITE_FILE_KEY is not set, so encrypted disks cannot be written.');
    }

    /**
     * @throws RuntimeException when the blob was wrapped with a key that is not configured any more
     */
    public function key(int $id): string
    {
        return $this->keys()[$id] ?? throw new RuntimeException("The file key with id {$id} is not configured (FERRITE_FILE_KEY, FERRITE_FILE_KEYS_OLD).");
    }

    /**
     * @return array<int, string>
     */
    private function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $keys = [];

        foreach (array_filter(array_map('trim', explode(',', (string) config('ferrite.file_keys_old')))) as $pair) {
            [$id, $encoded] = array_pad(explode(':', $pair, 2), 2, '');
            $keys[(int) $id] = $this->decode($encoded, "FERRITE_FILE_KEYS_OLD entry {$id}");
        }

        $current = (string) config('ferrite.file_key');

        if ($current !== '') {
            $keys[$this->currentId()] = $this->decode($current, 'FERRITE_FILE_KEY');
        }

        return $this->keys = $keys;
    }

    private function decode(string $encoded, string $name): string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException("{$name} must be 32 random bytes, base64 encoded (php -r \"echo base64_encode(random_bytes(32));\").");
        }

        return $raw;
    }
}
