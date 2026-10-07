<?php

use App\Models\User;

function registration(): array
{
    return ['name' => 'Rita', 'email' => 'rita@example.com', 'password' => 'a-good-password', 'password_confirmation' => 'a-good-password'];
}

it('lets the first person set up the instance as a verified admin', function () {
    $this->get(route('register'))->assertOk();

    $this->post(route('register.store'), registration())->assertSessionHasNoErrors();

    $user = User::sole();
    expect($user->isAdmin())->toBeTrue()->and($user->email_verified_at)->not->toBeNull();
});

it('closes registration once there is a user', function () {
    User::factory()->create();

    $this->get(route('register'))->assertNotFound();
    $this->post(route('register.store'), registration())->assertForbidden();
    $this->get(route('login'))->assertOk()->assertDontSee('Sign up');

    expect(User::count())->toBe(1);
});

it('can be opened with FERRITE_REGISTRATION, creating ordinary unverified users', function () {
    User::factory()->create();
    config(['ferrite.registration' => true]);

    $this->get(route('login'))->assertSee('Sign up');
    $this->post(route('register.store'), registration())->assertSessionHasNoErrors();

    $user = User::firstWhere('email', 'rita@example.com');
    expect($user->isAdmin())->toBeFalse()->and($user->email_verified_at)->toBeNull();
});

it('sends the front door to the files', function () {
    $this->get('/')->assertRedirect(route('files'));
});
