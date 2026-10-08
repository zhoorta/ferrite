<?php

use App\Jobs\ExtractContent;
use App\Models\Activity;
use App\Models\Node;
use App\Models\NodeContent;
use App\Models\Share;
use App\Models\Upload;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $base = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
    config(['ferrite.tmp_path' => "{$base}/tmp", 'ferrite.local_root' => "{$base}/blobs", 'ferrite.chunk_size' => 4]);
    $this->base = $base;

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

afterEach(fn () => File::deleteDirectory($this->base));

function conflictsFor(array $paths, ?int $parentId = null): TestResponse
{
    return test()->postJson(route('uploads.conflicts'), ['paths' => $paths, 'parent_id' => $parentId]);
}

/** Upload $content as $path in chunks of four bytes; $replace is the choice made in the dialog. */
function uploadFile(string $path, string $content, ?int $parentId = null, ?bool $replace = null): TestResponse
{
    $data = ['path' => $path, 'size' => strlen($content), 'parent_id' => $parentId] + ($replace === null ? [] : ['replace' => $replace]);
    $id = test()->postJson(route('uploads.store'), $data)->json('id');
    $response = null;

    foreach (str_split($content, 4) ?: [''] as $i => $chunk) {
        $response = test()->call('PATCH', route('uploads.update', $id), [], [], [], [
            'HTTP_UPLOAD_OFFSET' => $i * 4,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ], $chunk);
    }

    return $response;
}

function blob(Node $node): string
{
    return app(StorageManager::class)->filesystem($node->disk)->get($node->path);
}

describe('finding conflicts', function () {
    it('reports files and folders that already have the name', function () {
        storedFile($this->user, 'report.txt', 'old');
        Node::factory()->for($this->user, 'owner')->create(['name' => 'Photos']);

        $result = conflictsFor(['report.txt', 'new.txt', 'photos'])->assertOk()->json();

        expect($result['conflicts'])->toBe([['path' => 'report.txt', 'kind' => 'file'], ['path' => 'photos', 'kind' => 'folder']])
            ->and($result['merged'])->toBe([]);
    });

    it('ignores case, like names do everywhere else', function () {
        storedFile($this->user, 'Report.TXT', 'old');

        expect(conflictsFor(['report.txt'])->json('conflicts'))->toHaveCount(1);
    });

    it('does not count trashed items', function () {
        $old = storedFile($this->user, 'report.txt', 'old');
        $old->forceFill(['trashed_at' => now()])->save();

        expect(conflictsFor(['report.txt'])->json('conflicts'))->toBe([]);
    });

    it('looks inside existing folders for the files of a dropped folder, and says which folders are merged', function () {
        $photos = Node::factory()->for($this->user, 'owner')->create(['name' => 'Photos']);
        $year = Node::factory()->inside($photos)->create(['name' => '2026']);
        storedFile($this->user, 'a.jpg', 'x', $year);

        $result = conflictsFor(['Photos/2026/a.jpg', 'Photos/2026/b.jpg', 'Photos/2027/a.jpg', 'Other/a.jpg'])->assertOk()->json();

        expect($result['conflicts'])->toBe([['path' => 'Photos/2026/a.jpg', 'kind' => 'file']])
            ->and($result['merged'])->toBe(['Photos']);
    });

    it('checks inside the folder being uploaded to', function () {
        $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Docs']);
        storedFile($this->user, 'report.txt', 'old', $folder);
        storedFile($this->user, 'only-at-root.txt', 'x');

        expect(conflictsFor(['report.txt', 'only-at-root.txt'], $folder->id)->json('conflicts'))->toBe([['path' => 'report.txt', 'kind' => 'file']]);
    });

    it('is not allowed into a folder the user cannot write to', function () {
        $other = User::factory()->create();
        $theirs = Node::factory()->for($other, 'owner')->create(['name' => 'Theirs']);

        conflictsFor(['a.txt'], $theirs->id)->assertForbidden();

        $theirs->sharedWith()->attach($this->user, ['permission' => 'view']);
        conflictsFor(['a.txt'], $theirs->id)->assertForbidden();

        $theirs->sharedWith()->updateExistingPivot($this->user->id, ['permission' => 'edit']);
        conflictsFor(['a.txt'], $theirs->id)->assertOk();
    });

    it('needs a login and a list of paths', function () {
        conflictsFor([])->assertUnprocessable();

        auth()->logout();
        $this->postJson(route('uploads.conflicts'), ['paths' => ['a']])->assertUnauthorized();
    });
});

