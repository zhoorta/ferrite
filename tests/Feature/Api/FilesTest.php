<?php

use App\Models\Activity;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $base = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
    config(['ferrite.tmp_path' => "{$base}/tmp", 'ferrite.local_root' => "{$base}/blobs"]);
    $this->base = $base;

    $this->user = User::factory()->create();
    $this->music = Node::factory()->for($this->user, 'owner')->create(['name' => 'Music']);
    $this->album = Node::factory()->for($this->user, 'owner')->create(['name' => 'Album', 'parent_id' => $this->music->id]);
    $this->a = storedFile($this->user, 'a.flac', 'AAAA', $this->album, 'audio/flac');
    $this->b = storedFile($this->user, 'b.flac', 'BBBBBB', $this->music, 'audio/flac');
    $this->outside = storedFile($this->user, 'secret.txt', 'nope');
    $this->plain = apiToken($this->user, $this->music);
});

afterEach(fn () => File::deleteDirectory($this->base));

function apiFiles(string $plain, string $query = '')
{
    return apiGet('/api/v1/files'.$query, $plain);
}

it('lists files below the folder with paths relative to it', function () {
    $response = apiFiles($this->plain)->assertOk();

    expect($response->json('next_cursor'))->toBeNull()
        ->and(collect($response->json('data'))->pluck('path')->sort()->values()->all())->toBe(['Album/a.flac', 'b.flac'])
        ->and($response->json('data.0'))->toMatchArray([
            'id' => $this->a->id,
            'path' => 'Album/a.flac',
            'size' => 4,
            'sha256' => hash('sha256', 'AAAA'),
            'mime' => 'audio/flac',
        ]);
});

it('lists everything, with root-level files, for a whole-drive token', function () {
    $paths = collect(apiFiles(apiToken($this->user, null))->json('data'))->pluck('path')->sort()->values()->all();

    expect($paths)->toBe(['Music/Album/a.flac', 'Music/b.flac', 'secret.txt']);
});

it('leaves out trashed files, trashed folders and other accounts', function () {
    $this->b->forceFill(['trashed_at' => now()])->save();
    $this->album->forceFill(['trashed_at' => now()])->save();
    storedFile(User::factory()->create(), 'theirs.flac', 'x', null);

    expect(apiFiles($this->plain)->json('data'))->toBe([])
        ->and(apiFiles(apiToken($this->user, null))->json('data.*.path'))->toBe(['secret.txt']);
});

