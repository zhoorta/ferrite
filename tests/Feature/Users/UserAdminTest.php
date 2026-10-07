<?php

use App\Actions\Users\DeleteUser;
use App\Actions\Users\SaveUser;
use App\Actions\Users\SetUserDisabled;
use App\Enums\UserRole;
use App\Models\Node;
use App\Models\Share;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->actingAs($this->admin);
});

function userData(array $override = []): array
{
    return [...['name' => 'Nina New', 'email' => 'nina@example.com', 'password' => 'a-good-password', 'role' => 'user', 'quota_gb' => '2.5'], ...$override];
}

describe('saving', function () {
    it('creates a verified user with a quota in bytes and a hashed password', function () {
        $user = app(SaveUser::class)->handle($this->admin, null, userData());

        expect($user->exists)->toBeTrue()
            ->and($user->role)->toBe(UserRole::User)
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->quota_bytes)->toBe((int) (2.5 * 1024 ** 3))
            ->and(Hash::check('a-good-password', $user->password))->toBeTrue()
            ->and($user->disabled_at)->toBeNull();
    });

    it('treats an empty quota as unlimited and can make admins', function () {
        $user = app(SaveUser::class)->handle($this->admin, null, userData(['quota_gb' => '', 'role' => 'admin']));

        expect($user->quota_bytes)->toBeNull()->and($user->isAdmin())->toBeTrue();
    });

    it('validates input', function (array $override) {
        User::factory()->create(['email' => 'taken@example.com']);

        expect(fn () => app(SaveUser::class)->handle($this->admin, null, userData($override)))->toThrow(ValidationException::class);
    })->with([
        'duplicate email' => [['email' => 'taken@example.com']],
        'bad email' => [['email' => 'nope']],
        'no name' => [['name' => '']],
        'no password' => [['password' => '']],
        'bad role' => [['role' => 'owner']],
        'negative quota' => [['quota_gb' => '-1']],
        'text quota' => [['quota_gb' => 'lots']],
    ]);

    it('updates a user, keeping the password when left blank', function () {
        $user = app(SaveUser::class)->handle($this->admin, null, userData());
        $hash = $user->password;

        app(SaveUser::class)->handle($this->admin, $user, userData(['name' => 'Nina Renamed', 'password' => '', 'quota_gb' => '']));

        $user->refresh();
        expect($user->name)->toBe('Nina Renamed')->and($user->password)->toBe($hash)->and($user->quota_bytes)->toBeNull();

        app(SaveUser::class)->handle($this->admin, $user, userData(['password' => 'another-password']));
        expect(Hash::check('another-password', $user->fresh()->password))->toBeTrue();
    });

    it('lets a user keep their own e-mail but not take another\'s', function () {
        $user = app(SaveUser::class)->handle($this->admin, null, userData());

        app(SaveUser::class)->handle($this->admin, $user, userData(['password' => '']));
        expect(fn () => app(SaveUser::class)->handle($this->admin, $user, userData(['email' => $this->admin->email, 'password' => ''])))
            ->toThrow(ValidationException::class);
    });

    it('does not let an admin remove their own admin role', function () {
        app(SaveUser::class)->handle($this->admin, $this->admin, userData(['email' => $this->admin->email, 'role' => 'user', 'password' => '']));
    })->throws(ValidationException::class);

    it('is for admins only', function () {
        app(SaveUser::class)->handle(User::factory()->create(), null, userData());
    })->throws(AuthorizationException::class);
});

