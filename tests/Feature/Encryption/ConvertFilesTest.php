<?php

use App\Models\Node;
use App\Models\StorageDisk;
use App\Models\User;
use App\Support\Encryption\BlobCipher;
use App\Support\StorageManager;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $base = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
    config(['ferrite.tmp_path' => "{$base}/tmp", 'ferrite.local_root' => "{$base}/blobs", 'ferrite.file_key' => base64_encode(str_repeat('k', 32)), 'ferrite.file_key_id' => 1, 'ferrite.file_keys_old' => null]);
    $this->base = $base;

    $this->user = User::factory()->create(['quota_bytes' => null]);
    $this->actingAs($this->user);
    $this->disk = app(StorageManager::class)->default();
});

afterEach(fn () => File::deleteDirectory($this->base));

function blobCount(): int
{
    return collect(File::allFiles(config('ferrite.local_root')))->reject(fn ($file) => str_contains($file->getPathname(), '/thumbnails/'))->count();
}

function bytesOf(Node $node): string
{
    return (string) app(StorageManager::class)->filesystem($node->disk)->get($node->path);
}

it('encrypts the plaintext files of a disk in place and keeps them readable', function () {
    $big = random_bytes(BlobCipher::CHUNK * 2 + 50);
    $a = storedFile($this->user, 'a.txt', 'alpha');
    $b = storedFile($this->user, 'b.bin', $big, mime: 'application/octet-stream');
    $empty = storedFile($this->user, 'empty.txt', '');
    $oldPaths = [$a->path, $b->path, $empty->path];
    $touched = $a->updated_at;
    $this->travel(2)->days();

    $this->artisan('files:encrypt', ['disk' => $this->disk->name, '--yes' => true])->assertSuccessful();

    foreach ([$a, $b, $empty] as $node) {
        $fresh = $node->fresh();
        expect($fresh->encryption_key_id)->toBe(1)
            ->and(str_starts_with(bytesOf($fresh), 'FRT1'))->toBeTrue()
            ->and($fresh->sha256)->toBe($node->sha256)
            ->and($fresh->size)->toBe($node->size);
    }

    expect($a->fresh()->updated_at->equalTo($touched))->toBeTrue()
        ->and($this->disk->fresh()->encrypt)->toBeTrue()
        ->and(blobCount())->toBe(3)
        ->and(bytesOf($a->fresh()))->not->toContain('alpha');

    foreach ($oldPaths as $old) {
        expect(app(StorageManager::class)->filesystem($this->disk)->exists($old))->toBeFalse();
    }

    expect(stream_get_contents(app(StorageManager::class)->openNodeStream($b->fresh())))->toBe($big)
        ->and($this->get(route('nodes.download', $a->fresh()))->streamedContent())->toBe('alpha');
});

it('moves every node that shares a blob, trashed ones included, and is safe to run again', function () {
    $a = storedFile($this->user, 'a.txt', 'shared');
    $twin = $a->replicate(['updated_at'])->forceFill(['name' => 'twin.txt', 'owner_id' => $this->user->id]);
    $twin->save();
    $twin->forceFill(['trashed_at' => now()])->save();

    $this->artisan('files:encrypt', ['disk' => $this->disk->name, '--yes' => true])->assertSuccessful();

    expect($a->fresh()->path)->toBe($twin->fresh()->path)
        ->and($twin->fresh()->encryption_key_id)->toBe(1)
        ->and(blobCount())->toBe(1);

    $path = $a->fresh()->path;
    $this->artisan('files:encrypt', ['disk' => $this->disk->name, '--yes' => true])->assertSuccessful();

    expect($a->fresh()->path)->toBe($path);
});

it('only touches the disk it was given', function () {
    $other = StorageDisk::create(['name' => 'other', 'driver' => 'local', 'config' => ['root' => $this->base.'/other'], 'is_default' => false]);
    $here = storedFile($this->user, 'here.txt', 'here');
    $there = storedFile($this->user, 'there.txt', 'there');
    $there->forceFill(['disk_id' => $other->id])->save();
    app(StorageManager::class)->filesystem($other)->put($there->path, 'there');

    $this->artisan('files:encrypt', ['disk' => $this->disk->name, '--yes' => true])->assertSuccessful();

    expect($here->fresh()->encryption_key_id)->toBe(1)->and($there->fresh()->encryption_key_id)->toBeNull()
        ->and($other->fresh()->encrypt)->toBeFalse();
});

