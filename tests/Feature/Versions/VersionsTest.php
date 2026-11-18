<?php

use App\Actions\Nodes\DeleteVersion;
use App\Actions\Nodes\PurgeNode;
use App\Actions\Nodes\RestoreVersion;
use App\Actions\Storage\DeleteStorageDisk;
use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Node;
use App\Models\NodeVersion;
use App\Models\StorageDisk;
use App\Models\User;
use App\Support\StorageManager;
use App\Support\StorageUsage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $base = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
    config(['ferrite.tmp_path' => "{$base}/tmp", 'ferrite.local_root' => "{$base}/blobs", 'ferrite.chunk_size' => 1_000_000, 'ferrite.versions_keep' => 5, 'ferrite.versions_days' => 0]);
    $this->base = $base;

    $this->user = User::factory()->create(['quota_bytes' => null]);
    $this->actingAs($this->user);
});

afterEach(fn () => File::deleteDirectory($this->base));

/** Upload $content over a file of the same name, answering "replace" in the dialog. */
function replaceWith(string $name, string $content, ?int $parentId = null): TestResponse
{
    $id = test()->postJson(route('uploads.store'), ['path' => $name, 'size' => strlen($content), 'parent_id' => $parentId, 'replace' => true])->json('id');

    return test()->call('PATCH', route('uploads.update', $id), [], [], [], [
        'HTTP_UPLOAD_OFFSET' => 0,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $content);
}

/** A file with a few versions: "v1" is the oldest, the current content is $current. */
function fileWithHistory(User $owner, array $contents): Node
{
    $node = storedFile($owner, 'doc.txt', array_shift($contents));
    $owner->forceFill(['used_bytes' => $node->size])->save();

    foreach ($contents as $content) {
        replaceWith('doc.txt', $content)->assertOk();
    }

    return $node->fresh();
}

function versionBlobExists(string $path, StorageDisk $disk): bool
{
    return app(StorageManager::class)->filesystem($disk)->exists($path);
}

function versionBody(TestResponse $response): string
{
    return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
}

describe('keeping versions on replace', function () {
    it('keeps the old content as a version, counts it in the quota and keeps the node', function () {
        $node = fileWithHistory($this->user, ['one', 'second']);
        $version = $node->versions()->first();

        expect($node->size)->toBe(6)
            ->and($node->versions)->toHaveCount(1)
            ->and($version->size)->toBe(3)
            ->and($version->sha256)->toBe(hash('sha256', 'one'))
            ->and($version->user_id)->toBe($this->user->id)
            ->and($this->user->fresh()->used_bytes)->toBe(9)
            ->and(versionBlobExists($version->path, $node->disk))->toBeTrue()
            ->and(Node::where('owner_id', $this->user->id)->count())->toBe(1);

        $activity = Activity::where('node_id', $node->id)->latest('id')->first();
        expect($activity->meta['replaced'])->toBeTrue()->and($activity->meta['version'])->toBeTrue();
    });

    it('does not make a version when the content did not change', function () {
        $node = fileWithHistory($this->user, ['same', 'same']);

        expect($node->versions)->toHaveCount(0)->and($this->user->fresh()->used_bytes)->toBe(4);
    });

    it('keeps at most FERRITE_VERSIONS_KEEP and deletes the oldest blobs and their quota', function () {
        config(['ferrite.versions_keep' => 2]);
        $node = fileWithHistory($this->user, ['one', 'two2', 'three', 'four4', 'five55']);
        $versions = $node->versions;

        expect($versions)->toHaveCount(2)
            ->and($versions->pluck('sha256')->all())->toBe([hash('sha256', 'four4'), hash('sha256', 'three')])
            ->and($this->user->fresh()->used_bytes)->toBe(6 + 5 + 5);

        foreach (['one', 'two2'] as $gone) {
            expect(Node::where('sha256', hash('sha256', $gone))->exists())->toBeFalse()
                ->and(NodeVersion::where('sha256', hash('sha256', $gone))->exists())->toBeFalse();
        }

        expect(collect(File::allFiles(config('ferrite.local_root')))->count())->toBe(3);
    });

    it('is off with FERRITE_VERSIONS_KEEP=0: the old content goes and the quota follows', function () {
        config(['ferrite.versions_keep' => 0]);
        $node = fileWithHistory($this->user, ['one', 'second']);

        expect($node->versions)->toHaveCount(0)->and($this->user->fresh()->used_bytes)->toBe(6)
            ->and(collect(File::allFiles(config('ferrite.local_root')))->count())->toBe(1);
    });

    it('never blocks a replace: when the quota cannot hold the old content too, it is let go', function () {
        $node = storedFile($this->user, 'doc.txt', 'one');
        $this->user->forceFill(['used_bytes' => 3, 'quota_bytes' => 10])->save();

        replaceWith('doc.txt', 'second')->assertOk();   // 3 + 6 > 10? no: fits, so the version is kept
        expect($node->fresh()->versions)->toHaveCount(1)->and($this->user->fresh()->used_bytes)->toBe(9);

        replaceWith('doc.txt', 'third3')->assertOk();   // keeping "second" too would need 15
        $fresh = $node->fresh();

        expect($fresh->size)->toBe(6)
            ->and($fresh->versions)->toHaveCount(1)
            ->and($this->user->fresh()->used_bytes)->toBe(9);
    });

    it('keeps versions for an editor in a shared folder, on the owner\'s account', function () {
        $owner = User::factory()->create(['quota_bytes' => null]);
        $folder = Node::factory()->for($owner, 'owner')->create(['name' => 'Team']);
        $file = storedFile($owner, 'plan.txt', 'v1', $folder);
        $owner->forceFill(['used_bytes' => 2])->save();
        $folder->sharedWith()->attach($this->user, ['permission' => Permission::Edit->value]);

        replaceWith('plan.txt', 'version two', $folder->id)->assertOk();

        $version = $file->fresh()->versions()->first();
        expect($version->user_id)->toBe($this->user->id)->and($owner->fresh()->used_bytes)->toBe(2 + 11);
    });
});

describe('looking at and restoring versions', function () {
    it('downloads an old version with its own content and hash', function () {
        $node = fileWithHistory($this->user, ['first version', 'second']);
        $version = $node->versions()->first();

        $response = $this->get(route('nodes.version', [$node, $version]))->assertOk()
            ->assertHeader('Content-Length', '13')
            ->assertHeader('ETag', '"'.hash('sha256', 'first version').'"');

        expect(versionBody($response))->toBe('first version')
            ->and($response->headers->get('Content-Disposition'))->toContain('doc.txt')
            ->and(versionBody($this->get(route('nodes.download', $node))))->toBe('second');
    });

    it('restores a version: it becomes current, the current content becomes a version, the quota does not move', function () {
        $node = fileWithHistory($this->user, ['one', 'second']);
        $version = $node->versions()->first();
        $before = $this->user->fresh()->used_bytes;

        $restored = app(RestoreVersion::class)->handle($this->user, $version);

        $versions = $restored->versions()->get();
        expect($restored->id)->toBe($node->id)
            ->and($restored->size)->toBe(3)
            ->and($restored->sha256)->toBe(hash('sha256', 'one'))
            ->and(versionBody($this->get(route('nodes.download', $restored))))->toBe('one')
            ->and($versions)->toHaveCount(1)
            ->and($versions[0]->sha256)->toBe(hash('sha256', 'second'))
            ->and($this->user->fresh()->used_bytes)->toBe($before)
            ->and(NodeVersion::whereKey($version->id)->exists())->toBeFalse();

        $activity = Activity::where('node_id', $node->id)->latest('id')->first();
        expect($activity->action->value)->toBe('restored_version');

        // And back again: restoring is not destructive.
        app(RestoreVersion::class)->handle($this->user, $versions[0]);
        expect(versionBody($this->get(route('nodes.download', $node->fresh()))))->toBe('second')
            ->and($node->fresh()->versions)->toHaveCount(1);
    });

    it('deletes a version for good: row, blob and quota', function () {
        $node = fileWithHistory($this->user, ['one', 'second']);
        $version = $node->versions()->first();

        app(DeleteVersion::class)->handle($this->user, $version);

        expect(NodeVersion::count())->toBe(0)
            ->and(versionBlobExists($version->path, $node->disk))->toBeFalse()
            ->and($this->user->fresh()->used_bytes)->toBe(6)
            ->and(Activity::where('node_id', $node->id)->latest('id')->first()->action->value)->toBe('deleted_version');
    });

    it('is for people who can change the file: owners and editors, not viewers or strangers', function () {
        $node = fileWithHistory($this->user, ['one', 'second']);
        $version = $node->versions()->first();
        $viewer = User::factory()->create();
        $node->sharedWith()->attach($viewer, ['permission' => Permission::View->value]);
        $editor = User::factory()->create();
        $node->sharedWith()->attach($editor, ['permission' => Permission::Edit->value]);

        $this->actingAs($viewer);
        $this->get(route('nodes.version', [$node, $version]))->assertForbidden();
        expect(fn () => app(RestoreVersion::class)->handle($viewer, $version))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeleteVersion::class)->handle($viewer, $version))->toThrow(AuthorizationException::class);

        $this->actingAs(User::factory()->create());
        $this->get(route('nodes.version', [$node, $version]))->assertForbidden();

        $this->actingAs($editor);
        $this->get(route('nodes.version', [$node, $version]))->assertOk();
    });

    it('answers 404 for a version of another file or of a trashed file', function () {
        $node = fileWithHistory($this->user, ['one', 'second']);
        $other = storedFile($this->user, 'other.txt', 'x');
        $version = $node->versions()->first();

        $this->get(route('nodes.version', [$other, $version]))->assertNotFound();

        $node->forceFill(['trashed_at' => now()])->save();
        $this->get(route('nodes.version', [$node, $version]))->assertNotFound();
    });
});

