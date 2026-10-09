<?php

use App\Models\Activity;
use App\Models\Node;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $base = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
    config(['ferrite.tmp_path' => "{$base}/tmp", 'ferrite.local_root' => "{$base}/blobs", 'ferrite.chunk_size' => 4]);
    $this->base = $base;

    $this->user = User::factory()->create(['quota_bytes' => 1000]);
    $this->music = Node::factory()->for($this->user, 'owner')->create(['name' => 'Music']);
    $this->plain = apiToken($this->user, $this->music, ['read', 'write']);
});

afterEach(fn () => File::deleteDirectory($this->base));

function apiUploadStart(string $plain, array $data = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/uploads', $data + ['path' => 'a.txt', 'size' => 10], ['Authorization' => "Bearer {$plain}"]);
}

function apiUploadChunk(string $plain, string $id, int $offset, string $content): TestResponse
{
    app('auth')->forgetGuards();

    return test()->call('PATCH', "/api/v1/uploads/{$id}", [], [], [], [
        'HTTP_UPLOAD_OFFSET' => $offset,
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => "Bearer {$plain}",
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $content);
}

/** Send a whole file in chunks of the configured size and return the last response. */
function apiUploadAll(string $plain, string $path, string $content): TestResponse
{
    $start = apiUploadStart($plain, ['path' => $path, 'size' => strlen($content)])->assertSuccessful();
    $response = null;

    foreach (str_split($content, 4) as $i => $chunk) {
        $response = apiUploadChunk($plain, $start->json('id'), $i * 4, $chunk);
    }

    return $response ?? $start;
}

it('uploads a file in chunks into the token\'s folder, creating folders on the way', function () {
    $start = apiUploadStart($this->plain, ['path' => 'Album/one/a.txt', 'size' => 10])
        ->assertCreated()
        ->assertJson(['offset' => 0, 'size' => 10, 'chunk_size' => 4, 'status' => 'receiving']);

    apiUploadChunk($this->plain, $start->json('id'), 0, '0123')->assertOk()->assertJson(['offset' => 4]);
    apiUploadChunk($this->plain, $start->json('id'), 4, '4567')->assertOk()->assertJson(['offset' => 8]);
    $done = apiUploadChunk($this->plain, $start->json('id'), 8, '89')->assertOk()->assertJson(['status' => 'done']);

    expect($done->json('node.sha256'))->toBe(hash('sha256', '0123456789'));

    $file = Node::findOrFail($done->json('node.id'));
    expect($file->name)->toBe('a.txt')->and($file->parent->name)->toBe('one')->and($file->parent->parent->name)->toBe('Album')
        ->and($file->parent->parent->parent_id)->toBe($this->music->id)
        ->and($this->user->fresh()->used_bytes)->toBe(10);

    apiGet("/api/v1/files/{$file->id}/content", $this->plain)->assertOk();
    expect(apiGet('/api/v1/files', $this->plain)->json('data.0.path'))->toBe('Album/one/a.txt');
});

it('records the token in the activity log', function () {
    apiUploadAll($this->plain, 'a.txt', '0123456789')->assertOk();

    $activity = Activity::latest('id')->first();

    expect($activity->sentence($this->user))->toContain('through the token');
});

it('answers 409 exists, with the hash, when the path is taken, and creates nothing', function () {
    $existing = storedFile($this->user, 'a.txt', 'hello', $this->music);

    apiUploadStart($this->plain, ['path' => 'a.txt'])
        ->assertStatus(409)
        ->assertJson(['error' => 'exists', 'id' => $existing->id, 'sha256' => hash('sha256', 'hello')]);

    apiUploadStart($this->plain, ['path' => 'A.TXT'])->assertStatus(409); // names compare case-insensitively
    expect(Upload::count())->toBe(0);
});

it('answers 409 when a folder is in the way, with no hash', function () {
    $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'sub', 'parent_id' => $this->music->id]);

    apiUploadStart($this->plain, ['path' => 'sub'])->assertStatus(409)->assertJson(['error' => 'exists', 'id' => $folder->id, 'sha256' => null]);
});

it('refuses a file whose name appeared while it was being sent, and keeps neither copy', function () {
    $start = apiUploadStart($this->plain)->assertCreated();
    apiUploadChunk($this->plain, $start->json('id'), 0, '0123');
    apiUploadChunk($this->plain, $start->json('id'), 4, '4567');

    $other = storedFile($this->user, 'a.txt', 'someone else', $this->music);
    $last = apiUploadChunk($this->plain, $start->json('id'), 8, '89');

    $last->assertStatus(422);
    expect(Node::where('name', 'like', 'a%')->where('type', 'file')->pluck('id')->all())->toBe([$other->id])
        ->and($this->user->fresh()->used_bytes)->toBe(0);
    apiGet('/api/v1/files', $this->plain)->assertJsonCount(1, 'data');
});

