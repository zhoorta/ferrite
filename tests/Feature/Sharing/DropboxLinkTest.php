<?php

use App\Models\Activity;
use App\Models\Node;
use App\Models\Share;
use App\Models\Upload;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function () {
    $base = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
    config(['ferrite.tmp_path' => "{$base}/tmp", 'ferrite.local_root' => "{$base}/blobs", 'ferrite.chunk_size' => 4]);
    $this->base = $base;

    $this->owner = User::factory()->create();
    $this->folder = Node::factory()->for($this->owner, 'owner')->create(['name' => 'From clients']);
    $this->existing = storedFile($this->owner, 'secret.txt', 'do not show', $this->folder);
    $this->share = Share::factory()->dropbox()->create(['node_id' => $this->folder->id]);
    $this->token = $this->share->token;
});

afterEach(fn () => File::deleteDirectory($this->base));

function dropStart(string $token, array $data = []): TestResponse
{
    return test()->postJson(route('share.uploads.store', $token), $data + ['path' => 'a.txt', 'size' => 10]);
}

function dropChunk(string $token, string $id, int $offset, string $content): TestResponse
{
    return test()->call('PATCH', route('share.uploads.update', [$token, $id]), [], [], [], [
        'HTTP_UPLOAD_OFFSET' => $offset,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $content);
}

function dropAll(string $token, string $path, string $content): TestResponse
{
    $id = dropStart($token, ['path' => $path, 'size' => strlen($content)])->json('id');
    $response = null;

    foreach (str_split($content, 4) ?: [''] as $i => $chunk) {
        $response = dropChunk($token, $id, $i * 4, $chunk);
    }

    return $response ?? dropChunk($token, $id, 0, '');
}

describe('the guest page', function () {
    it('shows a drop zone and nothing from the folder', function () {
        $this->get(route('share.show', $this->token))
            ->assertOk()
            ->assertSee('Send files to From clients')
            ->assertSee('data-test="dropbox"', false)
            ->assertDontSee('secret.txt')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    it('has no way to browse, download or preview', function () {
        $sub = Node::factory()->inside($this->folder)->create(['name' => 'Inner']);

        $this->get(route('share.show', [$this->token, $sub->id]))->assertNotFound();
        $this->get(route('share.show', [$this->token, $this->existing->id]))->assertNotFound();
        $this->get(route('share.download', [$this->token, $this->existing->id]))->assertNotFound();
        $this->get(route('share.preview', [$this->token, $this->existing->id]))->assertNotFound();
        $this->get(route('share.thumbnail', [$this->token, $this->existing->id]))->assertNotFound();
        $this->get(route('share.zip', $this->token))->assertNotFound();
    });

    it('answers 404 for revoked, expired and trashed links', function () {
        $revoked = Share::factory()->dropbox()->revoked()->create(['node_id' => $this->folder->id]);
        $expired = Share::factory()->dropbox()->expired()->create(['node_id' => $this->folder->id]);

        $this->get(route('share.show', $revoked->token))->assertNotFound();
        dropStart($revoked->token)->assertNotFound();
        $this->get(route('share.show', $expired->token))->assertNotFound();
        dropStart($expired->token)->assertNotFound();

        $this->folder->forceFill(['trashed_at' => now()])->save();

        $this->get(route('share.show', $this->token))->assertNotFound();
        dropStart($this->token)->assertNotFound();
    });
});

describe('uploading', function () {
    it('stores a file in the folder for the owner, with no login', function () {
        dropAll($this->token, 'hello.txt', 'hello world')->assertOk()->assertJsonPath('node.name', 'hello.txt');

        $node = Node::firstWhere('name', 'hello.txt');

        expect($node->parent_id)->toBe($this->folder->id)
            ->and($node->owner_id)->toBe($this->owner->id)
            ->and(app(StorageManager::class)->filesystem($node->disk)->get($node->path))->toBe('hello world')
            ->and($this->owner->fresh()->used_bytes)->toBe(11)
            ->and($this->share->fresh()->received_bytes)->toBe(11);
    });

    it('lets the owner see it in the activity log', function () {
        dropAll($this->token, 'hello.txt', 'hello world')->assertOk();

        $activity = Activity::query()->where('action', 'link_uploaded')->sole();

        expect($activity->owner_id)->toBe($this->owner->id)
            ->and($activity->actor_id)->toBeNull()
            ->and($activity->node_name)->toBe('hello.txt')
            ->and($activity->sentence($this->owner))->toContain('hello.txt');
    });

    it('keeps both files when the name is taken and never overwrites', function () {
        dropAll($this->token, 'secret.txt', 'attacker')->assertOk();

        $names = Node::query()->where('parent_id', $this->folder->id)->pluck('name')->all();

        expect($names)->toHaveCount(2)->toContain('secret.txt')
            ->and(app(StorageManager::class)->filesystem($this->existing->disk)->get($this->existing->path))->toBe('do not show');
    });

    it('flattens a dropped folder path to the file name', function () {
        dropAll($this->token, 'photos/2026/../../a.jpg', 'x')->assertOk();

        expect(Node::query()->where('parent_id', $this->folder->id)->where('name', 'a.jpg')->exists())->toBeTrue()
            ->and(Node::query()->where('type', 'folder')->count())->toBe(1);
    });

    it('counts against the owner quota', function () {
        $this->owner->forceFill(['quota_bytes' => $this->owner->used_bytes + 5])->save();

        dropStart($this->token, ['size' => 6])->assertUnprocessable()->assertJsonValidationErrors('size');
        dropAll($this->token, 'ok.txt', 'abcd')->assertOk();
    });

    it('refuses an owner who is disabled', function () {
        $this->owner->forceFill(['disabled_at' => now()])->save();

        dropStart($this->token)->assertUnprocessable();
    });

    it('enforces the size limit of the link', function () {
        $limited = Share::factory()->dropbox(10)->create(['node_id' => $this->folder->id]);

        dropAll($limited->token, 'a.txt', 'aaaaaaa')->assertOk();
        expect($limited->fresh()->received_bytes)->toBe(7);

        dropStart($limited->token, ['path' => 'b.txt', 'size' => 4])->assertUnprocessable()->assertJsonValidationErrors('size');
        dropAll($limited->token, 'c.txt', 'ccc')->assertOk();
        expect($limited->fresh()->remainingBytes())->toBe(0);
    });

    it('refuses a file at completion when other uploads filled the limit meanwhile', function () {
        $limited = Share::factory()->dropbox(10)->create(['node_id' => $this->folder->id]);

        // Two uploads started while the link was empty, in different sessions.
        $first = dropStart($limited->token, ['path' => 'a.txt', 'size' => 6])->assertCreated()->json('id');
        $this->flushSession();
        $second = dropStart($limited->token, ['path' => 'b.txt', 'size' => 6]);

        // The second start already counts the first as on its way.
        $second->assertUnprocessable();

        $limited->forceFill(['received_bytes' => 8])->save();
        $this->session(['dropbox_uploads.'.$limited->id => [$first]]);

        dropChunk($limited->token, $first, 0, 'aaaa');
        $response = dropChunk($limited->token, $first, 4, 'aa');

        $response->assertStatus(422);
        expect(Node::query()->where('name', 'a.txt')->exists())->toBeFalse();
    });

    it('refuses a file when the link is revoked while it is on its way', function () {
        $id = dropStart($this->token, ['path' => 'a.txt', 'size' => 6])->json('id');
        dropChunk($this->token, $id, 0, 'aaaa')->assertOk();

        $this->share->forceFill(['revoked_at' => now()])->save();

        dropChunk($this->token, $id, 4, 'aa')->assertNotFound();
        expect(Node::query()->where('name', 'a.txt')->exists())->toBeFalse();
    });

    it('resumes an unfinished upload of the same file in the same session', function () {
        $id = dropStart($this->token, ['size' => 10, 'fingerprint' => 'f1'])->json('id');
        dropChunk($this->token, $id, 0, 'abcd')->assertOk();

        dropStart($this->token, ['size' => 10, 'fingerprint' => 'f1'])->assertOk()->assertJson(['id' => $id, 'offset' => 4]);
        expect(Upload::count())->toBe(1);
    });
});

describe('who can touch an upload', function () {
    it('keeps uploads private to the session that started them', function () {
        $id = dropStart($this->token)->json('id');

        $this->flushSession();

        $this->getJson(route('share.uploads.show', [$this->token, $id]))->assertNotFound();
        dropChunk($this->token, $id, 0, 'abcd')->assertNotFound();
        $this->deleteJson(route('share.uploads.destroy', [$this->token, $id]))->assertNotFound();
    });

    it('does not accept an upload through another link', function () {
        $other = Share::factory()->dropbox()->create(['node_id' => $this->folder->id]);
        $id = dropStart($this->token)->json('id');

        $this->getJson(route('share.uploads.show', [$other->token, $id]))->assertNotFound();
    });

    it('does not let a signed-in user reach a guest upload through the normal route', function () {
        $id = dropStart($this->token)->json('id');

        $this->actingAs($this->owner)->getJson(route('uploads.show', $id))->assertOk();
        $this->actingAs(User::factory()->create())->getJson(route('uploads.show', $id))->assertNotFound();
    });

    it('can cancel its own upload', function () {
        $id = dropStart($this->token)->json('id');

        $this->deleteJson(route('share.uploads.destroy', [$this->token, $id]))->assertNoContent();
        expect(Upload::count())->toBe(0);
    });

    it('refuses an ordinary view link on the upload routes', function () {
        $view = Share::factory()->create(['node_id' => $this->folder->id]);

        dropStart($view->token)->assertNotFound();
    });
});

describe('password', function () {
    it('keeps the upload routes closed until the password is entered', function () {
        $locked = Share::factory()->dropbox()->password('open sesame')->create(['node_id' => $this->folder->id]);

        dropStart($locked->token)->assertForbidden();
        $this->get(route('share.show', $locked->token))->assertOk()->assertSee('This link is protected')->assertDontSee('data-test="dropbox"', false);

        $this->withSession(['share_unlocks' => [$locked->id => hash('sha256', (string) $locked->password_hash)]]);

        dropStart($locked->token)->assertCreated();
    });
});

describe('view links are unchanged', function () {
    it('still lists the folder and offers no upload', function () {
        $view = Share::factory()->create(['node_id' => $this->folder->id]);

        $this->get(route('share.show', $view->token))->assertOk()->assertSee('secret.txt')->assertDontSee('data-test="dropbox"', false);
        $this->postJson(route('share.uploads.store', $view->token), ['path' => 'a', 'size' => 1])->assertNotFound();
    });
});

describe('share dialog', function () {
    it('creates an upload link with a size limit for a folder', function () {
        $this->actingAs($this->owner);

        Livewire::test('pages::files.share-dialog')
            ->call('open', $this->folder->id)
            ->set('linkKind', 'dropbox')
            ->set('maxSize', '2')
            ->set('linkPassword', 'pw')
            ->call('createLink')
            ->assertHasNoErrors()
            ->assertSee('Upload');

        $share = Share::query()->where('node_id', $this->folder->id)->latest('id')->first();

        expect($share->isDropbox())->toBeTrue()
            ->and($share->max_bytes)->toBe(2 * 1024 ** 3)
            ->and($share->allow_download)->toBeFalse()
            ->and(Hash::check('pw', $share->password_hash))->toBeTrue();
    });

    it('does not create an upload link for a file', function () {
        $this->actingAs($this->owner);

        Livewire::test('pages::files.share-dialog')
            ->call('open', $this->existing->id)
            ->set('linkKind', 'dropbox')
            ->call('createLink')
            ->assertHasErrors('kind');

        expect(Share::query()->where('node_id', $this->existing->id)->exists())->toBeFalse();
    });
});
