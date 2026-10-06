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