it('resumes an unfinished upload of the same file for the same token only', function () {
    $first = apiUploadStart($this->plain, ['path' => 'a.txt', 'fingerprint' => 'x'])->assertCreated();
    apiUploadChunk($this->plain, $first->json('id'), 0, '0123');

    apiUploadStart($this->plain, ['path' => 'a.txt', 'fingerprint' => 'x'])
        ->assertOk()->assertJson(['id' => $first->json('id'), 'offset' => 4]);

    $otherToken = apiToken($this->user, $this->music, ['read', 'write']);
    apiUploadStart($otherToken, ['path' => 'a.txt', 'fingerprint' => 'x'])->assertCreated();
});

it('answers 409 with the real offset for a wrong offset', function () {
    $start = apiUploadStart($this->plain)->assertCreated();

    apiUploadChunk($this->plain, $start->json('id'), 4, '0123')->assertStatus(409)->assertJson(['offset' => 0]);
});

it('keeps an upload to the token that started it', function () {
    $start = apiUploadStart($this->plain)->assertCreated();
    $id = $start->json('id');
    $other = apiToken($this->user, $this->music, ['read', 'write']);
    $stranger = apiToken(User::factory()->create(), null, ['read', 'write']);

    foreach ([$other, $stranger] as $plain) {
        apiUploadChunk($plain, $id, 0, '0123')->assertNotFound();
        app('auth')->forgetGuards();
        $this->getJson("/api/v1/uploads/{$id}", ['Authorization' => "Bearer {$plain}"])->assertNotFound();
        app('auth')->forgetGuards();
        $this->deleteJson("/api/v1/uploads/{$id}", [], ['Authorization' => "Bearer {$plain}"])->assertNotFound();
    }

    app('auth')->forgetGuards();
    $this->getJson("/api/v1/uploads/{$id}", ['Authorization' => "Bearer {$this->plain}"])->assertOk()->assertJson(['status' => 'receiving']);
});

it('cancels an upload and removes what was received', function () {
    $start = apiUploadStart($this->plain)->assertCreated();
    apiUploadChunk($this->plain, $start->json('id'), 0, '0123');

    app('auth')->forgetGuards();
    $this->deleteJson("/api/v1/uploads/{$start->json('id')}", [], ['Authorization' => "Bearer {$this->plain}"])->assertNoContent();

    expect(Upload::count())->toBe(0)->and(glob(config('ferrite.tmp_path').'/*'))->toBe([]);
});

it('refuses a read token with 403 on every upload route', function () {
    $read = apiToken($this->user, $this->music, ['read']);

    apiUploadStart($read)->assertForbidden();
    expect(Upload::count())->toBe(0);

    $id = apiUploadStart($this->plain)->json('id');
    apiUploadChunk($read, $id, 0, '0123')->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/uploads/{$id}", ['Authorization' => "Bearer {$read}"])->assertForbidden();
    app('auth')->forgetGuards();
    $this->deleteJson("/api/v1/uploads/{$id}", [], ['Authorization' => "Bearer {$read}"])->assertForbidden();
});

it('checks the quota when the upload starts and stores nothing over it', function () {
    apiUploadStart($this->plain, ['size' => 5000])->assertUnprocessable()->assertJsonValidationErrors('size');
});

it('never reaches outside the folder through the path', function () {
    foreach (['../x.txt', 'a/../../x.txt', '/x.txt', 'a//x.txt', './x.txt'] as $path) {
        apiUploadStart($this->plain, ['path' => $path])->assertUnprocessable();
    }

    expect(Node::where('name', 'x.txt')->exists())->toBeFalse();
});

it('ends access when the token is revoked or the folder is trashed', function () {
    $start = apiUploadStart($this->plain)->assertCreated();

    $this->music->forceFill(['trashed_at' => now()])->save();
    apiUploadChunk($this->plain, $start->json('id'), 0, '0123')->assertNotFound();

    $this->music->forceFill(['trashed_at' => null])->save();
    $this->user->tokens()->delete();
    apiUploadChunk($this->plain, $start->json('id'), 0, '0123')->assertUnauthorized();
});

it('uploads into the root of a whole-drive token', function () {
    $whole = apiToken($this->user, null, ['read', 'write']);

    apiUploadAll($whole, 'loose.txt', '0123456789')->assertOk();

    expect(Node::where('name', 'loose.txt')->sole()->parent_id)->toBeNull();
});
