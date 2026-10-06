<?php

use App\Models\User;
use App\Support\StorageManager;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function pngBytes(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

it('makes a cached JPEG thumbnail that fits in the thumbnail size', function () {
    $node = storedFile($this->user, 'wide.png', pngBytes(1000, 400), mime: 'image/png');

    $response = $this->get(route('nodes.thumbnail', $node))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $size = getimagesizefromstring($response->getContent());
    expect($size[0])->toBe(256)->and($size[1])->toBe(102)->and($size['mime'])->toBe('image/jpeg');

    $filesystem = app(StorageManager::class)->filesystem($node->disk);
    expect($filesystem->exists("thumbnails/{$node->path}.jpg"))->toBeTrue();

    // Served from the cache even when the original is gone.
    $filesystem->delete($node->path);
    $this->get(route('nodes.thumbnail', $node))->assertOk();
});

it('does not enlarge small images', function () {
    $node = storedFile($this->user, 'small.png', pngBytes(40, 30), mime: 'image/png');

    $size = getimagesizefromstring($this->get(route('nodes.thumbnail', $node))->getContent());

    expect([$size[0], $size[1]])->toBe([40, 30]);
});

it('answers 304 when the thumbnail ETag matches', function () {
    $node = storedFile($this->user, 'a.png', pngBytes(10, 10), mime: 'image/png');
    $etag = $this->get(route('nodes.thumbnail', $node))->headers->get('ETag');

    $this->get(route('nodes.thumbnail', $node), ['If-None-Match' => $etag])->assertStatus(304);
});

it('has no thumbnail for non-images, corrupt images or other people\'s files', function () {
    $text = storedFile($this->user, 'a.txt', 'hello');
    $corrupt = storedFile($this->user, 'bad.png', 'not a png', mime: 'image/png');
    $svg = storedFile($this->user, 'a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', mime: 'image/svg+xml');
    $theirs = storedFile(User::factory()->create(), 'x.png', pngBytes(10, 10), mime: 'image/png');

    foreach ([$text, $corrupt, $svg] as $node) {
        $this->get(route('nodes.thumbnail', $node))->assertNotFound();
    }

    $this->get(route('nodes.thumbnail', $theirs))->assertForbidden();
});

it('skips images with too many pixels', function () {
    // A 50000x50000 PNG header with no real data: the size check must stop it before decoding.
    $ihdr = pack('NNCCCCC', 50000, 50000, 8, 2, 0, 0, 0);
    $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    $node = storedFile($this->user, 'huge.png', $png, mime: 'image/png');

    $this->get(route('nodes.thumbnail', $node))->assertNotFound();
});
