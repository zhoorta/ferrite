<?php

use App\Support\Encryption\BlobCipher;
use App\Support\Encryption\DecryptionFailed;
use App\Support\Encryption\KeyRing;

function testKey(): string
{
    return base64_encode(str_repeat('k', 32));
}

function cipher(?string $key = null, int $id = 1, ?string $old = null): BlobCipher
{
    config(['ferrite.file_key' => $key ?? testKey(), 'ferrite.file_key_id' => $id, 'ferrite.file_keys_old' => $old]);

    return new BlobCipher(new KeyRing);
}

/** Encrypt $plain to a string. */
function sealed(BlobCipher $cipher, string $plain): string
{
    $source = fopen('php://memory', 'w+b');
    fwrite($source, $plain);
    rewind($source);
    $stream = $cipher->encrypt($source, strlen($plain));
    $blob = stream_get_contents($stream);
    fclose($stream);

    return $blob;
}

/** Decrypt $blob from $offset to the end (or $length bytes of it). */
function opened(BlobCipher $cipher, string $blob, int $plainSize, int $offset = 0, ?int $length = null): string
{
    $stream = $cipher->decrypt(function (int $at) use ($blob) {
        $raw = fopen('php://memory', 'w+b');
        fwrite($raw, $blob);
        fseek($raw, $at);

        return $raw;
    }, $plainSize, $offset);

    try {
        return $length === null ? (string) stream_get_contents($stream) : (string) stream_get_contents($stream, $length);
    } finally {
        fclose($stream);
    }
}

it('round trips content of every awkward size', function (int $size) {
    $cipher = cipher();
    $plain = $size === 0 ? '' : random_bytes($size);
    $blob = sealed($cipher, $plain);

    expect(strlen($blob))->toBe(BlobCipher::cipherSize($size))
        ->and(BlobCipher::plainSize(strlen($blob)))->toBe($size)
        ->and(opened($cipher, $blob, $size))->toBe($plain)
        ->and($size < 16 || ! str_contains($blob, substr($plain, 0, 16)))->toBeTrue();
})->with([0, 1, 100, 65535, 65536, 65537, 131072, 200000, 65536 * 3 + 5]);

it('reads any range, at and across chunk boundaries', function () {
    $cipher = cipher();
    $size = BlobCipher::CHUNK * 3 + 1234;
    $plain = random_bytes($size);
    $blob = sealed($cipher, $plain);

    foreach ([0, 1, 65535, 65536, 65537, 131071, 131072, 196608, $size - 1] as $offset) {
        expect(opened($cipher, $blob, $size, $offset))->toBe(substr($plain, $offset))
            ->and(opened($cipher, $blob, $size, $offset, 70000))->toBe(substr($plain, $offset, 70000));
    }

    expect(opened($cipher, $blob, $size, $size))->toBe('')
        ->and(opened($cipher, $blob, $size, $size + 99))->toBe('');
});

it('does not read the chunks before a range', function () {
    $cipher = cipher();
    $size = BlobCipher::CHUNK * 4;
    $blob = sealed($cipher, random_bytes($size));
    $opened = [];

    $stream = $cipher->decrypt(function (int $at) use ($blob, &$opened) {
        $opened[] = $at;
        $raw = fopen('php://memory', 'w+b');
        fwrite($raw, $blob);
        fseek($raw, $at);

        return $raw;
    }, $size, BlobCipher::CHUNK * 3);
    fread($stream, 10);
    fclose($stream);

    expect($opened)->toBe([0, BlobCipher::HEADER + 3 * (BlobCipher::CHUNK + 16)]);
});

it('rejects a tampered chunk, and returns nothing from it', function () {
    $cipher = cipher();
    $size = BlobCipher::CHUNK * 2 + 10;
    $blob = sealed($cipher, random_bytes($size));
    $blob[BlobCipher::HEADER + BlobCipher::CHUNK + 16 + 5] = $blob[BlobCipher::HEADER + BlobCipher::CHUNK + 16 + 5] ^ "\x01";

    // The first chunk is fine, the second is not.
    expect(fn () => opened($cipher, $blob, $size))->toThrow(DecryptionFailed::class)
        ->and(strlen(opened($cipher, $blob, $size, 0, 100)))->toBe(100);
    expect(fn () => opened($cipher, $blob, $size, BlobCipher::CHUNK))->toThrow(DecryptionFailed::class);
});

