<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a verified user from options', function () {
    $this->artisan('ferrite:user', ['email' => 'ana@example.com', '--password' => 'a-good-password', '--quota' => '5'])
        ->expectsOutputToContain('Created ana@example.com (user, quota 5 GB)')
        ->assertSuccessful();

    $user = User::firstWhere('email', 'ana@example.com');

    expect($user->name)->toBe('ana')
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->quota_bytes)->toBe(5 * 1024 ** 3)
        ->and(Hash::check('a-good-password', $user->password))->toBeTrue();
});

it('creates an admin with a name', function () {
    $this->artisan('ferrite:user', ['email' => 'boss@example.com', '--password' => 'a-good-password', '--admin' => true, '--name' => 'The Boss'])
        ->assertSuccessful();

    $user = User::firstWhere('email', 'boss@example.com');
    expect($user->isAdmin())->toBeTrue()->and($user->name)->toBe('The Boss')->and($user->quota_bytes)->toBeNull();
});

it('prompts for the password when none is given', function () {
    $this->artisan('ferrite:user', ['email' => 'ana@example.com'])
        ->expectsQuestion('Password', 'a-good-password')
        ->expectsQuestion('Confirm password', 'a-good-password')
        ->assertSuccessful();

    expect(Hash::check('a-good-password', User::sole()->password))->toBeTrue();
});

it('refuses mismatching prompted passwords', function () {
    $this->artisan('ferrite:user', ['email' => 'ana@example.com'])
        ->expectsQuestion('Password', 'a-good-password')
        ->expectsQuestion('Confirm password', 'something-else')
        ->expectsOutputToContain('do not match')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('updates an existing user, changing only what is asked', function () {
    $user = User::factory()->create(['email' => 'Ana@Example.com', 'name' => 'Ana']);
    $hash = $user->password;

    $this->artisan('ferrite:user', ['email' => 'ana@example.com', '--admin' => true, '--quota' => 'unlimited'])
        ->expectsConfirmation('Set a new password?', 'no')
        ->expectsOutputToContain('Updated Ana@Example.com (admin, quota unlimited)')
        ->assertSuccessful();

    $user->refresh();
    expect($user->isAdmin())->toBeTrue()->and($user->password)->toBe($hash)->and($user->name)->toBe('Ana')->and(User::count())->toBe(1);

    $this->artisan('ferrite:user', ['email' => 'ana@example.com', '--user' => true, '--password' => 'brand-new-password'])->assertSuccessful();
    $user->refresh();
    expect($user->isAdmin())->toBeFalse()->and(Hash::check('brand-new-password', $user->password))->toBeTrue();
});

it('can re-enable a disabled account, to recover a locked-out admin', function () {
    $user = User::factory()->admin()->create(['email' => 'ana@example.com', 'disabled_at' => now()]);

    $this->artisan('ferrite:user', ['email' => 'ana@example.com', '--enable' => true])
        ->expectsConfirmation('Set a new password?', 'no')
        ->assertSuccessful();

    expect($user->fresh()->isDisabled())->toBeFalse();
});

it('reports validation problems', function () {
    $this->artisan('ferrite:user', ['email' => 'not-an-email', '--password' => 'a-good-password'])->assertFailed();
    $this->artisan('ferrite:user', ['email' => 'ana@example.com', '--password' => 'a-good-password', '--quota' => 'lots'])->assertFailed();
    $this->artisan('ferrite:user', ['email' => 'ana@example.com', '--password' => 'a-good-password', '--admin' => true, '--user' => true])->assertFailed();

    expect(User::count())->toBe(0);
});

it('needs a password when not interactive', function () {
    $this->artisan('ferrite:user', ['email' => 'ana@example.com', '--no-interaction' => true])
        ->expectsOutputToContain('Pass --password')
        ->assertFailed();
});