it('pages by id with a cursor', function () {
    $this->travel(0);
    foreach (range(1, 1001) as $i) {
        Node::query()->insert([
            'owner_id' => $this->user->id, 'parent_id' => $this->music->id, 'type' => 'file', 'name' => "n{$i}.txt",
            'disk_id' => $this->a->disk_id, 'path' => "k{$i}", 'size' => 1, 'mime' => 'text/plain',
            'sha256' => hash('sha256', (string) $i), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $first = apiFiles($this->plain)->assertOk();
    expect($first->json('data'))->toHaveCount(1000)->and($first->json('next_cursor'))->not->toBeNull();

    $second = apiFiles($this->plain, '?cursor='.$first->json('next_cursor'))->assertOk();
    expect($second->json('data'))->toHaveCount(3)->and($second->json('next_cursor'))->toBeNull();
});

it('rejects a bad cursor', function () {
    apiFiles($this->plain, '?cursor=abc')->assertUnprocessable();
});

it('serves content with Range, ETag and headers', function () {
    $full = apiGet("/api/v1/files/{$this->a->id}/content", $this->plain)
        ->assertOk()
        ->assertHeader('ETag', '"'.hash('sha256', 'AAAA').'"')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($full->streamedContent())->toBe('AAAA');
    expect($full->headers->get('Content-Disposition'))->toStartWith('attachment');

    $this->withHeader('Range', 'bytes=1-2')
        ->getJson("/api/v1/files/{$this->a->id}/content", ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 1-2/4');

    $this->getJson("/api/v1/files/{$this->a->id}/content", ['Authorization' => "Bearer {$this->plain}", 'If-None-Match' => '"'.hash('sha256', 'AAAA').'"'])
        ->assertStatus(304);
});

it('answers 404 for content outside the folder, a folder, trashed or foreign nodes', function () {
    $foreign = storedFile(User::factory()->create(), 'f.txt', 'x');
    $this->b->forceFill(['trashed_at' => now()])->save();

    foreach ([$this->outside, $this->music, $this->album, $this->b, $foreign] as $node) {
        apiGet("/api/v1/files/{$node->id}/content", $this->plain)->assertNotFound();
    }

    apiGet('/api/v1/files/99999/content', $this->plain)->assertNotFound();
});

it('does not let a token from another account reach these files', function () {
    $other = apiToken(User::factory()->create(), null);

    apiGet("/api/v1/files/{$this->a->id}/content", $other)->assertNotFound();
    expect(apiFiles($other)->json('data'))->toBe([]);
});

it('needs a valid token for files and content', function () {
    apiGet('/api/v1/files')->assertUnauthorized();
    apiGet("/api/v1/files/{$this->a->id}/content")->assertUnauthorized();
});

it('moves a file to the trash with a trash token, and logs the token', function () {
    $plain = apiToken($this->user, $this->music, ['read', 'write', 'trash']);

    apiDelete("/api/v1/files/{$this->a->id}", $plain)->assertOk()->assertJson(['id' => $this->a->id, 'trashed' => true]);

    expect($this->a->fresh()->isTrashed())->toBeTrue()
        ->and(apiFiles($plain)->json('data.*.path'))->toBe(['b.flac']);
    $activity = Activity::where('node_id', $this->a->id)->latest('id')->first();
    expect($activity->action->value)->toBe('trashed')->and($activity->meta)->toBe(['token' => 'test']);
});

it('does not let read or write tokens trash anything', function () {
    apiDelete("/api/v1/files/{$this->a->id}", $this->plain)->assertForbidden();
    apiDelete("/api/v1/files/{$this->a->id}", apiToken($this->user, $this->music, ['read', 'write']))->assertForbidden();

    expect($this->a->fresh()->isTrashed())->toBeFalse();
});

it('answers 404 for files outside the folder, folders, trashed files and other accounts', function () {
    $plain = apiToken($this->user, $this->music, ['read', 'write', 'trash']);
    $theirs = storedFile(User::factory()->create(), 'theirs.flac', 'x', null);
    $this->b->forceFill(['trashed_at' => now()])->save();

    apiDelete("/api/v1/files/{$this->outside->id}", $plain)->assertNotFound();
    apiDelete("/api/v1/files/{$this->album->id}", $plain)->assertNotFound();
    apiDelete("/api/v1/files/{$this->b->id}", $plain)->assertNotFound();
    apiDelete("/api/v1/files/{$theirs->id}", $plain)->assertNotFound();

    expect($this->outside->fresh()->isTrashed())->toBeFalse()->and($this->album->fresh()->isTrashed())->toBeFalse();
});

it('needs a token', function () {
    apiDelete("/api/v1/files/{$this->a->id}")->assertUnauthorized();
});

function apiMove(int $id, string $folder, string $plain)
{
    app('auth')->forgetGuards();

    return test()->patchJson("/api/v1/files/{$id}", ['folder' => $folder], ['Authorization' => "Bearer {$plain}"]);
}

it('moves a file to another folder with a write token, keeping its name, and logs the token', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);

    apiMove($this->a->id, '', $write)->assertOk()->assertJson(['id' => $this->a->id, 'path' => 'a.flac']);
    expect($this->a->fresh()->parent_id)->toBe($this->music->id);

    apiMove($this->a->id, 'New/Deeper', $write)->assertOk()->assertJson(['path' => 'New/Deeper/a.flac']);
    expect(apiFiles($write)->json('data.*.path'))->toContain('New/Deeper/a.flac');

    $activity = Activity::where('node_id', $this->a->id)->latest('id')->first();
    expect($activity->action->value)->toBe('moved')->and($activity->meta['token'])->toBe('test');
});

it('does not replace: a name clash is a 409 and nothing moves', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);
    storedFile($this->user, 'a.flac', 'other', $this->music, 'audio/flac');

    apiMove($this->a->id, '', $write)->assertStatus(409)->assertJson(['error' => 'exists']);

    expect($this->a->fresh()->parent_id)->toBe($this->album->id);
});

it('does not let a read token move anything', function () {
    apiMove($this->a->id, '', $this->plain)->assertForbidden();

    expect($this->a->fresh()->parent_id)->toBe($this->album->id);
});

it('answers 404 for files outside the folder, folders, trashed files and other accounts, and cannot leave the folder', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);
    $theirs = storedFile(User::factory()->create(), 'theirs.flac', 'x', null);
    $this->b->forceFill(['trashed_at' => now()])->save();

    foreach ([$this->outside, $this->album, $this->b, $theirs] as $node) {
        apiMove($node->id, '', $write)->assertNotFound();
    }

    foreach (['..', '../Elsewhere', 'a/../b', '.', "ctl\x01"] as $bad) {
        apiMove($this->a->id, $bad, $write)->assertUnprocessable();
    }

    expect($this->a->fresh()->parent_id)->toBe($this->album->id);
});

it('needs a token and a folder field', function () {
    app('auth')->forgetGuards();
    $this->patchJson("/api/v1/files/{$this->a->id}", ['folder' => ''])->assertUnauthorized();

    apiMoveRaw($this->a->id, [], apiToken($this->user, $this->music, ['read', 'write']))->assertUnprocessable();
});

function apiMoveRaw(int $id, array $body, string $plain)
{
    app('auth')->forgetGuards();

    return test()->patchJson("/api/v1/files/{$id}", $body, ['Authorization' => "Bearer {$plain}"]);
}
