<?php

use App\Enums\Permission;
use App\Models\Node;
use App\Models\Share;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->node = Node::factory()->for($this->user, 'owner')->create(['name' => 'Taxes']);
});

describe('share dialog', function () {
    it('creates a link and lists its URL', function () {
        $component = Livewire::test('pages::files.share-dialog')
            ->call('open', $this->node->id)
            ->set('linkPassword', 'hunter2')
            ->set('expiry', '7d')
            ->set('allowDownload', false)
            ->call('createLink')
            ->assertHasNoErrors();

        $share = Share::sole();

        expect($share->node_id)->toBe($this->node->id)
            ->and($share->hasPassword())->toBeTrue()
            ->and($share->allow_download)->toBeFalse()
            ->and($share->expires_at->isBetween(now()->addDays(6), now()->addDays(8)))->toBeTrue();

        $component->assertSee($share->url())->assertSet('linkPassword', '');
    });

    it('revokes a link, but only one of this node', function () {
        $share = Share::factory()->create(['node_id' => $this->node->id]);
        $foreign = Share::factory()->create();

        Livewire::test('pages::files.share-dialog')
            ->call('open', $this->node->id)
            ->call('revokeLink', $foreign->id)
            ->assertNotFound();

        Livewire::test('pages::files.share-dialog')
            ->call('open', $this->node->id)
            ->call('revokeLink', $share->id)
            ->assertDontSee($share->url());

        expect($share->fresh()->isActive())->toBeFalse()->and($foreign->fresh()->isActive())->toBeTrue();
    });

    it('rejects an unknown expiry value', function () {
        Livewire::test('pages::files.share-dialog')
            ->call('open', $this->node->id)
            ->set('expiry', 'forever')
            ->call('createLink')
            ->assertHasErrors('expiry');

        expect(Share::count())->toBe(0);
    });

    it('shares with a person, shows them and removes them', function () {
        $friend = User::factory()->create(['email' => 'friend@example.com', 'name' => 'Frida Friend']);

        Livewire::test('pages::files.share-dialog')
            ->call('open', $this->node->id)
            ->set('email', 'friend@example.com')
            ->set('permission', 'edit')
            ->call('shareWithUser')
            ->assertHasNoErrors()
            ->assertSee('Frida Friend')
            ->call('removeUser', $friend->id)
            ->assertDontSee('Frida Friend');

        expect($this->node->sharedWith()->count())->toBe(0);
    });

    it('shows errors for unknown e-mails and bad permissions', function () {
        Livewire::test('pages::files.share-dialog')
            ->call('open', $this->node->id)
            ->set('email', 'nobody@example.com')
            ->call('shareWithUser')
            ->assertHasErrors('email')
            ->set('permission', 'owner')
            ->call('shareWithUser')
            ->assertHasErrors('permission');
    });

    it('only opens for nodes the user may share', function () {
        $theirs = Node::factory()->create();
        $shared = Node::factory()->create();
        $shared->sharedWith()->attach($this->user, ['permission' => Permission::Edit->value]);

        Livewire::test('pages::files.share-dialog')->call('open', $theirs->id)->assertForbidden();
        Livewire::test('pages::files.share-dialog')->call('open', $shared->id)->assertForbidden();
    });

    it('cannot be tricked into acting on a node it was not opened for', function () {
        $theirs = Node::factory()->create();

        Livewire::test('pages::files.share-dialog')
            ->call('open', $this->node->id)
            ->set('nodeId', $theirs->id)
            ->call('createLink')
            ->assertForbidden();

        expect(Share::count())->toBe(0);
    });

    it('is offered in the row menu only to owners', function () {
        $shared = Node::factory()->create(['name' => 'Theirs']);
        $shared->sharedWith()->attach($this->user, ['permission' => Permission::Edit->value]);

        $this->get(route('files'))->assertSee("share-node', { id: {$this->node->id} }", false);
        $this->get(route('files', $shared))->assertDontSee("share-node', { id:", false);
    });
});

describe('shared with me', function () {
    it('lists what others shared, not trashed items or own files', function () {
        $other = User::factory()->create(['name' => 'Olivia Owner']);
        $folder = Node::factory()->for($other, 'owner')->create(['name' => 'Team folder']);
        $file = storedFile($other, 'budget.txt', 'x');
        $gone = Node::factory()->for($other, 'owner')->trashed()->create(['name' => 'Binned']);

        foreach ([$folder, $file, $gone] as $node) {
            $node->sharedWith()->attach($this->user, ['permission' => Permission::View->value]);
        }

        $this->get(route('shared'))
            ->assertOk()
            ->assertSee('Team folder')
            ->assertSee('budget.txt')
            ->assertSee('Olivia Owner')
            ->assertSee(route('files', $folder))
            ->assertDontSee('Binned')
            ->assertDontSee('Taxes');
    });

    it('lets the recipient browse a shared folder', function () {
        $other = User::factory()->create();
        $folder = Node::factory()->for($other, 'owner')->create(['name' => 'Team folder']);
        Node::factory()->inside($folder)->create(['name' => 'Inner thing']);
        $folder->sharedWith()->attach($this->user, ['permission' => Permission::View->value]);

        $this->get(route('files', $folder))
            ->assertOk()
            ->assertSee('Inner thing')
            ->assertSee('Shared with me');
    });

    it('requires login', function () {
        auth()->logout();

        $this->get(route('shared'))->assertRedirect(route('login'));
    });
});

describe('share badge in the file browser', function () {
    it('marks items shared by link, upload link or with people, and nothing else', function () {
        $plain = Node::factory()->for($this->user, 'owner')->create(['name' => 'Plain']);
        $linked = Node::factory()->for($this->user, 'owner')->create(['name' => 'Linked']);
        $dropbox = Node::factory()->for($this->user, 'owner')->create(['name' => 'Dropbox']);
        $people = Node::factory()->for($this->user, 'owner')->create(['name' => 'People']);
        $revoked = Node::factory()->for($this->user, 'owner')->create(['name' => 'Revoked']);

        Share::factory()->create(['node_id' => $linked->id]);
        Share::factory()->dropbox()->create(['node_id' => $dropbox->id]);
        Share::factory()->revoked()->create(['node_id' => $revoked->id]);
        $people->sharedWith()->attach(User::factory()->create(), ['permission' => 'view']);

        $sharing = Livewire::test('pages::files.browser')->instance()->sharing;

        expect($sharing)->toHaveKeys([$linked->id, $dropbox->id, $people->id])
            ->and($sharing)->not->toHaveKeys([$plain->id, $revoked->id, $this->node->id])
            ->and($sharing[$linked->id])->toBe(['link' => true, 'upload' => false, 'people' => 0])
            ->and($sharing[$dropbox->id])->toBe(['link' => false, 'upload' => true, 'people' => 0])
            ->and($sharing[$people->id])->toBe(['link' => false, 'upload' => false, 'people' => 1]);
    });

    it('renders the badge and refreshes it when the dialog changes a share', function () {
        $component = Livewire::test('pages::files.browser')->assertDontSee('data-test="share-badge"', false);

        Share::factory()->create(['node_id' => $this->node->id]);

        $component->dispatch('shares-changed')->assertSee('data-test="share-badge"', false)->assertSee('Shared by link');
    });
});
