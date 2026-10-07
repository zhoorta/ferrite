<?php

use App\Actions\Nodes\EmptyTrash;
use App\Actions\Nodes\PurgeNode;
use App\Actions\Nodes\TrashNode;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Share;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function trashIt(User $user, Node $node): Node
{
    return app(TrashNode::class)->handle($user, $node);
}

function blobExists(Node $node): bool
{
    return app(StorageManager::class)->filesystem($node->disk)->exists($node->path);
}

it('deletes a trashed file, its blob and thumbnail, and frees the quota', function () {
    $file = storedFile($this->user, 'a.txt', 'twelve bytes');
    $this->user->forceFill(['used_bytes' => 100])->save();
    $filesystem = app(StorageManager::class)->filesystem($file->disk);
    $filesystem->put("thumbnails/{$file->path}.jpg", 'thumb');
    $path = $file->path;
    $disk = $file->disk;

    trashIt($this->user, $file);
    app(PurgeNode::class)->handle($this->user, $file);

    expect(Node::count())->toBe(0)
        ->and($filesystem->exists($path))->toBeFalse()
        ->and($filesystem->exists("thumbnails/{$path}.jpg"))->toBeFalse()
        ->and($this->user->fresh()->used_bytes)->toBe(88)
        ->and($disk)->not->toBeNull();
});

it('deletes a folder with everything inside, shares included', function () {
    $folder = Node::factory()->for($this->user, 'owner')->create();
    $sub = Node::factory()->inside($folder)->create();
    $a = storedFile($this->user, 'a.txt', 'aaaa', $folder);
    $b = storedFile($this->user, 'b.txt', 'bb', $sub);
    $keep = storedFile($this->user, 'keep.txt', 'k');
    Share::factory()->create(['node_id' => $folder->id]);
    $this->user->forceFill(['used_bytes' => 7])->save();

    trashIt($this->user, $folder);
    app(PurgeNode::class)->handle($this->user, $folder);

    expect(Node::pluck('id')->all())->toBe([$keep->id])
        ->and(Share::count())->toBe(0)
        ->and(blobExists($keep))->toBeTrue()
        ->and(File::allFiles(config('shed.local_root')))->toHaveCount(1)
        ->and($this->user->fresh()->used_bytes)->toBe(1)
        ->and($a->path)->not->toBeNull();
});

it('never takes quota below zero', function () {
    $file = storedFile($this->user, 'a.txt', 'abc');
    $this->user->forceFill(['used_bytes' => 1])->save();

    trashIt($this->user, $file);
    app(PurgeNode::class)->handle($this->user, $file);

    expect($this->user->fresh()->used_bytes)->toBe(0);
});

it('refuses items that are not in the trash, and other people\'s items', function () {
    $file = storedFile($this->user, 'a.txt', 'abc');

    expect(fn () => app(PurgeNode::class)->handle($this->user, $file))->toThrow(HttpException::class);

    trashIt($this->user, $file);
    expect(fn () => app(PurgeNode::class)->handle(User::factory()->create(), $file))->toThrow(AuthorizationException::class);
    expect(blobExists($file))->toBeTrue();
});

it('keeps the file if its blob cannot be deleted later, but never leaves a row without a blob', function () {
    $file = storedFile($this->user, 'a.txt', 'abc');
    trashIt($this->user, $file);
    // Blob already gone (for example removed by hand): the purge still completes.
    app(StorageManager::class)->filesystem($file->disk)->delete($file->path);

    app(PurgeNode::class)->handle($this->user, $file);

    expect(Node::count())->toBe(0);
});

it('logs the purge with the name', function () {
    $file = storedFile($this->user, 'secret.txt', 'abc');
    trashIt($this->user, $file);

    app(PurgeNode::class)->handle($this->user, $file);

    $entry = Activity::latest('id')->first();
    expect($entry->node_name)->toBe('secret.txt')
        ->and($entry->node_id)->toBeNull()
        ->and($entry->sentence($this->user))->toBe('You permanently deleted secret.txt');
});

