<?php

use App\Enums\Permission;
use App\Models\Node;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('requires login', function () {
    auth()->logout();

    $this->get(route('files'))->assertRedirect(route('login'));
    $this->get(route('trash'))->assertRedirect(route('login'));
});

it('lists the root with folders first', function () {
    Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'a.txt']);
    Node::factory()->for($this->user, 'owner')->create(['name' => 'Zeta']);
    Node::factory()->create(['name' => 'Not mine']);
    Node::factory()->for($this->user, 'owner')->trashed()->create(['name' => 'Gone']);

    $this->get(route('files'))
        ->assertOk()
        ->assertSeeInOrder(['Zeta', 'a.txt'])
        ->assertDontSee('Not mine')
        ->assertDontSee('Gone');
});

it('shows the contents of a folder', function () {
    $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Work']);
    Node::factory()->inside($folder)->create(['name' => 'Inner']);

    $this->get(route('files', $folder))->assertOk()->assertSee('Inner');
});

it('forbids and hides other people\'s and trashed folders', function () {
    $theirs = Node::factory()->create();
    $trashed = Node::factory()->for($this->user, 'owner')->trashed()->create();
    $file = Node::factory()->file()->for($this->user, 'owner')->create();

    $this->get(route('files', $theirs))->assertForbidden();
    $this->get(route('files', $trashed))->assertNotFound();
    $this->get(route('files', $file))->assertNotFound();
});

it('opens a folder shared with the user', function () {
    $shared = Node::factory()->create(['name' => 'Shared']);
    $shared->sharedWith()->attach($this->user, ['permission' => Permission::View->value]);

    $this->get(route('files', $shared))->assertOk();
});

it('creates a folder', function () {
    Livewire::test('pages::files.browser')
        ->set('name', 'Photos')
        ->call('createFolder')
        ->assertHasNoErrors()
        ->assertSee('Photos');

    expect(Node::where('name', 'Photos')->whereNull('parent_id')->count())->toBe(1);
});

it('shows a validation error for a duplicate folder name', function () {
    Node::factory()->for($this->user, 'owner')->create(['name' => 'Photos']);

    Livewire::test('pages::files.browser')
        ->set('name', 'photos')
        ->call('createFolder')
        ->assertHasErrors('name');
});

it('renames a node', function () {
    $node = Node::factory()->for($this->user, 'owner')->create(['name' => 'Old']);

    Livewire::test('pages::files.browser')
        ->call('startRename', $node->id)
        ->assertSet('name', 'Old')
        ->set('name', 'New')
        ->call('rename')
        ->assertHasNoErrors();

    expect($node->fresh()->name)->toBe('New');
});

it('moves a node through the picker', function () {
    $target = Node::factory()->for($this->user, 'owner')->create(['name' => 'Target']);
    $node = Node::factory()->for($this->user, 'owner')->create(['name' => 'Moving']);

    Livewire::test('pages::files.browser')
        ->call('startMove', $node->id)
        ->assertSee('Target')
        ->call('browseMove', $target->id)
        ->call('move')
        ->assertHasNoErrors();

    expect($node->fresh()->parent_id)->toBe($target->id);
});

it('moves a node by dropping it on a folder, and back to the root', function () {
    $target = Node::factory()->for($this->user, 'owner')->create(['name' => 'Target']);
    $node = Node::factory()->for($this->user, 'owner')->create(['name' => 'Moving']);

    Livewire::test('pages::files.browser')->call('dropMove', $node->id, $target->id);
    expect($node->fresh()->parent_id)->toBe($target->id);

    Livewire::test('pages::files.browser')->call('dropMove', $node->id, null);
    expect($node->fresh()->parent_id)->toBeNull();
});

it('ignores a drop on itself and reports a name clash instead of failing', function () {
    $target = Node::factory()->for($this->user, 'owner')->create(['name' => 'Target']);
    $node = Node::factory()->for($this->user, 'owner')->create(['name' => 'Same']);
    Node::factory()->for($this->user, 'owner')->create(['name' => 'Same', 'parent_id' => $target->id]);

    Livewire::test('pages::files.browser')
        ->call('dropMove', $target->id, $target->id)
        ->call('dropMove', $node->id, $target->id)
        ->assertHasNoErrors();

    expect($node->fresh()->parent_id)->toBeNull();
});

