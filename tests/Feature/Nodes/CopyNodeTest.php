<?php

use App\Actions\Nodes\CopyNode;
use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\Node;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['quota_bytes' => null]);
    $this->actingAs($this->user);
});

it('copies a file into a folder sharing the blob and charging the quota', function () {
    $dest = Node::factory()->for($this->user, 'owner')->create(['name' => 'Archive']);
    $file = storedFile($this->user, 'a.txt', 'hello');

    $copy = app(CopyNode::class)->handle($this->user, $file, $dest);

    expect($copy->id)->not->toBe($file->id)
        ->and($copy->parent_id)->toBe($dest->id)
        ->and($copy->name)->toBe('a.txt')
        ->and($copy->path)->toBe($file->path)
        ->and($copy->size)->toBe(5)
        ->and($this->user->fresh()->used_bytes)->toBe(5)
        ->and($file->fresh()->parent_id)->toBeNull();
});

it('numbers the copy when the name is taken in the same folder', function () {
    $file = storedFile($this->user, 'a.txt', 'hello');

    $first = app(CopyNode::class)->handle($this->user, $file, null);
    $second = app(CopyNode::class)->handle($this->user, $file, null);

    expect($first->name)->toBe('a (2).txt')->and($second->name)->toBe('a (3).txt');
});

it('copies a folder with its contents but not what is in the trash', function () {
    $root = Node::factory()->for($this->user, 'owner')->create(['name' => 'Project']);
    $sub = Node::factory()->inside($root)->create(['name' => 'docs']);
    storedFile($this->user, 'one.txt', 'one', $root);
    storedFile($this->user, 'two.txt', 'twotwo', $sub);
    storedFile($this->user, 'gone.txt', 'x', $root)->forceFill(['trashed_at' => now()])->save();

    $copy = app(CopyNode::class)->handle($this->user, $root, null);

    expect($copy->name)->toBe('Project (2)');

    $names = Node::query()->where('parent_id', $copy->id)->orderBy('name')->pluck('name')->all();
    $copiedSub = Node::query()->where('parent_id', $copy->id)->where('name', 'docs')->first();

    expect($names)->toBe(['docs', 'one.txt'])
        ->and(Node::query()->where('parent_id', $copiedSub->id)->pluck('name')->all())->toBe(['two.txt'])
        ->and($this->user->fresh()->used_bytes)->toBe(9);
});

it('refuses to copy a folder into itself or below itself', function () {
    $root = Node::factory()->for($this->user, 'owner')->create();
    $sub = Node::factory()->inside($root)->create();

    expect(fn () => app(CopyNode::class)->handle($this->user, $root, $root))->toThrow(ValidationException::class)
        ->and(fn () => app(CopyNode::class)->handle($this->user, $root, $sub))->toThrow(ValidationException::class);
});

it('refuses when the quota would be exceeded and leaves nothing behind', function () {
    $this->user->forceFill(['quota_bytes' => 8, 'used_bytes' => 5])->save();
    $file = storedFile($this->user, 'a.txt', 'hello');

    expect(fn () => app(CopyNode::class)->handle($this->user, $file, null))->toThrow(ValidationException::class);

    expect(Node::query()->where('owner_id', $this->user->id)->count())->toBe(1);
});

it('gives a copy made from a shared file its own blob, owned by the destination owner', function () {
    $other = User::factory()->create();
    $shared = storedFile($other, 'theirs.txt', 'secret', null);
    $shared->sharedWith()->attach($this->user, ['permission' => 'view']);
    $mine = Node::factory()->for($this->user, 'owner')->create(['name' => 'Mine']);

    $copy = app(CopyNode::class)->handle($this->user, $shared, $mine);

    expect($copy->owner_id)->toBe($this->user->id)
        ->and($copy->path)->not->toBe($shared->path)
        ->and($this->user->fresh()->used_bytes)->toBe(6)
        ->and($other->fresh()->used_bytes)->toBe(0);

    $filesystem = app(App\Support\StorageManager::class)->filesystem($copy->disk);
    expect($filesystem->get($copy->path))->toBe('secret');
});

it('does not let someone copy what they cannot see', function () {
    $theirs = storedFile(User::factory()->create(), 'theirs.txt', 'x');

    expect(fn () => app(CopyNode::class)->handle($this->user, $theirs, null))->toThrow(AuthorizationException::class);
});

it('does not copy into a folder the user cannot edit', function () {
    $other = User::factory()->create();
    $folder = Node::factory()->for($other, 'owner')->create();
    $folder->sharedWith()->attach($this->user, ['permission' => 'view']);
    $file = storedFile($this->user, 'a.txt', 'x');

    expect(fn () => app(CopyNode::class)->handle($this->user, $file, $folder))->toThrow(AuthorizationException::class);
});

it('logs the copy', function () {
    $dest = Node::factory()->for($this->user, 'owner')->create(['name' => 'Archive']);
    $file = storedFile($this->user, 'a.txt', 'x');

    app(CopyNode::class)->handle($this->user, $file, $dest);

    $activity = Activity::query()->latest('id')->first();
    expect($activity->action)->toBe(ActivityAction::Copied)
        ->and($activity->sentence($this->user))->toBe('You copied a.txt to Archive');
});

it('copies from the browser dialog, single and selected', function () {
    $dest = Node::factory()->for($this->user, 'owner')->create(['name' => 'Dest']);
    $a = storedFile($this->user, 'a.txt', 'a');
    $b = storedFile($this->user, 'b.txt', 'b');

    Livewire::test('pages::files.browser')
        ->call('startCopy', $a->id)
        ->assertSet('moveMode', 'copy')
        ->call('browseMove', $dest->id)
        ->call('copy')
        ->set('selected', [$b->id])
        ->call('startCopySelection')
        ->call('browseMove', $dest->id)
        ->call('copy')
        ->assertSet('selected', []);

    expect(Node::query()->where('parent_id', $dest->id)->orderBy('name')->pluck('name')->all())->toBe(['a.txt', 'b.txt'])
        ->and(Node::query()->whereNull('parent_id')->where('name', 'a.txt')->exists())->toBeTrue();
});
