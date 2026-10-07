<?php

use App\Models\Node;
use App\Models\Upload;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $base = storage_path('framework/testing/shed-'.bin2hex(random_bytes(4)));
    config(['shed.tmp_path' => "{$base}/tmp", 'shed.local_root' => "{$base}/blobs", 'shed.chunk_size' => 4]);
    $this->base = $base;

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

afterEach(fn () => File::deleteDirectory($this->base));

function startUpload(array $data = []): TestResponse
{
    return test()->postJson(route('uploads.store'), $data + ['path' => 'a.txt', 'size' => 10]);
}

function sendChunk(string $id, int $offset, string $content): TestResponse
{
    return test()->call('PATCH', route('uploads.update', $id), [], [], [], [
        'HTTP_UPLOAD_OFFSET' => $offset,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $content);
}

function uploadAll(string $path, string $content, ?int $parentId = null): TestResponse
{
    $id = startUpload(['path' => $path, 'size' => strlen($content), 'parent_id' => $parentId])->json('id');
    $response = null;

    foreach (str_split($content, 4) ?: [''] as $i => $chunk) {
        $response = sendChunk($id, $i * 4, $chunk);
    }

    return $response ?? sendChunk($id, 0, '');
}

it('requires login', function () {
    auth()->logout();

    $this->postJson(route('uploads.store'), ['path' => 'a', 'size' => 1])->assertUnauthorized();
});

it('starts an upload', function () {
    $response = startUpload()->assertCreated()->assertJson(['size' => 10, 'chunk_size' => 4]);

    expect($response->json('offset'))->toBe(0);

    expect(Upload::count())->toBe(1);
});

it('stores a file received in chunks', function () {
    $response = uploadAll('hello.txt', 'hello world');

    $response->assertOk()->assertJsonPath('node.name', 'hello.txt');

    $node = Node::firstWhere('name', 'hello.txt');
    $disk = app(StorageManager::class);

    expect($node->owner_id)->toBe($this->user->id)
        ->and($node->size)->toBe(11)
        ->and($node->mime)->toBe('text/plain')
        ->and($node->sha256)->toBe(hash('sha256', 'hello world'))
        ->and($node->path)->toMatch('#^[0-9a-f]{2}/[0-9a-f]{38}$#')
        ->and($disk->filesystem($node->disk)->get($node->path))->toBe('hello world')
        ->and($this->user->fresh()->used_bytes)->toBe(11)
        ->and(Upload::count())->toBe(0)
        ->and(File::files(config('shed.tmp_path')))->toBeEmpty();
});

it('stores an empty file', function () {
    uploadAll('empty.txt', '')->assertOk();

    expect(Node::firstWhere('name', 'empty.txt')->size)->toBe(0);
});

it('answers 409 with the real offset when a chunk is out of step', function () {
    $id = startUpload()->json('id');
    sendChunk($id, 0, 'abcd')->assertOk()->assertJson(['offset' => 4]);

    sendChunk($id, 0, 'abcd')->assertStatus(409)->assertJson(['offset' => 4]);
    sendChunk($id, 8, 'abcd')->assertStatus(409)->assertJson(['offset' => 4]);
});

it('rejects a chunk that runs past the declared size', function () {
    $id = startUpload(['size' => 3])->json('id');

    sendChunk($id, 0, 'abcdef')->assertStatus(413);

    expect(Upload::find($id)->offset)->toBe(0)
        ->and(filesize(Upload::find($id)->tmpPath()))->toBe(0);
});

it('resumes an unfinished upload of the same file', function () {
    $first = startUpload(['fingerprint' => '123']);
    sendChunk($first->json('id'), 0, 'abcd');

    startUpload(['fingerprint' => '123'])
        ->assertOk()
        ->assertJson(['id' => $first->json('id'), 'offset' => 4]);

    startUpload(['fingerprint' => '456'])->assertCreated();
});

it('creates the folders of a relative path and reuses existing ones', function () {
    $photos = Node::factory()->for($this->user, 'owner')->create(['name' => 'Photos']);

    uploadAll('photos/2026/a.txt', 'one')->assertOk();
    uploadAll('Photos/2026/b.txt', 'two')->assertOk();

    $year = Node::firstWhere('name', '2026');

    expect($year->parent_id)->toBe($photos->id)
        ->and(Node::where('name', '2026')->count())->toBe(1)
        ->and(Node::where('parent_id', $year->id)->pluck('name')->sort()->values()->all())->toBe(['a.txt', 'b.txt'])
        ->and(Node::where('name', 'Photos')->count())->toBe(1);
});

it('rejects a relative path through a file', function () {
    Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'notes']);

    startUpload(['path' => 'notes/a.txt'])->assertUnprocessable()->assertJsonValidationErrors('path');
});

it('keeps both files when the name is taken', function () {
    uploadAll('a.txt', 'one');
    uploadAll('a.txt', 'two');

    expect(Node::where('type', 'file')->pluck('name')->sort()->values()->all())->toBe(['a (2).txt', 'a.txt']);
});

it('rejects bad names', function (string $path) {
    startUpload(['path' => $path])->assertUnprocessable();
})->with(['', '..', 'a//b.txt', '/a.txt', 'a/../b.txt']);