it('trashes a node and restores it from the trash', function () {
    $node = Node::factory()->for($this->user, 'owner')->create(['name' => 'Doomed']);

    Livewire::test('pages::files.browser')->call('trash', $node->id)->assertDontSee('Doomed');

    Livewire::test('pages::files.trash')->assertSee('Doomed')->call('restore', $node->id)->assertDontSee('Doomed');

    expect($node->fresh()->trashed_at)->toBeNull();
});

it('does not let users act on other people\'s nodes', function () {
    $theirs = Node::factory()->create();

    Livewire::test('pages::files.browser')->call('trash', $theirs->id)->assertForbidden();
    Livewire::test('pages::files.browser')->call('startRename', $theirs->id)->assertForbidden();
    Livewire::test('pages::files.browser')->call('startMove', $theirs->id)->assertForbidden();
    Livewire::test('pages::files.trash')->call('restore', $theirs->id)->assertForbidden();
});

it('previews text files and offers a download for the rest', function () {
    $text = storedFile($this->user, 'notes.txt', 'remember the milk');
    $binary = storedFile($this->user, 'app.exe', 'MZ', mime: 'application/x-msdownload');

    Livewire::test('pages::files.browser')
        ->call('preview', $text->id)
        ->assertSet('previewId', $text->id)
        ->assertSee('remember the milk')
        ->assertSee(route('nodes.download', $text))
        ->call('closePreview')
        ->assertSet('previewId', null)
        ->call('preview', $binary->id)
        ->assertSee('No preview available')
        ->assertDontSee('MZ');
});

it('escapes text in the preview', function () {
    $node = storedFile($this->user, 'x.txt', '<script>alert(1)</script>');

    Livewire::test('pages::files.browser')
        ->call('preview', $node->id)
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('only previews files the user may view', function () {
    $theirs = storedFile(User::factory()->create(), 'secret.txt', 'secret');
    $trashed = storedFile($this->user, 'old.txt', 'old');
    $trashed->forceFill(['trashed_at' => now()])->save();

    Livewire::test('pages::files.browser')->call('preview', $theirs->id)->assertForbidden();
    Livewire::test('pages::files.browser')->call('preview', $trashed->id)->assertNotFound();
});

it('links downloads, ZIPs and thumbnails from the list', function () {
    $folder = Node::factory()->for($this->user, 'owner')->create();
    $image = storedFile($this->user, 'p.png', 'x', mime: 'image/png');
    $file = storedFile($this->user, 'a.txt', 'x');

    $this->get(route('files'))
        ->assertSee(route('nodes.zip', $folder))
        ->assertSee(route('nodes.download', $file))
        ->assertSee(route('nodes.thumbnail', $image))
        ->assertDontSee(route('nodes.thumbnail', $file));
});

it('shows a ".." row that leads to the parent folder, but not at the root', function () {
    $parent = Node::factory()->for($this->user, 'owner')->create(['name' => 'Work']);
    $child = Node::factory()->inside($parent)->create(['name' => 'Inner']);

    $this->get(route('files'))->assertOk()->assertDontSee('data-test="up-row"', false);

    $this->get(route('files', $parent))->assertOk()->assertSee('data-href="'.route('files').'"', false);
    $this->get(route('files', $child))->assertOk()->assertSee('data-href="'.route('files', $parent).'"', false)->assertSee('This folder is empty');
});

it('switches between list and grid view', function () {
    Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'a.txt']);

    Livewire::test('pages::files.browser')
        ->assertDontSeeHtml('data-test="grid"')
        ->call('setView', 'grid')
        ->assertSeeHtml('data-test="grid"')
        ->assertSee('a.txt')
        ->call('setView', 'nonsense')
        ->assertDontSeeHtml('data-test="grid"');
});