describe('disabling', function () {
    it('disables and re-enables, refusing to disable yourself', function () {
        $user = User::factory()->create();

        app(SetUserDisabled::class)->handle($this->admin, $user, true);
        expect($user->fresh()->isDisabled())->toBeTrue();

        app(SetUserDisabled::class)->handle($this->admin, $user, false);
        expect($user->fresh()->isDisabled())->toBeFalse();

        expect(fn () => app(SetUserDisabled::class)->handle($this->admin, $this->admin, true))->toThrow(ValidationException::class);
    });

    it('drops the user\'s stored sessions at once', function () {
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 's2', 'user_id' => $this->admin->id, 'payload' => '', 'last_activity' => time()]);

        app(SetUserDisabled::class)->handle($this->admin, $user, true);

        expect(DB::table('sessions')->pluck('id')->all())->toBe(['s2']);
    });

    it('signs a disabled user out on their next request', function () {
        $user = User::factory()->create();
        app(SetUserDisabled::class)->handle($this->admin, $user, true);

        $this->actingAs($user)->get(route('files'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'This account is disabled.');

        $this->assertGuest();
    });

    it('refuses a disabled user\'s API-style requests', function () {
        $user = User::factory()->create(['disabled_at' => now()]);

        $this->actingAs($user)->postJson(route('uploads.store'), ['path' => 'a', 'size' => 1])->assertForbidden();
    });

    it('does not affect share links the user made', function () {
        $user = User::factory()->create();
        $file = storedFile($user, 'a.txt', 'abc');
        $share = Share::factory()->create(['node_id' => $file->id]);
        app(SetUserDisabled::class)->handle($this->admin, $user, true);
        auth()->logout();

        $this->get(route('share.download', [$share->token, $file->id]))->assertOk();
    });
});

describe('deleting', function () {
    it('removes the user, their files and blobs, but nobody else\'s', function () {
        $user = User::factory()->create();
        $folder = Node::factory()->for($user, 'owner')->create();
        $mine = storedFile($user, 'mine.txt', 'mine', $folder);
        $keep = storedFile($this->admin, 'keep.txt', 'keep');

        app(DeleteUser::class)->handle($this->admin, $user);

        expect(User::find($user->id))->toBeNull()
            ->and(Node::pluck('id')->all())->toBe([$keep->id])
            ->and(File::allFiles(config('ferrite.local_root')))->toHaveCount(1)
            ->and(app(StorageManager::class)->filesystem($keep->disk)->exists($keep->path))->toBeTrue()
            ->and($mine->path)->not->toBeNull();
    });

    it('refuses to delete yourself, and is for admins only', function () {
        expect(fn () => app(DeleteUser::class)->handle($this->admin, $this->admin))->toThrow(ValidationException::class)
            ->and(fn () => app(DeleteUser::class)->handle(User::factory()->create(), $this->admin))->toThrow(AuthorizationException::class);
    });
});

describe('page', function () {
    it('is for admins only and linked for them', function () {
        $this->get(route('admin.users'))->assertOk()->assertSee('Ada Admin');
        $this->get(route('files'))->assertSee(route('admin.users'));

        $this->actingAs(User::factory()->create());
        $this->get(route('admin.users'))->assertForbidden();
        $this->get(route('files'))->assertDontSee(route('admin.users'));
    });

    it('adds, edits, disables and deletes', function () {
        $component = Livewire::test('pages::admin.users')
            ->call('add')
            ->set('name', 'Nina New')
            ->set('email', 'nina@example.com')
            ->set('password', 'a-good-password')
            ->set('quotaGb', '1')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Nina New');

        $nina = User::firstWhere('email', 'nina@example.com');

        $component->call('edit', $nina->id)->assertSet('quotaGb', '1')->assertSet('password', '')
            ->set('quotaGb', '')->call('save')->assertHasNoErrors();
        expect($nina->fresh()->quota_bytes)->toBeNull();

        $component->call('toggleDisabled', $nina->id)->assertSee('Disabled');
        expect($nina->fresh()->isDisabled())->toBeTrue();

        $component->call('delete', $nina->id)->assertDontSee('Nina New');
        expect(User::find($nina->id))->toBeNull();
    });

    it('shows validation errors', function () {
        Livewire::test('pages::admin.users')->call('add')->call('save')->assertHasErrors(['name', 'email', 'password']);
    });

    it('does not offer actions on yourself, and rejects them if forced', function () {
        Livewire::test('pages::admin.users')
            ->call('toggleDisabled', $this->admin->id)->assertHasErrors('user')
            ->call('delete', $this->admin->id)->assertHasErrors('user');

        expect($this->admin->fresh()->isDisabled())->toBeFalse();
    });
});