describe('removing files with versions', function () {
    it('purging a file removes its versions\' blobs and gives every byte back', function () {
        $node = fileWithHistory($this->user, ['one', 'second', 'third3']);
        $node->forceFill(['trashed_at' => now()])->save();

        app(PurgeNode::class)->handle($this->user, $node);

        expect($this->user->fresh()->used_bytes)->toBe(0)
            ->and(NodeVersion::count())->toBe(0)
            ->and(collect(File::allFiles(config('ferrite.local_root')))->count())->toBe(0);
    });

    it('purging a folder takes the versions of the files inside with it', function () {
        $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Docs']);
        $file = storedFile($this->user, 'doc.txt', 'one', $folder);
        $this->user->forceFill(['used_bytes' => 3])->save();
        replaceWith('doc.txt', 'second', $folder->id)->assertOk();
        $folder->forceFill(['trashed_at' => now()])->save();

        app(PurgeNode::class)->handle($this->user, $folder);

        expect($this->user->fresh()->used_bytes)->toBe(0)->and(NodeVersion::count())->toBe(0)
            ->and(collect(File::allFiles(config('ferrite.local_root')))->count())->toBe(0);
    });

    it('a disk that only holds versions cannot be removed', function () {
        $other = StorageDisk::create(['name' => 'archive', 'driver' => 'local', 'config' => ['root' => $this->base.'/archive'], 'is_default' => false]);
        $node = fileWithHistory($this->user, ['one', 'second']);
        NodeVersion::query()->update(['disk_id' => $other->id]);
        Node::query()->whereKey($node->id)->update(['disk_id' => app(StorageManager::class)->default()->id]);

        expect($other->versions()->exists())->toBeTrue();

        $admin = User::factory()->admin()->create();
        expect(fn () => app(DeleteStorageDisk::class)->handle($admin, $other))->toThrow(ValidationException::class);
    });
});

