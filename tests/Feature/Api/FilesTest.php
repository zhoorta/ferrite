<?php

use App\Models\Activity;
use App\Models\Node;
use App\Models\User;
use App\Support\FolderSizes;
use App\Support\StorageManager;
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

it('answers 404 for files outside the folder, trashed files and other accounts', function () {
    $plain = apiToken($this->user, $this->music, ['read', 'write', 'trash']);
    $theirs = storedFile(User::factory()->create(), 'theirs.flac', 'x', null);
    $this->b->forceFill(['trashed_at' => now()])->save();

    apiDelete("/api/v1/files/{$this->outside->id}", $plain)->assertNotFound();
    apiDelete("/api/v1/files/{$this->b->id}", $plain)->assertNotFound();
    apiDelete("/api/v1/files/{$theirs->id}", $plain)->assertNotFound();

    expect($this->outside->fresh()->isTrashed())->toBeFalse();
});

it('trashes a folder with what is in it, takes it out of the sizes, but never the token\'s own folder', function () {
    $plain = apiToken($this->user, $this->music, ['read', 'write', 'trash']);
    storedFile($this->user, 'in-album.flac', 'CCC', $this->album);
    app(FolderSizes::class)->recount();

    apiDelete("/api/v1/files/{$this->album->id}", $plain)->assertOk()->assertJson(['trashed' => true]);

    expect($this->album->fresh()->isTrashed())->toBeTrue()
        ->and($this->music->fresh()->size)->toBe(6)
        ->and($this->album->fresh()->size)->toBe(7)
        ->and(app(FolderSizes::class)->recount(fix: false)['wrong'])->toBe(0);

    apiDelete("/api/v1/files/{$this->music->id}", $plain)->assertForbidden();
    expect($this->music->fresh()->isTrashed())->toBeFalse();
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

it('answers 404 for files outside the folder, trashed files and other accounts, and cannot leave the folder', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);
    $theirs = storedFile(User::factory()->create(), 'theirs.flac', 'x', null);
    $this->b->forceFill(['trashed_at' => now()])->save();

    foreach ([$this->outside, $this->b, $theirs] as $node) {
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

it('moves a folder with what is in it, keeping the sizes right, but not into itself or the token folder', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);
    $other = Node::factory()->for($this->user, 'owner')->create(['name' => 'Other', 'parent_id' => $this->music->id]);
    app(FolderSizes::class)->recount();

    apiMoveRaw($this->album->id, ['folder' => 'Other'], $write)->assertOk()->assertJson(['path' => 'Other/Album']);

    expect($this->album->fresh()->parent_id)->toBe($other->id)
        ->and($other->fresh()->size)->toBe(4)
        ->and(app(FolderSizes::class)->recount(fix: false)['wrong'])->toBe(0);

    apiMoveRaw($this->album->id, ['folder' => 'Other/Album'], $write)->assertUnprocessable();
    apiMoveRaw($this->music->id, ['folder' => ''], $write)->assertNotFound();
    apiMoveRaw($this->outside->id, ['folder' => ''], $write)->assertNotFound();
});

it('refuses to move a folder where its name is taken', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);
    $other = Node::factory()->for($this->user, 'owner')->create(['name' => 'Other', 'parent_id' => $this->music->id]);
    Node::factory()->for($this->user, 'owner')->create(['name' => 'Album', 'parent_id' => $other->id]);

    apiMoveRaw($this->album->id, ['folder' => 'Other'], $write)->assertStatus(409);
});

