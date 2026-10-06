<?php

use App\Actions\Nodes\CreateFolder;
use App\Actions\Nodes\MoveNode;
use App\Actions\Nodes\RenameNode;
use App\Actions\Nodes\RestoreNode;
use App\Actions\Nodes\TrashNode;
use App\Actions\Sharing\CreateShareLink;
use App\Actions\Sharing\RevokeShareLink;
use App\Actions\Sharing\ShareWithUser;
use App\Actions\Sharing\UnshareWithUser;
use App\Enums\ActivityAction;
use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Share;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Olivia Owner']);
    $this->actingAs($this->user);
});

function lastActivity(): Activity
{
    return Activity::latest('id')->firstOrFail();
}

describe('recording', function () {
    it('logs folder and file actions with the owner, actor and name', function () {
        $folder = app(CreateFolder::class)->handle($this->user, null, 'Docs');
        expect(lastActivity()->action)->toBe(ActivityAction::CreatedFolder)
            ->and(lastActivity()->owner_id)->toBe($this->user->id)
            ->and(lastActivity()->actor_id)->toBe($this->user->id)
            ->and(lastActivity()->node_id)->toBe($folder->id)
            ->and(lastActivity()->node_name)->toBe('Docs');

        app(RenameNode::class)->handle($this->user, $folder, 'Documents');
        expect(lastActivity()->action)->toBe(ActivityAction::Renamed)
            ->and(lastActivity()->node_name)->toBe('Documents')
            ->and(lastActivity()->meta)->toBe(['from' => 'Docs']);

        $target = app(CreateFolder::class)->handle($this->user, null, 'Archive');
        app(MoveNode::class)->handle($this->user, $folder, $target);
        expect(lastActivity()->action)->toBe(ActivityAction::Moved)->and(lastActivity()->meta)->toBe(['to' => 'Archive']);

        app(TrashNode::class)->handle($this->user, $folder);
        expect(lastActivity()->action)->toBe(ActivityAction::Trashed);

        app(RestoreNode::class)->handle($this->user, $folder);
        expect(lastActivity()->action)->toBe(ActivityAction::Restored);
    });

    it('does not log a rename to the same name or a move to the same place', function () {
        $folder = app(CreateFolder::class)->handle($this->user, null, 'Docs');
        $before = Activity::count();

        app(RenameNode::class)->handle($this->user, $folder, 'Docs');
        app(MoveNode::class)->handle($this->user, $folder, null);

        expect(Activity::count())->toBe($before);
    });

    it('logs uploads', function () {
        $id = test()->postJson(route('uploads.store'), ['path' => 'a.txt', 'size' => 3])->json('id');
        test()->call('PATCH', route('uploads.update', $id), [], [], [], ['HTTP_UPLOAD_OFFSET' => 0, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream'], 'abc')->assertOk();

        expect(lastActivity()->action)->toBe(ActivityAction::Uploaded)
            ->and(lastActivity()->node_name)->toBe('a.txt')
            ->and(lastActivity()->meta)->toBe(['size' => 3]);
    });

    it('logs sharing, with the owner as owner even when an editor acts', function () {
        $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Team']);
        $friend = User::factory()->create(['email' => 'f@example.com', 'name' => 'Frida']);

        app(ShareWithUser::class)->handle($this->user, $folder, 'f@example.com', Permission::Edit);
        expect(lastActivity()->action)->toBe(ActivityAction::Shared)->and(lastActivity()->meta)->toBe(['user' => 'Frida', 'permission' => 'edit']);

        app(CreateFolder::class)->handle($friend, $folder, 'By Frida');
        expect(lastActivity()->owner_id)->toBe($this->user->id)->and(lastActivity()->actor_id)->toBe($friend->id);

        app(UnshareWithUser::class)->handle($this->user, $folder, $friend);
        expect(lastActivity()->action)->toBe(ActivityAction::Unshared);

        $before = Activity::count();
        app(UnshareWithUser::class)->handle($this->user, $folder, $friend);
        expect(Activity::count())->toBe($before);

        $share = app(CreateShareLink::class)->handle($this->user, $folder);
        expect(lastActivity()->action)->toBe(ActivityAction::LinkCreated)->and(lastActivity()->meta)->toBe(['share_id' => $share->id]);

        app(RevokeShareLink::class)->handle($this->user, $share);
        expect(lastActivity()->action)->toBe(ActivityAction::LinkRevoked);
    });

    it('keeps the name when the node is deleted and the entry when the actor is', function () {
        $actor = User::factory()->create();
        $node = Node::factory()->for($this->user, 'owner')->create(['name' => 'Ephemeral']);
        $node->sharedWith()->attach($actor, ['permission' => 'edit']);
        app(RenameNode::class)->handle($actor, $node, 'Gone soon');

        $node->delete();
        $actor->delete();

        $entry = lastActivity()->load('actor');
        expect($entry->node_id)->toBeNull()
            ->and($entry->node_name)->toBe('Gone soon')
            ->and($entry->actor_id)->toBeNull()
            ->and($entry->sentence($this->user))->toBe('Someone renamed Ephemeral to Gone soon');
    });
});

describe('downloads', function () {
    it('logs a collaborator downloading, but not the owner', function () {
        $file = storedFile($this->user, 'plan.txt', 'content');
        $friend = User::factory()->create();
        $file->sharedWith()->attach($friend, ['permission' => 'view']);

        $this->get(route('nodes.download', $file))->assertOk();
        expect(Activity::where('action', 'downloaded')->count())->toBe(0);

        $this->actingAs($friend);
        $this->get(route('nodes.download', $file))->assertOk();

        expect(lastActivity()->action)->toBe(ActivityAction::Downloaded)
            ->and(lastActivity()->actor_id)->toBe($friend->id)
            ->and(lastActivity()->owner_id)->toBe($this->user->id);
    });

    it('logs only the start of a download, not later ranges', function () {
        $file = storedFile($this->user, 'plan.txt', 'content');
        $friend = User::factory()->create();
        $file->sharedWith()->attach($friend, ['permission' => 'view']);
        $this->actingAs($friend);

        $this->get(route('nodes.download', $file), ['Range' => 'bytes=3-'])->assertStatus(206);
        expect(Activity::where('action', 'downloaded')->count())->toBe(0);

        $this->get(route('nodes.download', $file), ['Range' => 'bytes=0-1'])->assertStatus(206);
        expect(Activity::where('action', 'downloaded')->count())->toBe(1);
    });

    it('logs a collaborator downloading a folder as ZIP', function () {
        $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Pics']);
        $friend = User::factory()->create();
        $folder->sharedWith()->attach($friend, ['permission' => 'view']);

        $this->actingAs($friend)->get(route('nodes.zip', $folder))->assertOk();

        expect(lastActivity()->action)->toBe(ActivityAction::Downloaded)->and(lastActivity()->node_name)->toBe('Pics');
    });

    it('logs guests downloading through a share link, without an actor', function () {
        auth()->logout();
        $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Public']);
        $file = storedFile($this->user, 'flyer.txt', 'flyer text', $folder);
        $share = Share::factory()->create(['node_id' => $folder->id]);

        $this->get(route('share.download', [$share->token, $file->id]))->assertOk();
        expect(lastActivity()->action)->toBe(ActivityAction::LinkDownloaded)
            ->and(lastActivity()->actor_id)->toBeNull()
            ->and(lastActivity()->owner_id)->toBe($this->user->id)
            ->and(lastActivity()->meta)->toBe(['share_id' => $share->id]);

        $this->get(route('share.zip', $share->token))->assertOk();
        expect(lastActivity()->node_name)->toBe('Public');

        $before = Activity::count();
        $this->get(route('share.preview', [$share->token, $file->id]))->assertOk();
        $this->get(route('share.download', [$share->token, $file->id]), ['Range' => 'bytes=1-'])->assertStatus(206);
        expect(Activity::count())->toBe($before);
    });
});

describe('sentences', function () {
    it('reads naturally for the viewer and others', function (ActivityAction $action, array $meta, string $mine, string $theirs) {
        $other = User::factory()->create(['name' => 'Frida']);
        $activity = (new Activity)->forceFill(['node_name' => 'a.txt', 'meta' => $meta]);
        $activity->action = $action;
        $activity->actor_id = $this->user->id;
        $activity->setRelation('actor', $this->user);

        expect($activity->sentence($this->user))->toBe($mine)
            ->and($activity->sentence($other))->toBe(str_replace('You ', 'Olivia Owner ', $mine));
    })->with([
        [ActivityAction::Uploaded, [], 'You uploaded a.txt', ''],
        [ActivityAction::Renamed, ['from' => 'b.txt'], 'You renamed b.txt to a.txt', ''],
        [ActivityAction::Moved, ['to' => 'Archive'], 'You moved a.txt to Archive', ''],
        [ActivityAction::Trashed, [], 'You moved a.txt to the trash', ''],
        [ActivityAction::Shared, ['user' => 'Frida', 'permission' => 'edit'], 'You shared a.txt with Frida (can edit)', ''],
        [ActivityAction::LinkCreated, [], 'You created a share link for a.txt', ''],
    ]);

    it('says someone downloaded through a share link', function () {
        $activity = (new Activity)->forceFill(['node_name' => 'a.txt']);
        $activity->action = ActivityAction::LinkDownloaded;

        expect($activity->sentence($this->user))->toBe('Someone downloaded a.txt through a share link');
    });
});

describe('page', function () {
    it('shows what happened to my files and what I did, newest first, and nothing else', function () {
        $mine = Node::factory()->for($this->user, 'owner')->create(['name' => 'Mine']);
        $theirs = Node::factory()->create(['name' => 'Theirs']);
        $guest = User::factory()->create(['name' => 'Frida']);
        $mine->sharedWith()->attach($guest, ['permission' => 'edit']);

        app(RenameNode::class)->handle($this->user, $mine, 'Mine renamed');
        app(CreateFolder::class)->handle($guest, $mine, 'By guest');
        $theirs->sharedWith()->attach($this->user, ['permission' => 'edit']);
        app(CreateFolder::class)->handle($this->user, $theirs, 'My doing elsewhere');
        app(RenameNode::class)->handle($theirs->owner, $theirs, 'Theirs renamed');

        $this->get(route('activity'))
            ->assertOk()
            ->assertSeeInOrder(['Frida created the folder By guest', 'You renamed Mine to Mine renamed'])
            ->assertSee('You created the folder My doing elsewhere')
            ->assertDontSee('Theirs renamed');
    });

    it('is empty at first and requires login', function () {
        $this->get(route('activity'))->assertOk()->assertSee('Nothing has happened yet');

        auth()->logout();
        $this->get(route('activity'))->assertRedirect(route('login'));
    });

    it('pages through long logs', function () {
        $folder = Node::factory()->for($this->user, 'owner')->create();
        foreach (range(1, 35) as $i) {
            Activity::unguarded(fn () => Activity::create([
                'owner_id' => $this->user->id, 'actor_id' => $this->user->id, 'node_id' => $folder->id,
                'node_name' => "item {$i}", 'action' => 'trashed', 'created_at' => now()->subMinutes(100 - $i),
            ]));
        }

        Livewire::test('pages::files.activity')
            ->assertSee('item 35')
            ->assertDontSee('item 1 ')
            ->call('nextPage')
            ->assertSee('item 1')
            ->assertDontSee('item 35');
    });
});

it('prunes entries older than the retention period', function () {
    $node = Node::factory()->for($this->user, 'owner')->create();
    Activity::unguarded(fn () => Activity::create([
        'owner_id' => $this->user->id, 'node_id' => $node->id, 'node_name' => 'old', 'action' => 'trashed', 'created_at' => now()->subDays(91),
    ]));
    Activity::unguarded(fn () => Activity::create([
        'owner_id' => $this->user->id, 'node_id' => $node->id, 'node_name' => 'recent', 'action' => 'trashed', 'created_at' => now()->subDays(89),
    ]));

    $this->artisan('activity:prune')->assertSuccessful();

    expect(Activity::pluck('node_name')->all())->toBe(['recent']);
});
