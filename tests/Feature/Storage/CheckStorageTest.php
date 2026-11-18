<?php

use App\Models\Node;
use App\Models\Upload;
use App\Models\User;
use App\Support\Encryption\BlobCipher;
use App\Support\StorageManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $base = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
    config(['ferrite.tmp_path' => "{$base}/tmp", 'ferrite.local_root' => "{$base}/blobs"]);
    $this->base = $base;

    $this->user = User::factory()->create(['quota_bytes' => null]);
    $this->disk = app(StorageManager::class)->default();
});

afterEach(fn () => File::deleteDirectory($this->base));

function blobFile(Node $node): string
{
    return config('ferrite.local_root').'/'.$node->path;
}

it('is quiet when everything is where the database says', function () {
    storedFile($this->user, 'a.txt', 'alpha');
    encryptedFile($this->user, 'b.txt', random_bytes(BlobCipher::CHUNK + 5), mime: 'application/octet-stream');

    $this->artisan('ferrite:storage:check')->expectsOutputToContain('no problems')->assertSuccessful();
    $this->artisan('ferrite:storage:check', ['--verify' => true])->expectsOutputToContain('Verified 2 blobs')->assertSuccessful();
});

it('reports a missing blob and a blob of the wrong size', function () {
    $missing = storedFile($this->user, 'gone.txt', 'gone');
    $short = storedFile($this->user, 'short.txt', 'twelve bytes');
    $encryptedShort = encryptedFile($this->user, 'enc.txt', 'some content here');
    unlink(blobFile($missing));
    file_put_contents(blobFile($short), 'tiny');
    file_put_contents(blobFile($encryptedShort), substr(file_get_contents(blobFile($encryptedShort)), 0, -3));

    Log::spy();
    $this->artisan('ferrite:storage:check')
        ->expectsOutputToContain('MISSING')
        ->expectsOutputToContain('4 bytes on the disk, expected 12')
        ->expectsOutputToContain('3 problems')
        ->assertFailed();

    Log::shouldHaveReceived('error')->once();
});

it('finds bit rot that keeps the size, only when asked to verify, plain and encrypted', function () {
    $plain = storedFile($this->user, 'plain.txt', 'the original text');
    $enc = encryptedFile($this->user, 'enc.txt', 'the original text, encrypted');
    $good = storedFile($this->user, 'good.txt', 'untouched');

    file_put_contents(blobFile($plain), 'the origiXal text');
    $bytes = file_get_contents(blobFile($enc));
    $bytes[BlobCipher::HEADER + 4] = $bytes[BlobCipher::HEADER + 4] ^ "\x01";
    file_put_contents(blobFile($enc), $bytes);

    $this->artisan('ferrite:storage:check')->expectsOutputToContain('no problems')->assertSuccessful();

    $this->artisan('ferrite:storage:check', ['--verify' => true])
        ->expectsOutputToContain('CORRUPT')
        ->expectsOutputToContain('UNREADABLE')
        ->expectsOutputToContain('2 problems')
        ->assertFailed();

    expect($good->fresh()->verified_at)->not->toBeNull()
        ->and($plain->fresh()->verified_at)->toBeNull()
        ->and($enc->fresh()->verified_at)->toBeNull();
});

it('checks a shared blob once and marks every file that uses it', function () {
    $a = storedFile($this->user, 'a.txt', 'shared');
    $twin = $a->replicate(['updated_at'])->forceFill(['name' => 'twin.txt', 'owner_id' => $this->user->id]);
    $twin->save();

    $this->artisan('ferrite:storage:check', ['--verify' => true])->expectsOutputToContain('Verified 1 blobs')->assertSuccessful();

    expect($a->fresh()->verified_at)->not->toBeNull()->and($twin->fresh()->verified_at)->not->toBeNull();
});

it('verifies the least recently verified blobs first when limited, and so covers everything over time', function () {
    $first = storedFile($this->user, 'a.txt', 'one');
    $second = storedFile($this->user, 'b.txt', 'two');
    $third = storedFile($this->user, 'c.txt', 'three');
    $second->forceFill(['verified_at' => now()->subDay()])->save();
    $third->forceFill(['verified_at' => now()->subDays(5)])->save();

    $this->artisan('ferrite:storage:check', ['--verify' => true, '--limit' => 1])->assertSuccessful();

    // The never verified one went first, then the oldest.
    expect($first->fresh()->verified_at)->not->toBeNull()->and($third->fresh()->verified_at->isToday())->toBeFalse();

    $this->artisan('ferrite:storage:check', ['--verify' => true, '--limit' => 1])->assertSuccessful();
    expect($third->fresh()->verified_at->isToday())->toBeTrue()->and($second->fresh()->verified_at->isToday())->toBeFalse();
});

it('leaves folders, blob-less nodes and the updated_at of files alone', function () {
    Node::factory()->for($this->user, 'owner')->create(['name' => 'Folder']);
    $file = storedFile($this->user, 'a.txt', 'x');
    $touched = $file->updated_at;
    $this->travel(2)->days();

    $this->artisan('ferrite:storage:check', ['--verify' => true])->expectsOutputToContain('Verified 1 blobs')->assertSuccessful();

    expect($file->fresh()->updated_at->equalTo($touched))->toBeTrue();
});

it('lists orphans: blobs nobody points at, but not thumbnails, unfinished uploads or used blobs', function () {
    $used = storedFile($this->user, 'used.txt', 'used');
    $filesystem = app(StorageManager::class)->filesystem($this->disk);
    $filesystem->put('aa/orphan-blob', 'lost');
    $filesystem->put("thumbnails/{$used->path}.jpg", 'thumb');
    $filesystem->put('bb/pending-upload', 'half');
    Upload::unguard();
    Upload::query()->create(['user_id' => $this->user->id, 'disk_id' => $this->disk->id, 'blob_key' => 'bb/pending-upload', 'status' => Upload::PROCESSING, 'name' => 'p.txt', 'size' => 4, 'fingerprint' => 'x']);
    Upload::reguard();

    $this->artisan('ferrite:storage:check', ['--orphans' => true])
        ->expectsOutputToContain('aa/orphan-blob')
        ->doesntExpectOutputToContain('pending-upload')
        ->doesntExpectOutputToContain('thumbnails')
        ->expectsOutputToContain('1 orphans')
        ->assertSuccessful();
});

it('can be limited to one disk and refuses an unknown one', function () {
    storedFile($this->user, 'a.txt', 'x');

    $this->artisan('ferrite:storage:check', ['--disk' => $this->disk->name])->assertSuccessful();
    $this->artisan('ferrite:storage:check', ['--disk' => 'nope'])->assertFailed();
});

it('is scheduled nightly only when FERRITE_SCRUB_BLOBS is set', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command, 'ferrite:storage:check'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->filtersPass(app()))->toBeFalse();

    config(['ferrite.scrub_blobs' => 50]);
    expect($events->first()->filtersPass(app()))->toBeTrue();
});