it('lists only the folders of a folder with type=folder', function () {
    $rows = apiGet('/api/v1/files?parent=root&type=folder', $this->plain)->assertOk()->json('data');

    expect(collect($rows)->pluck('name')->all())->toBe(['Album']);
    apiGet('/api/v1/files?parent=root&type=nope', $this->plain)->assertUnprocessable();
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

function apiThumb(int $id, ?string $plain, array $headers = [])
{
    app('auth')->forgetGuards();

    return test()->get("/api/v1/files/{$id}/thumbnail", $headers + ($plain ? ['Authorization' => "Bearer {$plain}"] : []));
}

function apiPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('serves a small JPEG thumbnail with caching headers, and 304 for an unchanged one', function () {
    $image = storedFile($this->user, 'wide.png', apiPng(1000, 400), $this->music, 'image/png');

    $response = apiThumb($image->id, $this->plain)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $size = getimagesizefromstring($response->getContent());

    expect($size[0])->toBe(256)->and($size[1])->toBe(102)->and($size['mime'])->toBe('image/jpeg')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=86400')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    apiThumb($image->id, $this->plain, ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
});

it('answers 404 when there is no thumbnail: not an image, a folder, outside the folder, trashed, other accounts', function () {
    $audio = $this->b;
    $outside = storedFile($this->user, 'out.png', apiPng(50, 50), null, 'image/png');
    $trashed = storedFile($this->user, 'gone.png', apiPng(50, 50), $this->music, 'image/png');
    $trashed->forceFill(['trashed_at' => now()])->save();
    $theirs = storedFile(User::factory()->create(), 'theirs.png', apiPng(50, 50), null, 'image/png');
    $broken = storedFile($this->user, 'broken.png', 'not an image', $this->music, 'image/png');

    foreach ([$audio->id, $this->album->id, $outside->id, $trashed->id, $theirs->id, $broken->id, 999999] as $id) {
        apiThumb($id, $this->plain)->assertNotFound();
    }
});

it('needs a valid token for thumbnails', function () {
    $image = storedFile($this->user, 'a.png', apiPng(50, 50), $this->music, 'image/png');

    apiThumb($image->id, null)->assertUnauthorized();
});

it('makes a thumbnail of an encrypted image and keeps its cache encrypted', function () {
    $image = encryptedFile($this->user, 'secret.png', apiPng(600, 300), null, $this->music, 'image/png');

    $response = apiThumb($image->id, $this->plain)->assertOk();
    $cached = (string) app(StorageManager::class)->filesystem($image->disk)->get("thumbnails/{$image->path}.jpg");

    expect(getimagesizefromstring($response->getContent())[0])->toBe(256)
        ->and(str_starts_with($cached, 'FRT1'))->toBeTrue();
});

function apiPost(string $uri, string $plain)
{
    app('auth')->forgetGuards();

    return test()->postJson($uri, [], ['Authorization' => "Bearer {$plain}"]);
}

it('lists the trash of the token folder for a trash token only', function () {
    $full = apiToken($this->user, $this->music, ['read', 'write', 'trash']);
    $this->b->forceFill(['trashed_at' => now()])->save();
    $this->outside->forceFill(['trashed_at' => now()])->save();
    $gone = Node::factory()->for($this->user, 'owner')->create(['name' => 'Old', 'parent_id' => $this->album->id, 'trashed_at' => now()]);

    $names = collect(apiGet('/api/v1/files?trashed=1', $full)->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toContain('b.flac', 'Old')->not->toContain('secret.txt');
    apiGet('/api/v1/files?trashed=1', $this->plain)->assertForbidden();

    $whole = collect(apiGet('/api/v1/files?trashed=1', apiToken($this->user, null, ['read', 'trash']))->json('data'))->pluck('name')->all();
    expect($whole)->toContain('secret.txt', 'b.flac');
    expect($gone->fresh()->isTrashed())->toBeTrue();
});

it('restores an item, logs the token, and keeps the folder sizes right', function () {
    $full = apiToken($this->user, $this->music, ['read', 'write', 'trash']);
    app(FolderSizes::class)->recount();
    apiDelete("/api/v1/files/{$this->b->id}", $full)->assertOk();

    apiPost("/api/v1/files/{$this->b->id}/restore", $full)->assertOk()->assertJson(['restored' => true, 'path' => 'b.flac']);

    expect($this->b->fresh()->isTrashed())->toBeFalse()
        ->and($this->music->fresh()->size)->toBe(10)
        ->and(app(FolderSizes::class)->recount(fix: false)['wrong'])->toBe(0);
    $activity = Activity::where('node_id', $this->b->id)->latest('id')->first();
    expect($activity->action->value)->toBe('restored')->and($activity->meta)->toBe(['token' => 'test']);
});

it('needs the trash ability to restore, and never restores outside the token folder or into a trashed one', function () {
    $full = apiToken($this->user, $this->music, ['read', 'write', 'trash']);
    $this->b->forceFill(['trashed_at' => now()])->save();
    $this->outside->forceFill(['trashed_at' => now()])->save();

    apiPost("/api/v1/files/{$this->b->id}/restore", apiToken($this->user, $this->music, ['read', 'write']))->assertForbidden();
    apiPost("/api/v1/files/{$this->outside->id}/restore", $full)->assertNotFound();
    apiPost("/api/v1/files/{$this->a->id}/restore", $full)->assertNotFound();   // not trashed

    $this->album->forceFill(['trashed_at' => now()])->save();
    $inside = Node::factory()->for($this->user, 'owner')->create(['name' => 'Inside', 'parent_id' => $this->album->id, 'trashed_at' => now()]);
    apiPost("/api/v1/files/{$inside->id}/restore", $full)->assertStatus(409)->assertJson(['error' => 'parent_trashed']);

    expect($this->outside->fresh()->isTrashed())->toBeTrue();
});

function apiDeleteTrash(string $uri, string $plain)
{
    app('auth')->forgetGuards();

    return test()->deleteJson($uri, [], ['Authorization' => "Bearer {$plain}"]);
}

it('deletes a trashed item for good with a trash token, and logs it', function () {
    $full = apiToken($this->user, $this->music, ['read', 'write', 'trash']);
    apiDelete("/api/v1/files/{$this->b->id}", $full)->assertOk();

    apiDeleteTrash("/api/v1/trash/{$this->b->id}", $full)->assertOk()->assertJson(['id' => $this->b->id, 'purged' => true]);

    expect(Node::query()->whereKey($this->b->id)->exists())->toBeFalse()
        ->and(Activity::where('action', 'purged')->latest('id')->first()->meta)->toBe(['token' => 'test']);
});

it('refuses to delete for good without the trash ability, what is not trashed or what is outside the token folder', function () {
    $full = apiToken($this->user, $this->music, ['read', 'write', 'trash']);
    $this->outside->forceFill(['trashed_at' => now()])->save();

    apiDeleteTrash("/api/v1/trash/{$this->b->id}", apiToken($this->user, $this->music, ['read', 'write']))->assertForbidden();
    apiDeleteTrash("/api/v1/trash/{$this->b->id}", $full)->assertNotFound();           // not in the trash
    apiDeleteTrash("/api/v1/trash/{$this->outside->id}", $full)->assertNotFound();     // trashed, but outside

    expect(Node::query()->whereKey($this->b->id)->exists())->toBeTrue()->and(Node::query()->whereKey($this->outside->id)->exists())->toBeTrue();
});

it('empties only the trash of the token folder', function () {
    $full = apiToken($this->user, $this->music, ['read', 'write', 'trash']);
    $this->b->forceFill(['trashed_at' => now()])->save();
    $this->a->forceFill(['trashed_at' => now()])->save();
    $this->outside->forceFill(['trashed_at' => now()])->save();

    apiDeleteTrash('/api/v1/trash', apiToken($this->user, $this->music, ['read']))->assertForbidden();
    apiDeleteTrash('/api/v1/trash', $full)->assertOk()->assertJson(['purged' => 2]);

    expect(Node::query()->whereKey([$this->a->id, $this->b->id])->exists())->toBeFalse()
        ->and(Node::query()->whereKey($this->outside->id)->exists())->toBeTrue();
});