it('stops at the limit and carries on where it left off', function () {
    foreach (['a', 'b', 'c'] as $name) {
        storedFile($this->user, "{$name}.txt", "content {$name}");
    }

    $this->artisan('files:encrypt', ['disk' => $this->disk->name, '--yes' => true, '--limit' => 2])->assertSuccessful();
    expect(Node::whereNotNull('encryption_key_id')->count())->toBe(2);

    $this->artisan('files:encrypt', ['disk' => $this->disk->name, '--yes' => true])->assertSuccessful();
    expect(Node::whereNull('encryption_key_id')->count())->toBe(0);
});

it('needs the key, a known disk and a yes before it turns encryption on', function () {
    storedFile($this->user, 'a.txt', 'alpha');

    $this->artisan('files:encrypt', ['disk' => 'nope', '--yes' => true])->assertFailed();

    $this->artisan('files:encrypt', ['disk' => $this->disk->name])->expectsConfirmation('Turn encryption on for this disk and convert it?', 'no')->assertFailed();
    expect($this->disk->fresh()->encrypt)->toBeFalse()->and(Node::whereNotNull('encryption_key_id')->exists())->toBeFalse();

    config(['ferrite.file_key' => null]);
    $this->artisan('files:encrypt', ['disk' => $this->disk->name, '--yes' => true])->assertFailed();
    expect($this->disk->fresh()->encrypt)->toBeFalse();
});

it('leaves a file alone when its blob is missing, reports it and goes on with the others', function () {
    $broken = storedFile($this->user, 'broken.txt', 'gone soon');
    $fine = storedFile($this->user, 'fine.txt', 'still here');
    app(StorageManager::class)->filesystem($this->disk)->delete($broken->path);

    $this->artisan('files:encrypt', ['disk' => $this->disk->name, '--yes' => true])->assertFailed();

    expect($broken->fresh()->encryption_key_id)->toBeNull()->and($broken->fresh()->path)->toBe($broken->path)
        ->and($fine->fresh()->encryption_key_id)->toBe(1);
});

it('rekeys: new header under the new key, same chunks, readable without the old key afterwards', function () {
    $oldKey = base64_encode(str_repeat('k', 32));
    $content = random_bytes(BlobCipher::CHUNK + 4000);
    $this->disk->forceFill(['encrypt' => true])->save();
    $file = encryptedFile($this->user, 'a.bin', $content, $this->disk, mime: 'application/octet-stream');
    $before = bytesOf($file);

    // Rotate: a new current key, the old one kept for reading until the rekey has run.
    config(['ferrite.file_key' => base64_encode(str_repeat('n', 32)), 'ferrite.file_key_id' => 2, 'ferrite.file_keys_old' => "1:{$oldKey}"]);
    expect(stream_get_contents(app(StorageManager::class)->openNodeStream($file)))->toBe($content);

    $this->artisan('files:rekey')->assertSuccessful();

    $after = bytesOf($file->fresh());
    expect($file->fresh()->encryption_key_id)->toBe(2)
        ->and($file->fresh()->path)->not->toBe($file->path)
        ->and(substr($after, BlobCipher::HEADER))->toBe(substr($before, BlobCipher::HEADER))
        ->and(substr($after, 0, BlobCipher::HEADER))->not->toBe(substr($before, 0, BlobCipher::HEADER))
        ->and(app(StorageManager::class)->filesystem($this->disk)->exists($file->path))->toBeFalse();

    config(['ferrite.file_keys_old' => null]);
    expect(stream_get_contents(app(StorageManager::class)->openNodeStream($file->fresh())))->toBe($content);

    $path = $file->fresh()->path;
    $this->artisan('files:rekey')->assertSuccessful();
    expect($file->fresh()->path)->toBe($path);
});

it('rekey needs the old key to read the old header', function () {
    $this->disk->forceFill(['encrypt' => true])->save();
    $file = encryptedFile($this->user, 'a.txt', 'secret', $this->disk);
    config(['ferrite.file_key' => base64_encode(str_repeat('n', 32)), 'ferrite.file_key_id' => 2, 'ferrite.file_keys_old' => null]);

    $this->artisan('files:rekey')->assertFailed();

    expect($file->fresh()->encryption_key_id)->toBe(1)->and($file->fresh()->path)->toBe($file->path)
        ->and(blobCount())->toBe(1);
});
