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

it('lists one folder level with parent=root, folders included', function () {
    $items = collect(apiFiles($this->plain, '?parent=root')->assertOk()->json('data'))->keyBy('name');

    expect($items->keys()->sort()->values()->all())->toBe(['Album', 'b.flac'])
        ->and($items['Album'])->toMatchArray(['id' => $this->album->id, 'type' => 'folder', 'path' => 'Album', 'size' => null, 'sha256' => null, 'mime' => null])
        ->and($items['b.flac'])->toMatchArray(['id' => $this->b->id, 'type' => 'file', 'path' => 'b.flac', 'size' => 6, 'sha256' => hash('sha256', 'BBBBBB'), 'mime' => 'audio/flac']);
});

it('lists a subfolder by id with paths relative to the token folder', function () {
    $items = apiFiles($this->plain, "?parent={$this->album->id}")->assertOk()->json('data');

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['id' => $this->a->id, 'name' => 'a.flac', 'type' => 'file', 'path' => 'Album/a.flac']);
});

it('lists the top of the drive for a whole-drive token with parent=root', function () {
    $names = collect(apiFiles(apiToken($this->user, null), '?parent=root')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['Music', 'secret.txt']);
});

it('leaves out trashed items in a folder listing', function () {
    $this->b->forceFill(['trashed_at' => now()])->save();

    expect(collect(apiFiles($this->plain, '?parent=root')->json('data'))->pluck('name')->all())->toBe(['Album']);
});

it('pages a folder listing with the cursor', function () {
    $first = apiFiles($this->plain, '?parent=root')->json('data.0.id');
    $rest = apiFiles($this->plain, "?parent=root&cursor={$first}")->assertOk()->json('data');

    expect($rest)->toHaveCount(1)->and($rest[0]['id'])->toBeGreaterThan($first);
});

it('answers 404 for a parent outside the folder, a file, trashed or another account, and 422 for a bad value', function () {
    $other = User::factory()->create();
    $theirs = Node::factory()->for($other, 'owner')->create(['name' => 'Theirs']);
    $trashed = Node::factory()->for($this->user, 'owner')->create(['name' => 'Gone', 'parent_id' => $this->music->id, 'trashed_at' => now()]);

    foreach ([$this->outside->id, $this->a->id, $theirs->id, $trashed->id, 999999] as $id) {
        apiFiles($this->plain, "?parent={$id}")->assertNotFound();
    }

    $outsideFolder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Elsewhere']);
    apiFiles($this->plain, "?parent={$outsideFolder->id}")->assertNotFound();
    apiFiles($this->plain, '?parent=abc')->assertUnprocessable();
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

it('renames a file with a write token, keeping its folder, and logs the token', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);

    apiMoveRaw($this->a->id, ['name' => 'renamed.flac'], $write)->assertOk()->assertJson(['id' => $this->a->id, 'name' => 'renamed.flac', 'path' => 'Album/renamed.flac']);
    expect($this->a->fresh()->name)->toBe('renamed.flac');

    $activity = Activity::where('node_id', $this->a->id)->latest('id')->first();
    expect($activity->action->value)->toBe('renamed')->and($activity->meta['token'])->toBe('test')->and($activity->meta['from'])->toBe('a.flac');
});

it('renames a folder inside the token folder but not the token folder itself', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);

    apiMoveRaw($this->album->id, ['name' => 'Record'], $write)->assertOk()->assertJson(['path' => 'Record']);
    expect(apiFiles($write)->json('data.*.path'))->toContain('Record/a.flac');

    apiMoveRaw($this->music->id, ['name' => 'Tunes'], $write)->assertNotFound();
    expect($this->music->fresh()->name)->toBe('Music');
});

it('does not replace on rename: a taken name, even in another case, is a 409', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);
    storedFile($this->user, 'Other.flac', 'x', $this->album, 'audio/flac');

    apiMoveRaw($this->a->id, ['name' => 'other.FLAC'], $write)->assertStatus(409)->assertJson(['error' => 'exists']);
    expect($this->a->fresh()->name)->toBe('a.flac');

    apiMoveRaw($this->a->id, ['name' => 'A.flac'], $write)->assertOk();
});

it('rejects bad names and asks for exactly one of folder and name', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);

    foreach (['', '  ', '.', '..', 'a/b', "a\x00b", str_repeat('x', 256)] as $name) {
        apiMoveRaw($this->a->id, ['name' => $name], $write)->assertUnprocessable();
    }

    apiMoveRaw($this->a->id, ['name' => 'x.flac', 'folder' => ''], $write)->assertUnprocessable();
    expect($this->a->fresh()->name)->toBe('a.flac');
});