describe('pruning', function () {
    it('versions:prune applies a lowered limit and the age limit, and refunds the quota', function () {
        $node = fileWithHistory($this->user, ['one', 'two2', 'three', 'four4']);
        expect($node->versions)->toHaveCount(3);

        config(['ferrite.versions_keep' => 2]);
        $this->artisan('versions:prune')->expectsOutputToContain('Removed 1 versions')->assertSuccessful();
        expect($node->fresh()->versions)->toHaveCount(2)->and($this->user->fresh()->used_bytes)->toBe(14);

        config(['ferrite.versions_days' => 30]);
        $node->fresh()->versions()->latest('id')->first()->forceFill(['created_at' => now()->subDays(45)])->save();
        $this->artisan('versions:prune')->expectsOutputToContain('Removed 1 versions')->assertSuccessful();

        expect($node->fresh()->versions)->toHaveCount(1);
    });

    it('is scheduled daily', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'versions:prune'));

        expect($events)->toHaveCount(1);
    });
});

describe('with encryption and the other tools', function () {
    it('keeps encrypted versions encrypted and readable', function () {
        $disk = encryptedDisk(default: true);
        $node = fileWithHistory($this->user, ['plain start']);
        replaceWith('doc.txt', 'encrypted second')->assertOk();
        $node = $node->fresh();
        $version = $node->versions()->first();

        expect($node->encryption_key_id)->toBe(1)->and($version->encryption_key_id)->toBeNull();

        replaceWith('doc.txt', 'encrypted third!')->assertOk();
        $second = $node->fresh()->versions()->first();

        expect($second->encryption_key_id)->toBe(1)
            ->and(versionBody($this->get(route('nodes.version', [$node, $second]))))->toBe('encrypted second')
            ->and(versionBody($this->get(route('nodes.version', [$node, $version]))))->toBe('plain start')
            ->and($disk->versions()->count())->toBe(2);
    });

    it('files:encrypt and files:rekey convert the versions too', function () {
        $node = fileWithHistory($this->user, ['plain start', 'second one']);
        $version = $node->versions()->first();
        $disk = app(StorageManager::class)->default();
        config(['ferrite.file_key' => base64_encode(str_repeat('k', 32)), 'ferrite.file_key_id' => 1, 'ferrite.file_keys_old' => null]);

        $this->artisan('files:encrypt', ['disk' => $disk->name, '--yes' => true])->assertSuccessful();

        $version = $version->fresh();
        expect($version->encryption_key_id)->toBe(1)->and($node->fresh()->encryption_key_id)->toBe(1)
            ->and(versionBody($this->get(route('nodes.version', [$node, $version]))))->toBe('plain start');

        $old = base64_encode(str_repeat('k', 32));
        config(['ferrite.file_key' => base64_encode(str_repeat('n', 32)), 'ferrite.file_key_id' => 2, 'ferrite.file_keys_old' => "1:{$old}"]);
        $this->artisan('files:rekey')->assertSuccessful();
        config(['ferrite.file_keys_old' => null]);

        expect($version->fresh()->encryption_key_id)->toBe(2)
            ->and(versionBody($this->get(route('nodes.version', [$node, $version->fresh()]))))->toBe('plain start');
    });

    it('the storage check sees versions: a missing blob, bit rot, and orphans do not include them', function () {
        $node = fileWithHistory($this->user, ['one', 'second']);
        $version = $node->versions()->first();

        $this->artisan('ferrite:storage:check', ['--verify' => true])->expectsOutputToContain('Verified 2 blobs')->assertSuccessful();
        expect($version->fresh()->verified_at)->not->toBeNull();

        $this->artisan('ferrite:storage:check', ['--orphans' => true])->doesntExpectOutputToContain('ORPHAN')->assertSuccessful();

        unlink(config('ferrite.local_root').'/'.$version->path);
        $this->artisan('ferrite:storage:check')->expectsOutputToContain('MISSING')->assertFailed();
    });

    it('the usage per disk counts version blobs', function () {
        fileWithHistory($this->user, ['one', 'second']);
        $disk = app(StorageManager::class)->default();

        expect(app(StorageUsage::class)->physicalByDisk()[$disk->id])->toBe(9);
    });
});

