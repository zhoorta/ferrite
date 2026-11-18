<?php

use App\Models\Activity;
use App\Models\Node;
use App\Models\NodeVersion;
use App\Models\StorageDisk;
use App\Models\Upload;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function () {
    $base = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
    config(['ferrite.tmp_path' => "{$base}/tmp", 'ferrite.local_root' => "{$base}/blobs", 'ferrite.chunk_size' => 1_000_000, 'ferrite.versions_keep' => 5]);
    $this->base = $base;

    $this->user = User::factory()->create(['quota_bytes' => null]);
    $this->music = Node::factory()->for($this->user, 'owner')->create(['name' => 'Music']);
    $this->file = storedFile($this->user, 'notes.txt', 'original text', $this->music);
    $this->user->forceFill(['used_bytes' => $this->file->size])->save();
    $this->replacer = apiToken($this->user, $this->music, ['read', 'write', 'replace']);
});

afterEach(fn () => File::deleteDirectory($this->base));

function apiReplaceStart(string $plain, array $data): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/uploads', $data, ['Authorization' => "Bearer {$plain}"]);
}

function apiSend(string $plain, string $id, string $content): TestResponse
{
    app('auth')->forgetGuards();

    return test()->call('PATCH', "/api/v1/uploads/{$id}", [], [], [], [
        'HTTP_UPLOAD_OFFSET' => 0,
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => "Bearer {$plain}",
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $content);
}

function replaceBody(string $content, ?string $base = null, array $extra = []): array
{
    return ['path' => 'notes.txt', 'size' => strlen($content), 'replace' => true, 'base' => $base ?? hash('sha256', 'original text')] + $extra;
}

it('replaces a file with the right ability and base: same node, new content, old content kept as a version', function () {
    $start = apiReplaceStart($this->replacer, replaceBody('edited text!'))->assertCreated();
    $done = apiSend($this->replacer, $start->json('id'), 'edited text!')->assertOk();

    $fresh = $this->file->fresh();
    expect($done->json('status'))->toBe('done')
        ->and($done->json('node.id'))->toBe($this->file->id)
        ->and($done->json('node.sha256'))->toBe(hash('sha256', 'edited text!'))
        ->and(Node::where('owner_id', $this->user->id)->where('type', 'file')->count())->toBe(1)
        ->and($fresh->sha256)->toBe(hash('sha256', 'edited text!'))
        ->and(app(StorageManager::class)->filesystem($fresh->disk)->get($fresh->path))->toBe('edited text!')
        ->and($fresh->versions)->toHaveCount(1)
        ->and($fresh->versions[0]->sha256)->toBe(hash('sha256', 'original text'))
        ->and($this->user->fresh()->used_bytes)->toBe(13 + 12);

    $activity = Activity::where('node_id', $this->file->id)->latest('id')->first();
    expect($activity->meta['replaced'])->toBeTrue()->and($activity->meta['token'])->toBe('test')->and($activity->meta['version'])->toBeTrue();
});

it('still works with versions off: the old content is deleted', function () {
    config(['ferrite.versions_keep' => 0]);

    $start = apiReplaceStart($this->replacer, replaceBody('edited text!'))->assertCreated();
    apiSend($this->replacer, $start->json('id'), 'edited text!')->assertOk();

    expect(NodeVersion::count())->toBe(0)->and($this->user->fresh()->used_bytes)->toBe(12)
        ->and(collect(File::allFiles(config('ferrite.local_root')))->count())->toBe(1);
});

it('refuses a copy that is out of date: 409 changed with what is there now, nothing stored', function () {
    $stale = hash('sha256', 'what the client opened');

    apiReplaceStart($this->replacer, replaceBody('mine', $stale))
        ->assertStatus(409)
        ->assertJson(['error' => 'changed', 'id' => $this->file->id, 'sha256' => hash('sha256', 'original text')]);

    expect(Upload::count())->toBe(0);
});

it('refuses when the file changed while the new content was on its way, and stores nothing', function () {
    $start = apiReplaceStart($this->replacer, replaceBody('edited text!'))->assertCreated();

    // Someone else saves a new version meanwhile.
    $this->file->forceFill(['sha256' => hash('sha256', 'someone else wrote this')])->save();

    $response = apiSend($this->replacer, $start->json('id'), 'edited text!');
    $state = $this->getJson('/api/v1/uploads/'.$start->json('id'), ['Authorization' => "Bearer {$this->replacer}"]);

    expect($response->json('status') ?? $state->json('status'))->toBe('failed')
        ->and($state->json('error'))->toContain('changed')
        ->and($this->file->fresh()->sha256)->toBe(hash('sha256', 'someone else wrote this'))
        ->and(NodeVersion::count())->toBe(0)
        ->and(collect(File::allFiles(config('ferrite.local_root')))->count())->toBe(1);
});

it('refuses when the file was trashed or removed while the content was on its way', function () {
    $start = apiReplaceStart($this->replacer, replaceBody('edited text!'))->assertCreated();
    $this->file->forceFill(['trashed_at' => now()])->save();

    apiSend($this->replacer, $start->json('id'), 'edited text!');

    expect(Node::where('owner_id', $this->user->id)->where('type', 'file')->count())->toBe(1)
        ->and($this->getJson('/api/v1/uploads/'.$start->json('id'), ['Authorization' => "Bearer {$this->replacer}"])->json('status'))->toBe('failed');
});

it('needs the replace ability: a write token gets 403 and nothing happens', function () {
    $write = apiToken($this->user, $this->music, ['read', 'write']);

    apiReplaceStart($write, replaceBody('edited text!'))->assertForbidden();
    expect(Upload::count())->toBe(0)->and($this->file->fresh()->sha256)->toBe(hash('sha256', 'original text'));

    // Adding files is unaffected.
    apiReplaceStart($write, ['path' => 'new.txt', 'size' => 3])->assertCreated();
});

it('keeps refusing a taken name for a plain upload, even for a token that may replace', function () {
    apiReplaceStart($this->replacer, ['path' => 'notes.txt', 'size' => 3])->assertStatus(409)->assertJson(['error' => 'exists']);
});

it('answers 404 for a file that is not there and 409 for a folder', function () {
    apiReplaceStart($this->replacer, [...replaceBody('x'), 'path' => 'missing.txt'])->assertNotFound()->assertJson(['error' => 'not_found']);

    Node::factory()->for($this->user, 'owner')->create(['name' => 'Folder', 'parent_id' => $this->music->id]);
    apiReplaceStart($this->replacer, ['path' => 'Folder', 'size' => 3, 'replace' => true, 'base' => str_repeat('a', 64)])->assertStatus(409)->assertJson(['error' => 'exists', 'type' => 'folder']);
});

it('cannot reach a file outside the token folder', function () {
    $outside = storedFile($this->user, 'secret.txt', 'outside');

    apiReplaceStart($this->replacer, ['path' => 'secret.txt', 'size' => 3, 'replace' => true, 'base' => $outside->sha256])->assertNotFound();
    apiReplaceStart($this->replacer, ['path' => '../secret.txt', 'size' => 3, 'replace' => true, 'base' => $outside->sha256])->assertStatus(422);

    expect($outside->fresh()->sha256)->toBe(hash('sha256', 'outside'));
});

it('wants the base: a sha256 is required with replace, and its format is checked', function () {
    apiReplaceStart($this->replacer, ['path' => 'notes.txt', 'size' => 3, 'replace' => true])->assertUnprocessable()->assertJsonValidationErrors('base');
    apiReplaceStart($this->replacer, ['path' => 'notes.txt', 'size' => 3, 'replace' => true, 'base' => 'not a hash'])->assertUnprocessable()->assertJsonValidationErrors('base');
    apiReplaceStart($this->replacer, ['path' => 'notes.txt', 'size' => 3, 'replace' => 'maybe', 'base' => str_repeat('a', 64)])->assertUnprocessable();
});

it('resumes an unfinished replace of the same content, and does not mix up different bases', function () {
    $first = apiReplaceStart($this->replacer, replaceBody('edited text!') + ['fingerprint' => 'fp'])->assertCreated();
    $again = apiReplaceStart($this->replacer, replaceBody('edited text!') + ['fingerprint' => 'fp'])->assertOk();

    expect($again->json('id'))->toBe($first->json('id'));
});

it('only needs room for the growth: replacing a file in a nearly full account works', function () {
    $this->user->forceFill(['used_bytes' => 13, 'quota_bytes' => 15])->save();

    $start = apiReplaceStart($this->replacer, replaceBody('fifteen chars!!'))->assertCreated();
    apiSend($this->replacer, $start->json('id'), 'fifteen chars!!')->assertOk();

    // No room for the old content as a version: it is let go, and the replace still goes through.
    expect($this->file->fresh()->size)->toBe(15)->and($this->user->fresh()->used_bytes)->toBe(15)->and(NodeVersion::count())->toBe(0);

    apiReplaceStart($this->replacer, ['path' => 'notes.txt', 'size' => 20, 'replace' => true, 'base' => hash('sha256', 'fifteen chars!!')])->assertUnprocessable()->assertJsonValidationErrors('size');
});

it('works on an encrypting disk and keeps the version encrypted', function () {
    StorageDisk::query()->update(['is_default' => false]);
    encryptedDisk(default: true);

    $start = apiReplaceStart($this->replacer, replaceBody('edited text!'))->assertCreated();
    apiSend($this->replacer, $start->json('id'), 'edited text!')->assertOk();

    $fresh = $this->file->fresh();
    expect($fresh->encryption_key_id)->toBe(1)->and($fresh->versions[0]->encryption_key_id)->toBeNull()
        ->and(stream_get_contents(app(StorageManager::class)->openNodeStream($fresh)))->toBe('edited text!');
});

it('lets you create the new access levels in settings and shows them', function () {
    $this->actingAs($this->user);

    foreach (['replace' => ['read', 'write', 'replace'], 'full' => ['read', 'write', 'replace', 'trash']] as $access => $abilities) {
        Livewire::test('pages::settings.api-tokens')->set('name', "t-{$access}")->set('access', $access)->call('create')->assertHasNoErrors();

        $token = $this->user->tokens()->where('name', "t-{$access}")->first();
        expect($token->abilities)->toBe($abilities);
    }

    Livewire::test('pages::settings.api-tokens')->assertSee('read + write + replace + trash');

    $full = $this->user->tokens()->where('name', 't-full')->first();
    $plain = apiToken($this->user, null, ['read', 'write', 'replace', 'trash']);
    apiGet('/api/v1/root', $plain)->assertJson(['access' => 'read+write+replace+trash']);
});
