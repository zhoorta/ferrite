<?php

use App\Actions\Nodes\CopyNode;
use App\Enums\Permission;
use App\Jobs\ExtractContent;
use App\Models\Node;
use App\Models\NodeContent;
use App\Models\User;
use App\Support\Encryption\BlobCipher;
use App\Support\Encryption\DecryptionFailed;
use App\Support\StorageManager;
use App\Support\TextPreview;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $base = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
    config(['ferrite.tmp_path' => "{$base}/tmp", 'ferrite.local_root' => "{$base}/blobs", 'ferrite.chunk_size' => 1_000_000]);
    $this->base = $base;

    $this->user = User::factory()->create(['quota_bytes' => null]);
    $this->actingAs($this->user);
});

afterEach(fn () => File::deleteDirectory($this->base));

/** The bytes a disk holds for a node, exactly as stored. */
function encryptedPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function storedBytes(Node $node): string
{
    return (string) app(StorageManager::class)->filesystem($node->disk)->get($node->path);
}

function downloaded(Node $node, ?string $range = null): TestResponse
{
    return test()->get(route('nodes.download', $node), $range === null ? [] : ['Range' => $range]);
}

function apiUploadWhole(string $plain, string $path, string $content): TestResponse
{
    app('auth')->forgetGuards();
    $start = test()->postJson('/api/v1/uploads', ['path' => $path, 'size' => strlen($content)], ['Authorization' => "Bearer {$plain}"])->assertCreated();

    app('auth')->forgetGuards();

    return test()->call('PATCH', "/api/v1/uploads/{$start->json('id')}", [], [], [], [
        'HTTP_UPLOAD_OFFSET' => 0,
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => "Bearer {$plain}",
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $content);
}

it('stores the blob encrypted: no plaintext on the disk, and the size the format promises', function () {
    $content = 'the quick brown fox '.str_repeat('jumps over the lazy dog. ', 8000);
    $node = encryptedFile($this->user, 'fox.txt', $content);
    $stored = storedBytes($node);

    expect($node->encryption_key_id)->toBe(1)
        ->and(str_starts_with($stored, 'FRT1'))->toBeTrue()
        ->and(strlen($stored))->toBe(BlobCipher::cipherSize(strlen($content)))
        ->and(str_contains($stored, 'quick brown fox'))->toBeFalse()
        ->and($node->size)->toBe(strlen($content));
});

it('downloads the plaintext with the plaintext length and ETag', function () {
    $content = random_bytes(BlobCipher::CHUNK * 2 + 777);
    $node = encryptedFile($this->user, 'data.bin', $content, mime: 'application/octet-stream');

    $response = downloaded($node)->assertOk()
        ->assertHeader('Content-Length', (string) strlen($content))
        ->assertHeader('ETag', '"'.hash('sha256', $content).'"');

    expect($response->streamedContent())->toBe($content);
});

it('serves Range requests from the middle, across chunks and at the boundaries', function () {
    $content = random_bytes(BlobCipher::CHUNK * 3 + 500);
    $node = encryptedFile($this->user, 'video.bin', $content, mime: 'application/octet-stream');
    $size = strlen($content);

    foreach ([[0, 99], [65535, 65536], [65536, 70000], [100000, 150000], [$size - 10, $size - 1], [131072, $size - 1]] as [$from, $to]) {
        $response = downloaded($node, "bytes={$from}-{$to}")->assertStatus(206)->assertHeader('Content-Range', "bytes {$from}-{$to}/{$size}");

        expect($response->streamedContent())->toBe(substr($content, $from, $to - $from + 1));
    }

    expect(downloaded($node, 'bytes=-100')->streamedContent())->toBe(substr($content, -100))
        ->and(downloaded($node, "bytes={$size}-")->assertStatus(416));
});

it('reads an empty encrypted file', function () {
    $node = encryptedFile($this->user, 'empty.txt', '');

    expect(downloaded($node)->assertOk()->streamedContent())->toBe('')
        ->and(strlen(storedBytes($node)))->toBe(BlobCipher::cipherSize(0));
});

it('zips encrypted and plaintext files together', function () {
    $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Mixed']);
    encryptedFile($this->user, 'secret.txt', 'top secret', parent: $folder);
    storedFile($this->user, 'plain.txt', 'plain text', $folder);

    $response = $this->get(route('nodes.zip', $folder))->assertOk();

    expect(zipEntries($response))->toBe(['plain.txt' => 'plain text', 'secret.txt' => 'top secret']);
});

it('keeps the thumbnail of an encrypted image encrypted too', function () {
    $node = encryptedFile($this->user, 'wide.png', encryptedPng(1000, 400), mime: 'image/png');

    $response = $this->get(route('nodes.thumbnail', $node))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect(getimagesizefromstring($response->getContent())[0])->toBe(256);

    $filesystem = app(StorageManager::class)->filesystem($node->disk);
    $cached = (string) $filesystem->get("thumbnails/{$node->path}.jpg");
    expect(str_starts_with($cached, "\xFF\xD8"))->toBeFalse()->and(str_starts_with($cached, 'FRT1'))->toBeTrue();

    // Served from the cache without touching the original.
    $filesystem->delete($node->path);
    expect($this->get(route('nodes.thumbnail', $node))->assertOk()->getContent())->toBe($response->getContent());
});

it('makes a fresh thumbnail when the cached one is a plaintext leftover', function () {
    $node = encryptedFile($this->user, 'wide.png', encryptedPng(300, 300), mime: 'image/png');
    $filesystem = app(StorageManager::class)->filesystem($node->disk);
    $filesystem->put("thumbnails/{$node->path}.jpg", 'not a thumbnail');

    $response = $this->get(route('nodes.thumbnail', $node))->assertOk();

    expect(getimagesizefromstring($response->getContent()))->not->toBeFalse();
});

it('reads the text for the preview through the decrypting stream', function () {
    $node = encryptedFile($this->user, 'notes.txt', "Meeting about the zeppelin project\nsecond line");

    expect(TextPreview::read($node)['text'])->toContain('zeppelin');
});

it('keeps files on encrypted disks out of the content index unless told otherwise, and reads them when it is on', function () {
    config(['ferrite.search_contents' => true]);
    $node = encryptedFile($this->user, 'notes.txt', 'Meeting about the zeppelin project');

    ExtractContent::queueFor($node);
    ExtractContent::dispatchSync($node->id);
    expect(NodeContent::query()->where('sha256', $node->sha256)->exists())->toBeFalse();

    config(['ferrite.search_encrypted' => true]);
    ExtractContent::dispatchSync($node->id);

    expect(NodeContent::query()->where('sha256', $node->sha256)->first()?->text)->toContain('zeppelin');
});

it('copies an encrypted file: the copy is encrypted the same way and reads back', function () {
    $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Archive']);
    $node = encryptedFile($this->user, 'a.txt', 'copy me');

    $copy = app(CopyNode::class)->handle($this->user, $node, $folder);

    expect($copy->encryption_key_id)->toBe(1)
        ->and(downloaded($copy)->streamedContent())->toBe('copy me');
});

it('duplicates the blob into another owner\'s folder and keeps it readable', function () {
    $other = User::factory()->create(['quota_bytes' => null]);
    $theirs = Node::factory()->for($other, 'owner')->create(['name' => 'Theirs']);
    $theirs->sharedWith()->attach($this->user, ['permission' => Permission::Edit->value]);
    $node = encryptedFile($this->user, 'mine.txt', 'my words');

    $copy = app(CopyNode::class)->handle($this->user, $node, $theirs);

    expect($copy->owner_id)->toBe($other->id)
        ->and($copy->path)->not->toBe($node->path)
        ->and($copy->encryption_key_id)->toBe(1)
        ->and(storedBytes($copy))->toBe(storedBytes($node));

    $this->actingAs($other);
    expect(downloaded($copy)->streamedContent())->toBe('my words');
});

it('still reads plaintext files that sit on an encrypting disk, while it is being converted', function () {
    encryptedDisk(default: true);
    $plain = storedFile($this->user, 'old.txt', 'written before encryption');

    expect($plain->encryption_key_id)->toBeNull()
        ->and(downloaded($plain)->streamedContent())->toBe('written before encryption');
});

it('refuses to serve a blob that was tampered with, and says nothing of the content', function () {
    $node = encryptedFile($this->user, 'a.bin', random_bytes(BlobCipher::CHUNK + 100), mime: 'application/octet-stream');
    $path = config('ferrite.local_root').'/'.$node->path;
    $bytes = (string) file_get_contents($path);
    $bytes[BlobCipher::HEADER + 10] = $bytes[BlobCipher::HEADER + 10] ^ "\x01";
    file_put_contents($path, $bytes);

    $level = ob_get_level();

    expect(fn () => downloaded($node)->streamedContent())->toThrow(DecryptionFailed::class);

    // The response was cut off in the middle of its output buffer.
    while (ob_get_level() > $level) {
        ob_end_clean();
    }
});

it('uploads to an encrypting default disk: encrypted at rest, dedup keeps one blob, plaintext download', function () {
    encryptedDisk(default: true);
    $plain = apiToken($this->user, null, ['read', 'write']);
    $content = str_repeat('upload me! ', 20000);

    $done = apiUploadWhole($plain, 'one.txt', $content);
    $done->assertSuccessful();
    $one = Node::query()->where('name', 'one.txt')->firstOrFail();

    expect($one->encryption_key_id)->toBe(1)
        ->and(str_contains(storedBytes($one), 'upload me!'))->toBeFalse()
        ->and(downloaded($one)->streamedContent())->toBe($content);

    apiUploadWhole($plain, 'two.txt', $content)->assertSuccessful();
    $two = Node::query()->where('name', 'two.txt')->firstOrFail();

    expect($two->path)->toBe($one->path)->and($two->encryption_key_id)->toBe(1)
        ->and(downloaded($two)->streamedContent())->toBe($content);
});

it('keeps writing plaintext on a disk that does not encrypt, byte for byte as before', function () {
    $plain = apiToken($this->user, null, ['read', 'write']);

    apiUploadWhole($plain, 'plain.txt', 'just text')->assertSuccessful();
    $node = Node::query()->where('name', 'plain.txt')->firstOrFail();

    expect($node->encryption_key_id)->toBeNull()
        ->and(storedBytes($node))->toBe('just text')
        ->and(downloaded($node)->streamedContent())->toBe('just text');
});

it('never falls back to plaintext: without the file key the upload fails and leaves nothing on the disk', function () {
    encryptedDisk(default: true);
    config(['ferrite.file_key' => null]);
    $plain = apiToken($this->user, null, ['read', 'write']);

    apiUploadWhole($plain, 'nope.txt', 'must not be stored in the clear');

    expect(Node::query()->where('name', 'nope.txt')->exists())->toBeFalse()
        ->and(collect(File::allFiles(config('ferrite.local_root')))->count())->toBe(0);
});
