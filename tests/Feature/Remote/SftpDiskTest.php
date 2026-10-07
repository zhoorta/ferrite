<?php

/*
 * Runs against a real SFTP server and is skipped unless FERRITE_TEST_SFTP_HOST is set. Use a
 * scratch directory: the test writes (and removes) its own files under FERRITE_TEST_SFTP_ROOT.
 *
 *   FERRITE_TEST_SFTP_HOST=u123.your-storagebox.de FERRITE_TEST_SFTP_PORT=23 \
 *   FERRITE_TEST_SFTP_USER=u123 FERRITE_TEST_SFTP_KEY=~/.ssh/id_ed25519 \
 *   FERRITE_TEST_SFTP_ROOT=/ferrite-test FERRITE_TEST_SFTP_MB=3000 \
 *   php artisan test --compact tests/Feature/Remote
 *
 * FERRITE_TEST_SFTP_PASSWORD works instead of a key; FERRITE_TEST_SFTP_MB is the file size
 * (default 64). Prints how long the last chunk, the storing job and a Range read near the end took.
 */

use App\Actions\Uploads\CompleteUpload;
use App\Actions\Uploads\FailUpload;
use App\Jobs\FinalizeUpload;
use App\Models\Node;
use App\Models\StorageDisk;
use App\Models\Upload;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->host = getenv('FERRITE_TEST_SFTP_HOST') ?: null;

    if ($this->host === null) {
        $this->markTestSkipped('Set FERRITE_TEST_SFTP_HOST to test against a real SFTP server.');
    }

    $key = getenv('FERRITE_TEST_SFTP_KEY') ?: null;

    $this->disk = StorageDisk::create([
        'name' => 'remote-test',
        'driver' => 'sftp',
        'is_default' => true,
        'config' => array_filter([
            'host' => $this->host,
            'port' => (int) (getenv('FERRITE_TEST_SFTP_PORT') ?: 22),
            'username' => getenv('FERRITE_TEST_SFTP_USER') ?: null,
            'password' => getenv('FERRITE_TEST_SFTP_PASSWORD') ?: null,
            'privateKey' => $key === null ? null : file_get_contents(str_replace('~', (string) getenv('HOME'), $key)),
            'root' => rtrim(getenv('FERRITE_TEST_SFTP_ROOT') ?: '/ferrite-test', '/').'/run-'.bin2hex(random_bytes(3)),
            'timeout' => 30,
        ]),
    ]);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    config(['ferrite.chunk_size' => 5 * 1024 * 1024]);
});

afterEach(function () {
    if (isset($this->disk)) {
        try {
            app(StorageManager::class)->filesystem($this->disk)->deleteDirectory('');
        } catch (Throwable) {
            // Nothing to clean up.
        }
    }
});

/** Bytes $offset up to $offset + $length of the generated file, so any range can be checked. */
function remotePattern(int $offset, int $length): string
{
    $block = hash('sha256', 'ferrite', true); // 32 bytes, repeated

    $out = '';
    for ($i = $offset; $i < $offset + $length; $i++) {
        $out .= $block[$i % 32];
    }

    return $out;
}

it('stores a big file through the queue and serves ranges from it without downloading it first', function () {
    Queue::fake();

    $size = (int) (getenv('FERRITE_TEST_SFTP_MB') ?: 64) * 1024 * 1024;
    $chunk = config('ferrite.chunk_size');

    $id = $this->postJson(route('uploads.store'), ['path' => 'big.bin', 'size' => $size])->json('id');
    $sha = hash_init('sha256');
    $unit = str_repeat(hash('sha256', 'ferrite', true), $chunk / 32 + 1);

    $lastChunkSeconds = 0;

    for ($offset = 0; $offset < $size; $offset += $chunk) {
        $length = min($chunk, $size - $offset);
        // $chunk is a multiple of 32, so every chunk starts on a pattern boundary.
        $body = substr($unit, 0, $length);
        hash_update($sha, $body);

        $started = microtime(true);
        $response = $this->call('PATCH', route('uploads.update', $id), [], [], [], [
            'HTTP_UPLOAD_OFFSET' => $offset, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream',
        ], $body);
        $lastChunkSeconds = microtime(true) - $started;
    }

    $response->assertStatus(202)->assertJson(['status' => 'processing']);
    expect($lastChunkSeconds)->toBeLessThan(10)
        ->and(Node::count())->toBe(0);

    $started = microtime(true);
    (new FinalizeUpload($id))->handle(app(CompleteUpload::class), app(FailUpload::class));
    $jobSeconds = microtime(true) - $started;

    $node = Node::firstWhere('name', 'big.bin');
    expect($node)->not->toBeNull()
        ->and($node->sha256)->toBe(hash_final($sha))
        ->and($node->size)->toBe($size)
        ->and(app(StorageManager::class)->filesystem($this->disk)->size($node->path))->toBe($size);

    // A range at the very end must not read everything before it.
    $started = microtime(true);
    $tail = $this->get(route('nodes.download', $node), ['Range' => 'bytes='.($size - 100).'-'.($size - 1)]);
    $tailContent = $tail->streamedContent();
    $rangeSeconds = microtime(true) - $started;

    $tail->assertStatus(206);
    expect($tailContent)->toBe(remotePattern($size - 100, 100));

    $middle = $this->get(route('nodes.download', $node), ['Range' => 'bytes=1000003-1000102']);
    expect($middle->streamedContent())->toBe(remotePattern(1000003, 100));

    fwrite(STDERR, sprintf(
        "\nSFTP %d MB: last chunk %.2fs, storing job %.1fs, range at the end %.2fs\n",
        $size / 1024 / 1024, $lastChunkSeconds, $jobSeconds, $rangeSeconds,
    ));

    expect(Upload::find($id)->status)->toBe('done');
});