it('checks the quota when starting', function () {
    $this->user->forceFill(['quota_bytes' => 5])->save();

    startUpload(['size' => 6])->assertUnprocessable()->assertJsonValidationErrors('size');
    startUpload(['size' => 5])->assertCreated();
});

it('checks the quota again when completing', function () {
    $id = startUpload(['size' => 8])->json('id');
    sendChunk($id, 0, 'abcd');
    $this->user->forceFill(['quota_bytes' => 5])->save();

    sendChunk($id, 4, 'efgh')->assertUnprocessable()->assertJsonValidationErrors('size');

    expect(Node::count())->toBe(0)
        ->and(Upload::count())->toBe(0)
        ->and($this->user->fresh()->used_bytes)->toBe(0)
        ->and(File::allFiles(config('shed.local_root')))->toBeEmpty();
});

it('counts uploads towards the quota', function () {
    $this->user->forceFill(['quota_bytes' => 10])->save();

    uploadAll('a.txt', 'abcdefgh');

    startUpload(['path' => 'b.txt', 'size' => 3])->assertUnprocessable();
    expect($this->user->fresh()->remainingBytes())->toBe(2);
});

it('does not let users touch other people\'s uploads', function () {
    $id = startUpload()->json('id');
    $this->actingAs(User::factory()->create());

    $this->getJson(route('uploads.show', $id))->assertNotFound();
    sendChunk($id, 0, 'abcd')->assertNotFound();
    $this->deleteJson(route('uploads.destroy', $id))->assertNotFound();
});

it('only uploads into folders the user can edit', function () {
    $theirs = Node::factory()->create();
    $trashed = Node::factory()->for($this->user, 'owner')->trashed()->create();
    $file = Node::factory()->file()->for($this->user, 'owner')->create();

    startUpload(['parent_id' => $theirs->id])->assertForbidden();
    startUpload(['parent_id' => $trashed->id])->assertUnprocessable();
    startUpload(['parent_id' => $file->id])->assertUnprocessable();
});

it('charges the folder owner when uploading into a shared folder', function () {
    $owner = User::factory()->create();
    $folder = Node::factory()->for($owner, 'owner')->create();
    $folder->sharedWith()->attach($this->user, ['permission' => 'edit']);

    uploadAll('a.txt', 'abcd', $folder->id)->assertOk();

    expect(Node::firstWhere('name', 'a.txt')->owner_id)->toBe($owner->id)
        ->and($owner->fresh()->used_bytes)->toBe(4)
        ->and($this->user->fresh()->used_bytes)->toBe(0);
});

it('cancels an upload and removes its data', function () {
    $id = startUpload()->json('id');
    sendChunk($id, 0, 'abcd');

    $this->deleteJson(route('uploads.destroy', $id))->assertNoContent();

    expect(Upload::count())->toBe(0)
        ->and(File::files(config('shed.tmp_path')))->toBeEmpty();
});

it('prunes stale unfinished uploads', function () {
    $stale = startUpload()->json('id');
    sendChunk($stale, 0, 'abcd');
    Upload::whereKey($stale)->update(['updated_at' => now()->subDays(2)]);
    $fresh = startUpload(['path' => 'b.txt'])->json('id');

    $this->artisan('uploads:prune')->assertSuccessful();

    expect(Upload::pluck('id')->all())->toBe([$fresh])
        ->and(file_exists(config('shed.tmp_path')."/{$stale}"))->toBeFalse();
});

it('shows the upload controls only where the user can write', function () {
    $this->get(route('files'))->assertSee('Upload folder');

    $shared = Node::factory()->create();
    $shared->sharedWith()->attach($this->user, ['permission' => 'view']);

    $this->get(route('files', $shared))->assertDontSee('Upload folder');
});

it('gives the page uploader the application base URL, so its requests hit /uploads and not /uploads/uploads', function () {
    $this->get(route('files'))->assertSee("baseUrl: '".str_replace('/', '\\/', url('/'))."'", false);
});

it('stores identical content of one owner once but charges each file', function () {
    uploadAll('a.txt', 'same content')->assertOk();
    uploadAll('b.txt', 'same content')->assertOk();

    [$a, $b] = [Node::firstWhere('name', 'a.txt'), Node::firstWhere('name', 'b.txt')];

    expect($b->path)->toBe($a->path)
        ->and($b->id)->not->toBe($a->id)
        ->and(File::allFiles(config('shed.local_root')))->toHaveCount(1)
        ->and($this->user->fresh()->used_bytes)->toBe(24);
});

it('does not share blobs between owners', function () {
    uploadAll('a.txt', 'same content')->assertOk();

    $this->actingAs(User::factory()->create());
    uploadAll('b.txt', 'same content')->assertOk();

    expect(Node::firstWhere('name', 'b.txt')->path)->not->toBe(Node::firstWhere('name', 'a.txt')->path)
        ->and(File::allFiles(config('shed.local_root')))->toHaveCount(2);
});

it('keeps separate blobs for different content of the same size', function () {
    uploadAll('a.txt', 'aaaa')->assertOk();
    uploadAll('b.txt', 'bbbb')->assertOk();

    expect(File::allFiles(config('shed.local_root')))->toHaveCount(2);
});
