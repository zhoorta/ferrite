<?php

use App\Actions\Sharing\CreateShareLink;
use App\Actions\Sharing\RevokeShareLink;
use App\Actions\Sharing\ShareWithUser;
use App\Actions\Sharing\UnshareWithUser;
use App\Enums\Permission;
use App\Models\Node;
use App\Models\Share;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->node = Node::factory()->for($this->owner, 'owner')->create();
});

describe('share links', function () {
    it('creates a link with a long random token', function () {
        $a = app(CreateShareLink::class)->handle($this->owner, $this->node);
        $b = app(CreateShareLink::class)->handle($this->owner, $this->node);

        expect($a->token)->toHaveLength(40)->not->toBe($b->token)
            ->and($a->created_by)->toBe($this->owner->id)
            ->and($a->allow_download)->toBeTrue()
            ->and($a->hasPassword())->toBeFalse()
            ->and($a->isActive())->toBeTrue();
    });

    it('stores a hashed password, an expiry and the download flag', function () {
        $share = app(CreateShareLink::class)->handle($this->owner, $this->node, 'hunter2', now()->addDay(), false);

        expect($share->password_hash)->not->toBe('hunter2')
            ->and(Hash::check('hunter2', $share->password_hash))->toBeTrue()
            ->and($share->expires_at->isFuture())->toBeTrue()
            ->and($share->allow_download)->toBeFalse()
            ->and($share->toArray())->not->toHaveKey('password_hash');
    });

    it('treats an empty password as none', function () {
        expect(app(CreateShareLink::class)->handle($this->owner, $this->node, '')->hasPassword())->toBeFalse();
    });

    it('rejects an expiry in the past', function () {
        app(CreateShareLink::class)->handle($this->owner, $this->node, null, now()->subMinute());
    })->throws(ValidationException::class);

    it('is limited to the owner, even for people with edit access', function () {
        $editor = User::factory()->create();
        $this->node->sharedWith()->attach($editor, ['permission' => Permission::Edit->value]);

        app(CreateShareLink::class)->handle($editor, $this->node);
    })->throws(AuthorizationException::class);

    it('cannot share a trashed node', function () {
        $this->node->forceFill(['trashed_at' => now()])->save();

        app(CreateShareLink::class)->handle($this->owner, $this->node);
    })->throws(AuthorizationException::class);

    it('revokes a link, and only for the owner', function () {
        $share = Share::factory()->create(['node_id' => $this->node->id]);

        expect(fn () => app(RevokeShareLink::class)->handle(User::factory()->create(), $share))
            ->toThrow(AuthorizationException::class);

        app(RevokeShareLink::class)->handle($this->owner, $share);

        expect($share->fresh()->isActive())->toBeFalse()
            ->and(Share::active()->count())->toBe(0);
    });

    it('lists only active links in the active scope', function () {
        Share::factory()->create(['node_id' => $this->node->id]);
        Share::factory()->expired()->create(['node_id' => $this->node->id]);
        Share::factory()->revoked()->create(['node_id' => $this->node->id]);
        Share::factory()->create(['node_id' => $this->node->id, 'expires_at' => now()->addHour()]);

        expect(Share::active()->count())->toBe(2);
    });

    it('is removed with the node', function () {
        Share::factory()->create(['node_id' => $this->node->id]);

        $this->node->delete();

        expect(Share::count())->toBe(0);
    });
});

describe('sharing with users', function () {
    beforeEach(function () {
        $this->friend = User::factory()->create(['email' => 'Friend@Example.com']);
    });

    it('shares by e-mail, ignoring case', function () {
        app(ShareWithUser::class)->handle($this->owner, $this->node, ' friend@example.COM ', Permission::View);

        expect($this->friend->can('view', $this->node))->toBeTrue()
            ->and($this->friend->can('update', $this->node))->toBeFalse();
    });

    it('changes the permission instead of adding a second share', function () {
        app(ShareWithUser::class)->handle($this->owner, $this->node, 'friend@example.com', Permission::View);
        app(ShareWithUser::class)->handle($this->owner, $this->node, 'friend@example.com', Permission::Edit);

        expect($this->node->sharedWith()->count())->toBe(1)
            ->and($this->friend->can('update', $this->node))->toBeTrue();
    });

    it('rejects unknown users and yourself', function (string $email) {
        app(ShareWithUser::class)->handle($this->owner, $this->node, $email, Permission::View);
    })->with(['nobody@example.com'])->throws(ValidationException::class);

    it('rejects sharing with yourself', function () {
        app(ShareWithUser::class)->handle($this->owner, $this->node, $this->owner->email, Permission::View);
    })->throws(ValidationException::class);

    it('is limited to the owner', function () {
        $editor = User::factory()->create();
        $this->node->sharedWith()->attach($editor, ['permission' => Permission::Edit->value]);

        app(ShareWithUser::class)->handle($editor, $this->node, 'friend@example.com', Permission::View);
    })->throws(AuthorizationException::class);

    it('removes access', function () {
        app(ShareWithUser::class)->handle($this->owner, $this->node, 'friend@example.com', Permission::Edit);

        app(UnshareWithUser::class)->handle($this->owner, $this->node, $this->friend);

        expect($this->friend->can('view', $this->node))->toBeFalse();
    });
});