it('does not let a read token rename, and 404s outside the folder, trashed or other accounts', function () {
    apiMoveRaw($this->a->id, ['name' => 'x.flac'], $this->plain)->assertForbidden();

    $write = apiToken($this->user, $this->music, ['read', 'write']);
    $this->b->forceFill(['trashed_at' => now()])->save();
    $theirs = storedFile(User::factory()->create(), 'theirs.txt', 'x');

    foreach ([$this->outside->id, $this->b->id, $theirs->id] as $id) {
        apiMoveRaw($id, ['name' => 'x.flac'], $write)->assertNotFound();
    }

    expect($this->outside->fresh()->name)->toBe('secret.txt');
});

it('still cannot move a folder', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);

    apiMoveRaw($this->album->id, ['folder' => ''], $write)->assertNotFound();
});

function apiMkdir(array $body, ?string $plain)
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/files', $body, $plain ? ['Authorization' => "Bearer {$plain}"] : []);
}

it('creates a folder in the token folder with a write token, and logs the token', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);

    $response = apiMkdir(['parent' => 'root', 'name' => 'New'], $write)->assertCreated();
    $folder = Node::find($response->json('id'));

    expect($response->json())->toMatchArray(['name' => 'New', 'type' => 'folder', 'path' => 'New'])
        ->and($folder->parent_id)->toBe($this->music->id)
        ->and($folder->owner_id)->toBe($this->user->id);

    $deeper = apiMkdir(['parent' => (string) $this->album->id, 'name' => 'Deep'], $write)->assertCreated();
    expect($deeper->json('path'))->toBe('Album/Deep');

    $activity = Activity::where('node_id', $folder->id)->latest('id')->first();
    expect($activity->action->value)->toBe('created_folder')->and($activity->meta['token'])->toBe('test');
});

it('creates a folder at the top of the drive for a whole-drive token', function () {
    $made = apiMkdir(['parent' => 'root', 'name' => 'Top'], apiToken($this->user, null, ['read', 'write']))->assertCreated();

    expect(Node::find($made->json('id'))->parent_id)->toBeNull()->and($made->json('path'))->toBe('Top');
});

it('does not create over a taken name: 409 with the existing id, files and other cases included', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);

    apiMkdir(['parent' => 'root', 'name' => 'album'], $write)->assertStatus(409)->assertJson(['error' => 'exists', 'id' => $this->album->id, 'type' => 'folder']);
    apiMkdir(['parent' => 'root', 'name' => 'B.FLAC'], $write)->assertStatus(409)->assertJson(['id' => $this->b->id, 'type' => 'file']);

    expect(Node::where('parent_id', $this->music->id)->count())->toBe(2);
});

it('does not let a read token create folders', function () {
    apiMkdir(['parent' => 'root', 'name' => 'New'], $this->plain)->assertForbidden();

    expect(Node::where('name', 'New')->exists())->toBeFalse();
});

it('answers 404 for a parent outside the folder, a file, trashed or another account, and 422 for bad input', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);
    $theirs = Node::factory()->for(User::factory()->create(), 'owner')->create(['name' => 'Theirs']);
    $trashed = Node::factory()->for($this->user, 'owner')->create(['name' => 'Gone', 'parent_id' => $this->music->id, 'trashed_at' => now()]);

    foreach ([$this->outside->id, $this->b->id, $theirs->id, $trashed->id, 999999] as $id) {
        apiMkdir(['parent' => (string) $id, 'name' => 'New'], $write)->assertNotFound();
    }

    foreach ([[], ['parent' => 'root'], ['name' => 'x'], ['parent' => 'abc', 'name' => 'x'], ['parent' => 'root', 'name' => ''], ['parent' => 'root', 'name' => 'a/b'], ['parent' => 'root', 'name' => '..'], ['parent' => 'root', 'name' => str_repeat('x', 256)]] as $body) {
        apiMkdir($body, $write)->assertUnprocessable();
    }

    expect(Node::where('name', 'New')->exists())->toBeFalse();
});

it('needs a token to create folders', function () {
    apiMkdir(['parent' => 'root', 'name' => 'New'], null)->assertUnauthorized();
});

function apiMoveRaw(int $id, array $body, string $plain)
{
    app('auth')->forgetGuards();

    return test()->patchJson("/api/v1/files/{$id}", $body, ['Authorization' => "Bearer {$plain}"]);
}
