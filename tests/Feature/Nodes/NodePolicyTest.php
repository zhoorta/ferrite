<?php

use App\Enums\Permission;
use App\Models\Node;
use App\Models\User;

function share(Node $node, User $user, Permission $permission): void
{
    $node->sharedWith()->attach($user, ['permission' => $permission->value]);
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->folder = Node::factory()->for($this->owner, 'owner')->create();
    $this->file = Node::factory()->file()->inside($this->folder)->create();
});

it('gives the owner full access', function () {
    expect($this->owner->can('view', $this->file))->toBeTrue()
        ->and($this->owner->can('update', $this->file))->toBeTrue()
        ->and($this->owner->can('share', $this->file))->toBeTrue()
        ->and($this->owner->can('forceDelete', $this->file))->toBeTrue();
});

it('denies strangers, including admins', function () {
    $admin = User::factory()->admin()->create();

    foreach ([$this->other, $admin] as $user) {
        expect($user->can('view', $this->file))->toBeFalse()
            ->and($user->can('update', $this->file))->toBeFalse();
    }
});

it('grants view through a share on an ancestor', function () {
    share($this->folder, $this->other, Permission::View);

    expect($this->other->can('view', $this->file))->toBeTrue()
        ->and($this->other->can('update', $this->file))->toBeFalse()
        ->and($this->other->can('share', $this->file))->toBeFalse()
        ->and($this->other->can('forceDelete', $this->file))->toBeFalse();
});

it('grants edit through a share on an ancestor', function () {
    share($this->folder, $this->other, Permission::Edit);

    expect($this->other->can('update', $this->file))->toBeTrue()
        ->and($this->other->can('share', $this->file))->toBeFalse();
});

it('does not grant access to siblings or parents of a shared node', function () {
    share($this->file, $this->other, Permission::Edit);
    $sibling = Node::factory()->file()->inside($this->folder)->create();

    expect($this->other->can('view', $sibling))->toBeFalse()
        ->and($this->other->can('view', $this->folder))->toBeFalse();
});

it('hides trashed nodes from share recipients but not from the owner', function () {
    share($this->folder, $this->other, Permission::Edit);
    $this->folder->forceFill(['trashed_at' => now()])->save();

    expect($this->other->can('view', $this->file))->toBeFalse()
        ->and($this->other->can('update', $this->file))->toBeFalse()
        ->and($this->owner->can('view', $this->file))->toBeTrue()
        ->and($this->owner->can('restore', $this->folder))->toBeTrue()
        ->and($this->other->can('restore', $this->folder))->toBeFalse();
});
