<?php

use App\Models\Node;
use App\Models\User;
use Illuminate\Database\QueryException;

it('lists a node and its ancestors, nearest first', function () {
    $root = Node::factory()->create();
    $child = Node::factory()->inside($root)->create();
    $grandchild = Node::factory()->inside($child)->create();

    expect($grandchild->selfAndAncestorIds())->toBe([$grandchild->id, $child->id, $root->id]);
});

it('treats descendants of a trashed folder as trashed', function () {
    $root = Node::factory()->create();
    $child = Node::factory()->inside($root)->create();

    expect($child->isTrashed())->toBeFalse();

    $root->forceFill(['trashed_at' => now()])->save();

    expect($child->fresh()->isTrashed())->toBeTrue();
});

it('rejects duplicate names in the same folder', function () {
    $owner = User::factory()->create();
    $parent = Node::factory()->for($owner, 'owner')->create();

    Node::factory()->inside($parent)->create(['name' => 'Report']);
    Node::factory()->inside($parent)->create(['name' => 'Report']);
})->throws(QueryException::class);

it('rejects duplicate names at the root of one owner', function () {
    $owner = User::factory()->create();

    Node::factory()->for($owner, 'owner')->create(['name' => 'Docs']);
    Node::factory()->for($owner, 'owner')->create(['name' => 'Docs']);
})->throws(QueryException::class);

it('allows the same name for different owners, in other folders, or once trashed', function () {
    $owner = User::factory()->create();
    $other = Node::factory()->for($owner, 'owner')->create(['name' => 'Docs']);

    Node::factory()->create(['name' => 'Docs']);
    Node::factory()->inside(Node::factory()->for($owner, 'owner')->create())->create(['name' => 'Docs']);
    Node::factory()->for($owner, 'owner')->trashed()->create(['name' => 'Docs']);
    Node::factory()->for($owner, 'owner')->trashed()->create(['name' => 'Docs']);

    expect(Node::where('name', 'Docs')->count())->toBe(5);
});

it('cascades deletes from a user to their nodes', function () {
    $node = Node::factory()->create();

    $node->owner->delete();

    expect(Node::count())->toBe(0);
});
