<?php

use App\Actions\Storage\DeleteStorageDisk;
use App\Actions\Storage\SaveStorageDisk;
use App\Actions\Users\SaveUser;
use App\Enums\Permission;
use App\Models\Node;
use App\Models\Share;
use App\Models\StorageDisk;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->default = app(StorageManager::class)->default();
    $this->other = app(SaveStorageDisk::class)->handle($this->admin, null, ['name' => 'other', 'driver' => 'local', 'config' => ['root' => $this->storageBase.'/other']]);
});

/** Upload $content as $name in one request, as $user. */
function uploadAs(User $user, string $name, string $content, ?int $parentId = null): void
{
    test()->actingAs($user);

    $id = test()->postJson(route('uploads.store'), ['path' => $name, 'size' => strlen($content), 'parent_id' => $parentId])->assertCreated()->json('id');

    test()->call('PATCH', route('uploads.update', $id), [], [], [], ['HTTP_UPLOAD_OFFSET' => 0, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream'], $content)->assertOk();
}

describe('where an upload lands', function () {
    it('uses the default disk when the user has none assigned', function () {
        $user = User::factory()->create();

        uploadAs($user, 'a.txt', 'hello');

        expect(Node::firstWhere('name', 'a.txt')->disk_id)->toBe($this->default->id);
    });

    it('uses the assigned disk, and follows a change of the default only for those without one', function () {
        $user = User::factory()->create(['disk_id' => $this->other->id]);
        $plain = User::factory()->create();

        uploadAs($user, 'a.txt', 'hello');
        uploadAs($plain, 'b.txt', 'hello');

        expect(Node::firstWhere('name', 'a.txt')->disk_id)->toBe($this->other->id)
            ->and(Node::firstWhere('name', 'b.txt')->disk_id)->toBe($this->default->id);
    });

    it('puts a file uploaded into someone else\'s shared folder on the folder owner\'s disk', function () {
        $owner = User::factory()->create(['disk_id' => $this->other->id]);
        $guest = User::factory()->create();
        $folder = Node::factory()->for($owner, 'owner')->create(['name' => 'Shared']);
        $folder->sharedWith()->attach($guest, ['permission' => Permission::Edit->value]);

        uploadAs($guest, 'in.txt', 'hello', $folder->id);

        $node = Node::firstWhere('name', 'in.txt');

        expect($node->owner_id)->toBe($owner->id)
            ->and($node->disk_id)->toBe($this->other->id);
    });

    it('puts a drop-box upload on the folder owner\'s disk', function () {
        $owner = User::factory()->create(['disk_id' => $this->other->id]);
        $folder = Node::factory()->for($owner, 'owner')->create(['name' => 'Inbox']);
        $share = Share::factory()->dropbox()->create(['node_id' => $folder->id]);

        auth()->logout();
        $id = test()->postJson(route('share.uploads.store', $share->token), ['path' => 'drop.txt', 'size' => 5])->assertCreated()->json('id');
        test()->call('PATCH', route('share.uploads.update', [$share->token, $id]), [], [], [], ['HTTP_UPLOAD_OFFSET' => 0, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream'], 'hello')->assertOk();

        expect(Node::firstWhere('name', 'drop.txt')->disk_id)->toBe($this->other->id);
    });

    it('does not reuse a blob from another disk for identical content', function () {
        $user = User::factory()->create();

        uploadAs($user, 'one.txt', 'same content');
        $user->forceFill(['disk_id' => $this->other->id])->save();
        uploadAs($user, 'two.txt', 'same content');

        $one = Node::firstWhere('name', 'one.txt');
        $two = Node::firstWhere('name', 'two.txt');

        expect($one->disk_id)->toBe($this->default->id)
            ->and($two->disk_id)->toBe($this->other->id)
            ->and($two->path)->not->toBe($one->path);
    });

    it('falls back to the default when the assigned disk is gone', function () {
        $user = User::factory()->create(['disk_id' => $this->other->id]);
        $this->other->delete();

        uploadAs($user, 'a.txt', 'hello');

        expect($user->fresh()->disk_id)->toBeNull()
            ->and(Node::firstWhere('name', 'a.txt')->disk_id)->toBe($this->default->id);
    });
});

describe('assigning a disk', function () {
    it('is saved by an admin and cleared with a blank value', function () {
        $user = User::factory()->create();

        app(SaveUser::class)->handle($this->admin, $user, ['name' => $user->name, 'email' => $user->email, 'role' => 'user', 'disk_id' => (string) $this->other->id]);
        expect($user->fresh()->disk_id)->toBe($this->other->id);

        app(SaveUser::class)->handle($this->admin, $user->fresh(), ['name' => $user->name, 'email' => $user->email, 'role' => 'user', 'disk_id' => '']);
        expect($user->fresh()->disk_id)->toBeNull();
    });

    it('is left alone when the data has no disk', function () {
        $user = User::factory()->create(['disk_id' => $this->other->id]);

        app(SaveUser::class)->save($user, ['name' => 'New', 'email' => $user->email, 'role' => 'user']);

        expect($user->fresh()->disk_id)->toBe($this->other->id);
    });

    it('rejects a disk that does not exist', function () {
        $user = User::factory()->create();

        expect(fn () => app(SaveUser::class)->handle($this->admin, $user, ['name' => 'x', 'email' => $user->email, 'role' => 'user', 'disk_id' => '9999']))->toThrow(ValidationException::class);
    });

    it('is chosen in the admin users page', function () {
        $user = User::factory()->create();
        $this->actingAs($this->admin);

        Livewire::test('pages::admin.users')
            ->call('edit', $user->id)
            ->assertSee('Storage disk')
            ->set('diskId', (string) $this->other->id)
            ->call('save')
            ->assertHasNoErrors();

        expect($user->fresh()->disk_id)->toBe($this->other->id);
    });

    it('keeps a disk assigned to users from being removed', function () {
        User::factory()->create(['disk_id' => $this->other->id]);

        expect(fn () => app(DeleteStorageDisk::class)->handle($this->admin, $this->other))->toThrow(ValidationException::class)
            ->and(StorageDisk::count())->toBe(2);
    });
});