describe('replacing', function () {
    it('keeps both files by default, as before', function () {
        storedFile($this->user, 'report.txt', 'old');

        uploadFile('report.txt', 'new content')->assertOk();

        expect(Node::query()->where('owner_id', $this->user->id)->pluck('name')->sort()->values()->all())->toBe(['report (2).txt', 'report.txt']);
    });

    it('replaces the content of the same file, keeping its identity', function () {
        $old = storedFile($this->user, 'report.txt', 'old');
        $this->user->forceFill(['used_bytes' => 3])->save();
        $share = Share::factory()->create(['node_id' => $old->id]);
        $this->user->favorites()->attach($old->id);
        $oldPath = $old->path;

        uploadFile('report.txt', 'new content', replace: true)->assertOk()->assertJsonPath('node.id', $old->id);

        $fresh = $old->fresh();

        expect(Node::query()->where('owner_id', $this->user->id)->count())->toBe(1)
            ->and($fresh->name)->toBe('report.txt')
            ->and($fresh->size)->toBe(11)
            ->and($fresh->sha256)->toBe(hash('sha256', 'new content'))
            ->and(blob($fresh))->toBe('new content')
            ->and($this->user->fresh()->used_bytes)->toBe(11)
            ->and($share->fresh()->node_id)->toBe($old->id)
            ->and($this->user->favorites()->whereKey($old->id)->exists())->toBeTrue()
            ->and(app(StorageManager::class)->filesystem($fresh->disk)->exists($oldPath))->toBeFalse();
    });

    it('records it in the activity log as a replacement', function () {
        storedFile($this->user, 'report.txt', 'old');

        uploadFile('report.txt', 'new content', replace: true)->assertOk();

        $activity = Activity::query()->latest('id')->first();

        expect($activity->meta)->toMatchArray(['replaced' => true])
            ->and($activity->sentence($this->user))->toContain('replaced report.txt');
    });

    it('indexes the new text for content search', function () {
        $old = storedFile($this->user, 'notes.txt', 'oranges');
        ExtractContent::queueFor($old);

        uploadFile('notes.txt', 'lemons here', replace: true)->assertOk();

        expect(NodeContent::query()->where('sha256', hash('sha256', 'lemons here'))->value('text'))->toBe('lemons here');
    });

    it('does not delete a blob that another file still uses', function () {
        $first = storedFile($this->user, 'a.txt', 'shared');
        $second = $first->replicate();
        $second->owner_id = $first->owner_id;
        $second->name = 'b.txt';
        $second->save();

        uploadFile('a.txt', 'different', replace: true)->assertOk();

        expect(blob($second->fresh()))->toBe('shared');
    });

    it('is harmless when the content is the same', function () {
        $old = storedFile($this->user, 'a.txt', 'same');
        $this->user->forceFill(['used_bytes' => 4])->save();

        uploadFile('a.txt', 'same', replace: true)->assertOk();

        expect(blob($old->fresh()))->toBe('same')->and($this->user->fresh()->used_bytes)->toBe(4)->and(Node::query()->count())->toBe(1);
    });

    it('creates the file when there is nothing to replace', function () {
        uploadFile('new.txt', 'hello', replace: true)->assertOk();

        expect(Node::query()->pluck('name')->all())->toBe(['new.txt']);
    });

    it('keeps both when the name belongs to a folder', function () {
        Node::factory()->for($this->user, 'owner')->create(['name' => 'Stuff']);

        uploadFile('Stuff', 'x', replace: true)->assertOk();

        expect(Node::query()->orderBy('id')->pluck('name')->all())->toBe(['Stuff', 'Stuff (2)']);
    });

    it('counts only the growth against the quota', function () {
        storedFile($this->user, 'a.txt', str_repeat('x', 10));
        $this->user->forceFill(['used_bytes' => 10, 'quota_bytes' => 12])->save();

        uploadFile('a.txt', str_repeat('y', 12), replace: true)->assertOk();

        expect($this->user->fresh()->used_bytes)->toBe(12);

        $this->postJson(route('uploads.store'), ['path' => 'a.txt', 'size' => 13, 'replace' => true])->assertUnprocessable()->assertJsonValidationErrors('size');
    });

    it('works for an editor in a shared folder, on the owner\'s account', function () {
        $owner = User::factory()->create();
        $folder = Node::factory()->for($owner, 'owner')->create(['name' => 'Team']);
        $file = storedFile($owner, 'plan.txt', 'v1', $folder);
        $owner->forceFill(['used_bytes' => 2])->save();
        $folder->sharedWith()->attach($this->user, ['permission' => 'edit']);

        uploadFile('plan.txt', 'version two', $folder->id, replace: true)->assertOk();

        expect(blob($file->fresh()))->toBe('version two')
            ->and($file->fresh()->owner_id)->toBe($owner->id)
            ->and($owner->fresh()->used_bytes)->toBe(11);
    });

    it('is not honoured through a drop-box link', function () {
        $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Inbox']);
        $existing = storedFile($this->user, 'a.txt', 'mine', $folder);
        $share = Share::factory()->dropbox()->create(['node_id' => $folder->id]);

        auth()->logout();

        $id = $this->postJson(route('share.uploads.store', $share->token), ['path' => 'a.txt', 'size' => 5, 'replace' => true])->json('id');
        $this->call('PATCH', route('share.uploads.update', [$share->token, $id]), [], [], [], [
            'HTTP_UPLOAD_OFFSET' => 0, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream',
        ], 'guest');
        $this->call('PATCH', route('share.uploads.update', [$share->token, $id]), [], [], [], [
            'HTTP_UPLOAD_OFFSET' => 5, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream',
        ], '');

        expect(blob($existing->fresh()))->toBe('mine')
            ->and(Upload::query()->first()?->replace ?? false)->toBeFalse();
    });
});