describe('the dialog', function () {
    it('lists versions, restores and deletes them', function () {
        $node = fileWithHistory($this->user, ['one', 'second', 'third3']);
        $versions = $node->versions;

        Livewire::test('pages::files.versions-dialog')
            ->dispatch('versions-node', id: $node->id)
            ->assertSet('nodeId', $node->id)
            ->assertSeeHtml('data-test="version"')
            ->call('restore', $versions[1]->id)
            ->assertDispatched('versions-changed')
            ->call('delete', $node->fresh()->versions()->latest('id')->first()->id)
            ->assertDispatched('versions-changed');

        expect($node->fresh()->sha256)->toBe(hash('sha256', 'one'));
    });

    it('is not for people who cannot change the file, and not for folders', function () {
        $node = fileWithHistory($this->user, ['one', 'second']);
        $viewer = User::factory()->create();
        $node->sharedWith()->attach($viewer, ['permission' => Permission::View->value]);
        $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'F']);

        $this->actingAs($viewer);
        Livewire::test('pages::files.versions-dialog')->dispatch('versions-node', id: $node->id)->assertForbidden();

        $this->actingAs($this->user);
        Livewire::test('pages::files.versions-dialog')->dispatch('versions-node', id: $folder->id)->assertNotFound();
    });

    it('can only restore a version of the file it is open for', function () {
        $a = fileWithHistory($this->user, ['one', 'second']);
        $other = storedFile($this->user, 'other.txt', 'x');
        $this->user->forceFill(['used_bytes' => $this->user->fresh()->used_bytes + 1])->save();
        replaceWith('other.txt', 'y y')->assertOk();
        $foreign = $other->fresh()->versions()->first();

        Livewire::test('pages::files.versions-dialog')->dispatch('versions-node', id: $a->id)->call('restore', $foreign->id)->assertNotFound();

        expect(NodeVersion::whereKey($foreign->id)->exists())->toBeTrue()->and($other->fresh()->sha256)->toBe(hash('sha256', 'y y'));
    });

    it('shows the menu item for files you can change', function () {
        $node = fileWithHistory($this->user, ['one', 'second']);

        Livewire::test('pages::files.browser')->assertSeeHtml('data-test="versions-menu"');
    });
});