it('steps through the files of the folder in the preview', function () {
    $folder = Node::factory()->for($this->user, 'owner')->create();
    Node::factory()->inside($folder)->create(['name' => 'Subfolder']);
    $a = storedFile($this->user, 'a.txt', 'a', $folder);
    $b = storedFile($this->user, 'b.txt', 'b', $folder);

    Livewire::test('pages::files.browser', ['folder' => $folder])
        ->call('preview', $a->id)
        ->assertSet('previewId', $a->id)
        ->call('previewStep', -1)
        ->assertSet('previewId', $a->id)
        ->call('previewStep', 1)
        ->assertSet('previewId', $b->id)
        ->assertSee('2 / 2')
        ->call('previewStep', 1)
        ->assertSet('previewId', $b->id);
});

it('points the ".." card of the grid at the parent folder', function () {
    $parent = Node::factory()->for($this->user, 'owner')->create();
    $child = Node::factory()->inside($parent)->create();

    Livewire::test('pages::files.browser', ['folder' => $child])
        ->call('setView', 'grid')
        ->assertSeeHtml('data-href="'.route('files', $parent).'"');
});

it('moves the ticked items to the trash in one go', function () {
    $a = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'a.txt']);
    $b = Node::factory()->for($this->user, 'owner')->create(['name' => 'B']);
    $keep = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'keep.txt']);

    Livewire::test('pages::files.browser')
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->assertSee('2 selected')
        ->call('trashSelection')
        ->assertSet('selected', []);

    expect($a->fresh()->isTrashed())->toBeTrue()
        ->and($b->fresh()->isTrashed())->toBeTrue()
        ->and($keep->fresh()->isTrashed())->toBeFalse();
});

it('ignores ticked ids that are not listed in the folder', function () {
    $other = Node::factory()->file()->create(['name' => 'theirs.txt']);

    Livewire::test('pages::files.browser')->set('selected', [$other->id])->call('trashSelection');

    expect($other->fresh()->isTrashed())->toBeFalse();
});

it('moves the ticked items into a folder from the move dialog', function () {
    $target = Node::factory()->for($this->user, 'owner')->create(['name' => 'Target']);
    $a = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'a.txt']);
    $b = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'b.txt']);

    Livewire::test('pages::files.browser')
        ->set('selected', [$a->id, $b->id])
        ->call('startMoveSelection')
        ->call('browseMove', $target->id)
        ->call('move')
        ->assertSet('selected', []);

    expect($a->fresh()->parent_id)->toBe($target->id)
        ->and($b->fresh()->parent_id)->toBe($target->id);
});

it('moves what it can and reports the name clashes', function () {
    $target = Node::factory()->for($this->user, 'owner')->create(['name' => 'Target']);
    Node::factory()->file()->inside($target)->create(['name' => 'a.txt']);
    $a = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'a.txt']);
    $b = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'b.txt']);

    Livewire::test('pages::files.browser')
        ->set('selected', [$a->id, $b->id])
        ->call('startMoveSelection')
        ->call('browseMove', $target->id)
        ->call('move');

    expect($a->fresh()->parent_id)->toBeNull()
        ->and($b->fresh()->parent_id)->toBe($target->id);
});

it('drags every ticked row along with the dragged one', function () {
    $target = Node::factory()->for($this->user, 'owner')->create(['name' => 'Target']);
    $a = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'a.txt']);
    $b = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'b.txt']);
    $c = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'c.txt']);

    Livewire::test('pages::files.browser')
        ->set('selected', [$a->id, $b->id])
        ->call('dropMove', $a->id, $target->id);

    expect($a->fresh()->parent_id)->toBe($target->id)
        ->and($b->fresh()->parent_id)->toBe($target->id)
        ->and($c->fresh()->parent_id)->toBeNull();
});

it('selects and clears everything', function () {
    Node::factory()->file()->for($this->user, 'owner')->count(3)->create();

    Livewire::test('pages::files.browser')
        ->call('toggleAll')
        ->assertCount('selected', 3)
        ->call('toggleAll')
        ->assertSet('selected', []);
});

it('loads a folder a page at a time', function () {
    foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
        Node::factory()->file()->for($this->user, 'owner')->create(['name' => "{$name}.txt"]);
    }

    Livewire::test('pages::files.browser')
        ->set('limit', 2)
        ->assertSee('a.txt')->assertSee('b.txt')->assertDontSee('c.txt')
        ->assertSeeHtml('data-test="load-more"')
        ->call('loadMore')
        ->assertSee('c.txt')
        ->assertDontSeeHtml('data-test="load-more"');
});
