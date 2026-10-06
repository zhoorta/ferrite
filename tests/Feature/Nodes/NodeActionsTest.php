<?php

use App\Actions\Nodes\CreateFolder;
use App\Actions\Nodes\MoveNode;
use App\Actions\Nodes\RenameNode;
use App\Actions\Nodes\RestoreNode;
use App\Actions\Nodes\TrashNode;
use App\Enums\Permission;
use App\Models\Node;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function folder(User $owner, string $name, ?Node $parent = null): Node
{
    $node = Node::factory()->for($owner, 'owner')->create(['name' => $name, 'parent_id' => $parent?->id]);

    return $node;
}

describe('CreateFolder', function () {
    it('creates a folder at the root and inside a folder', function () {
        $root = app(CreateFolder::class)->handle($this->user, null, '  Docs ');
        $child = app(CreateFolder::class)->handle($this->user, $root, 'Taxes');

        expect($root->name)->toBe('Docs')
            ->and($root->owner_id)->toBe($this->user->id)
            ->and($child->parent_id)->toBe($root->id);
    });

    it('rejects bad and duplicate names', function (string $name) {
        folder($this->user, 'Docs');

        app(CreateFolder::class)->handle($this->user, null, $name);
    })->with(['', '   ', '.', '..', 'a/b', 'a\\b', "tab\there", 'docs', str_repeat('a', 256)])
        ->throws(ValidationException::class);

    it('creates inside a folder shared with edit, owned by the folder owner', function () {
        $other = User::factory()->create();
        $shared = folder($other, 'Shared');
        $shared->sharedWith()->attach($this->user, ['permission' => Permission::Edit->value]);

        $folder = app(CreateFolder::class)->handle($this->user, $shared, 'Mine');

        expect($folder->owner_id)->toBe($other->id);
    });

    it('refuses inside a folder the user cannot edit', function () {
        $shared = folder(User::factory()->create(), 'Shared');
        $shared->sharedWith()->attach($this->user, ['permission' => Permission::View->value]);

        app(CreateFolder::class)->handle($this->user, $shared, 'Nope');
    })->throws(AuthorizationException::class);

    it('refuses inside a trashed folder', function () {
        $trashed = folder($this->user, 'Old');
        app(TrashNode::class)->handle($this->user, $trashed);

        app(CreateFolder::class)->handle($this->user, $trashed, 'New');
    })->throws(ValidationException::class);
});

describe('RenameNode', function () {
    it('renames, allowing a case change of itself', function () {
        $node = folder($this->user, 'docs');

        app(RenameNode::class)->handle($this->user, $node, 'Docs');

        expect($node->fresh()->name)->toBe('Docs');
    });

    it('rejects a name taken by a sibling', function () {
        folder($this->user, 'A');
        $b = folder($this->user, 'B');

        app(RenameNode::class)->handle($this->user, $b, 'a');
    })->throws(ValidationException::class);

    it('refuses strangers', function () {
        $node = folder($this->user, 'A');

        app(RenameNode::class)->handle(User::factory()->create(), $node, 'B');
    })->throws(AuthorizationException::class);
});

describe('MoveNode', function () {
    it('moves into a folder and back to the root', function () {
        $a = folder($this->user, 'A');
        $b = folder($this->user, 'B');

        app(MoveNode::class)->handle($this->user, $b, $a);
        expect($b->fresh()->parent_id)->toBe($a->id);

        app(MoveNode::class)->handle($this->user, $b, null);
        expect($b->fresh()->parent_id)->toBeNull();
    });

    it('rejects moving a folder into itself or a descendant', function () {
        $a = folder($this->user, 'A');
        $b = folder($this->user, 'B', $a);
        $c = folder($this->user, 'C', $b);

        foreach ([$a, $c] as $destination) {
            expect(fn () => app(MoveNode::class)->handle($this->user, $a, $destination))
                ->toThrow(ValidationException::class);
        }
    });

    it('rejects a name collision at the destination', function () {
        $target = folder($this->user, 'Target');
        folder($this->user, 'Same', $target);
        $moving = folder($this->user, 'same');

        app(MoveNode::class)->handle($this->user, $moving, $target);
    })->throws(ValidationException::class);

    it('rejects a file as destination', function () {
        $node = folder($this->user, 'A');
        $file = Node::factory()->file()->for($this->user, 'owner')->create();

        app(MoveNode::class)->handle($this->user, $node, $file);
    })->throws(ValidationException::class);

    it('refuses a trashed destination', function () {
        $node = folder($this->user, 'A');
        $trashed = folder($this->user, 'T');
        app(TrashNode::class)->handle($this->user, $trashed);

        app(MoveNode::class)->handle($this->user, $node, $trashed);
    })->throws(AuthorizationException::class);

    it('refuses to move into someone else\'s folder', function () {
        $node = folder($this->user, 'A');
        $theirs = folder(User::factory()->create(), 'Theirs');

        app(MoveNode::class)->handle($this->user, $node, $theirs);
    })->throws(AuthorizationException::class);
});

describe('TrashNode and RestoreNode', function () {
    it('trashes only the node and restores it in place', function () {
        $a = folder($this->user, 'A');
        $b = folder($this->user, 'B', $a);

        app(TrashNode::class)->handle($this->user, $a);

        expect($a->fresh()->trashed_at)->not->toBeNull()
            ->and($b->fresh()->trashed_at)->toBeNull()
            ->and($b->fresh()->isTrashed())->toBeTrue();

        app(RestoreNode::class)->handle($this->user, $a);

        expect($a->fresh()->trashed_at)->toBeNull()
            ->and($b->fresh()->isTrashed())->toBeFalse();
    });

    it('lets a name be reused while trashed and renames on a restore collision', function () {
        $old = folder($this->user, 'Report');
        app(TrashNode::class)->handle($this->user, $old);
        folder($this->user, 'Report');

        app(RestoreNode::class)->handle($this->user, $old);

        expect($old->fresh()->name)->toBe('Report (2)');
    });

    it('keeps the extension when renaming a restored file', function () {
        $file = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'a.tar.gz']);
        app(TrashNode::class)->handle($this->user, $file);
        Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'a.tar.gz']);

        app(RestoreNode::class)->handle($this->user, $file);

        expect($file->fresh()->name)->toBe('a.tar (2).gz');
    });

    it('restores to the root when the parent is trashed', function () {
        $a = folder($this->user, 'A');
        $b = folder($this->user, 'B', $a);
        app(TrashNode::class)->handle($this->user, $b);
        app(TrashNode::class)->handle($this->user, $a);

        app(RestoreNode::class)->handle($this->user, $b);

        expect($b->fresh()->parent_id)->toBeNull()
            ->and($b->fresh()->isTrashed())->toBeFalse();
    });

    it('lists only top-level trashed nodes', function () {
        $a = folder($this->user, 'A');
        $b = folder($this->user, 'B', $a);
        $c = folder($this->user, 'C');
        $other = folder(User::factory()->create(), 'Other');

        foreach ([$b, $a, $c] as $node) {
            app(TrashNode::class)->handle($this->user, $node);
        }
        app(TrashNode::class)->handle($other->owner, $other);

        expect(Node::trashRootIds($this->user))->toEqualCanonicalizing([$a->id, $c->id]);
    });

    it('refuses a share recipient trashing or restoring', function () {
        $node = folder(User::factory()->create(), 'A');
        $node->sharedWith()->attach($this->user, ['permission' => Permission::Edit->value]);

        app(TrashNode::class)->handle($this->user, $node);
    })->throws(AuthorizationException::class);
});