it('empties the trash but leaves everything else', function () {
    $a = storedFile($this->user, 'a.txt', 'aaa');
    $b = storedFile($this->user, 'b.txt', 'bbb');
    $keep = storedFile($this->user, 'keep.txt', 'k');
    $other = storedFile(User::factory()->create(), 'theirs.txt', 'x');
    trashIt($this->user, $a);
    trashIt($this->user, $b);
    trashIt($other->owner, $other);

    expect(app(EmptyTrash::class)->handle($this->user))->toBe(2)
        ->and(Node::pluck('name')->sort()->values()->all())->toBe(['keep.txt', 'theirs.txt'])
        ->and(blobExists($keep))->toBeTrue()
        ->and(blobExists($other))->toBeTrue();
});

describe('page', function () {
    it('deletes one item or everything', function () {
        $a = trashIt($this->user, storedFile($this->user, 'a.txt', 'a'));
        $b = trashIt($this->user, storedFile($this->user, 'b.txt', 'b'));
        trashIt($this->user, storedFile($this->user, 'c.txt', 'c'));

        Livewire::test('pages::files.trash')
            ->call('purge', $a->id)
            ->assertDontSee('a.txt')
            ->assertSee('b.txt')
            ->call('emptyTrash')
            ->assertSee('The trash is empty');

        expect(Node::count())->toBe(0);
    });

    it('cannot delete other people\'s items or live items', function () {
        $theirs = trashIt($other = User::factory()->create(), storedFile($other, 'x.txt', 'x'));
        $live = storedFile($this->user, 'live.txt', 'l');

        Livewire::test('pages::files.trash')->call('purge', $theirs->id)->assertForbidden();
        Livewire::test('pages::files.trash')->call('purge', $live->id)->assertStatus(422);

        expect(Node::count())->toBe(2);
    });
});

describe('automatic purge', function () {
    it('deletes items trashed longer than the retention period only', function () {
        $old = storedFile($this->user, 'old.txt', 'old');
        $recent = storedFile($this->user, 'recent.txt', 'recent');
        $live = storedFile($this->user, 'live.txt', 'live');
        $folder = Node::factory()->for($this->user, 'owner')->create(['trashed_at' => now()->subDays(31)]);
        $inside = storedFile($this->user, 'inside.txt', 'in', $folder);
        $old->forceFill(['trashed_at' => now()->subDays(31)])->save();
        $recent->forceFill(['trashed_at' => now()->subDays(29)])->save();
        $this->user->forceFill(['used_bytes' => 100])->save();

        $this->artisan('trash:purge')->assertSuccessful();

        expect(Node::pluck('name')->sort()->values()->all())->toBe(['live.txt', 'recent.txt'])
            ->and(blobExists($recent))->toBeTrue()
            ->and(blobExists($live))->toBeTrue()
            ->and($this->user->fresh()->used_bytes)->toBe(100 - 3 - 2)
            ->and($inside->path)->not->toBeNull();
    });
});

it('keeps a shared blob until its last node is purged', function () {
    $a = storedFile($this->user, 'a.txt', 'same');
    $b = $a->replicate();
    $b->owner_id = $a->owner_id;
    $b->name = 'b.txt';
    $b->save();

    $filesystem = app(StorageManager::class)->filesystem($a->disk);
    $filesystem->put("thumbnails/{$a->path}.jpg", 'thumb');

    app(PurgeNode::class)->handle($this->user, trashIt($this->user, $a));

    expect(blobExists($b))->toBeTrue()
        ->and($filesystem->exists("thumbnails/{$b->path}.jpg"))->toBeTrue();

    app(PurgeNode::class)->handle($this->user, trashIt($this->user, $b));

    expect($filesystem->exists($b->path))->toBeFalse()
        ->and($filesystem->exists("thumbnails/{$b->path}.jpg"))->toBeFalse();
});