it('rejects a truncated blob, swapped chunks and a changed header', function () {
    $cipher = cipher();
    $size = BlobCipher::CHUNK * 3;
    $plain = random_bytes($size);
    $blob = sealed($cipher, $plain);
    $chunk = BlobCipher::CHUNK + 16;

    // Cut after the second chunk: the third is missing, and "the end" is not a valid last chunk.
    $cut = substr($blob, 0, BlobCipher::HEADER + 2 * $chunk);
    expect(fn () => opened($cipher, $cut, $size))->toThrow(DecryptionFailed::class);

    // Dropping the last chunk and claiming a smaller size: the new last chunk was not sealed as the last one.
    expect(fn () => opened($cipher, $cut, BlobCipher::CHUNK * 2))->toThrow(DecryptionFailed::class);

    $swapped = substr($blob, 0, BlobCipher::HEADER).substr($blob, BlobCipher::HEADER + $chunk, $chunk).substr($blob, BlobCipher::HEADER, $chunk).substr($blob, BlobCipher::HEADER + 2 * $chunk);
    expect(fn () => opened($cipher, $swapped, $size))->toThrow(DecryptionFailed::class);

    $badHeader = $blob;
    $badHeader[20] = $badHeader[20] ^ "\x01";
    expect(fn () => opened($cipher, $badHeader, $size))->toThrow(DecryptionFailed::class);

    expect(fn () => opened($cipher, str_repeat('x', BlobCipher::cipherSize(10)), 10))->toThrow(DecryptionFailed::class, 'not an encrypted');
});

it('refuses the wrong key and a blob whose key is not configured any more', function () {
    $blob = sealed(cipher(), 'secret');

    expect(fn () => opened(cipher(base64_encode(str_repeat('x', 32))), $blob, 6))->toThrow(DecryptionFailed::class, 'does not open')
        ->and(fn () => opened(cipher(base64_encode(str_repeat('x', 32)), 2), $blob, 6))->toThrow(DecryptionFailed::class, 'id 1 is not configured');
});

it('reads old blobs after a rotation and re-wraps their header with the new key', function () {
    $oldKey = testKey();
    $new = base64_encode(str_repeat('n', 32));
    $plain = random_bytes(BlobCipher::CHUNK + 9);
    $blob = sealed(cipher($oldKey, 1), $plain);

    $rotated = cipher($new, 2, "1:{$oldKey}");
    expect(opened($rotated, $blob, strlen($plain)))->toBe($plain);

    $header = $rotated->rewrapHeader(substr($blob, 0, BlobCipher::HEADER));
    $rewrapped = $header.substr($blob, BlobCipher::HEADER);

    expect($rotated->keyIdOf($header))->toBe(2)
        ->and(opened(cipher($new, 2), $rewrapped, strlen($plain)))->toBe($plain);
});

it('gives each blob its own data key and nonce, so equal content never encrypts equally', function () {
    $cipher = cipher();

    expect(sealed($cipher, 'same'))->not->toBe(sealed($cipher, 'same'));
});

it('round trips short strings, as used for thumbnails', function () {
    $cipher = cipher();

    foreach (['', 'x', random_bytes(70000)] as $plain) {
        $blob = $cipher->encryptString($plain);

        expect($cipher->decryptString($blob))->toBe($plain);
    }

    expect(fn () => $cipher->decryptString('nope'))->toThrow(DecryptionFailed::class);
});

it('refuses to encrypt without a key and rejects a malformed key', function () {
    config(['ferrite.file_key' => null, 'ferrite.file_key_id' => 1, 'ferrite.file_keys_old' => null]);
    expect((new KeyRing)->configured())->toBeFalse()
        ->and(fn () => (new BlobCipher(new KeyRing))->encryptString('x'))->toThrow(RuntimeException::class, 'FERRITE_FILE_KEY is not set');

    config(['ferrite.file_key' => 'not base64!']);
    expect(fn () => (new KeyRing)->current())->toThrow(RuntimeException::class, '32 random bytes');
});

it('says when the content is not the size that was declared', function () {
    $cipher = cipher();
    $source = fopen('php://memory', 'w+b');
    fwrite($source, 'abcdef');
    rewind($source);

    $short = $cipher->encrypt($source, 10);
    expect(fn () => stream_get_contents($short))->toThrow(RuntimeException::class, 'shorter');

    rewind($source);
    $long = $cipher->encrypt($source, 3);
    expect(fn () => stream_get_contents($long))->toThrow(RuntimeException::class, 'longer');
});
