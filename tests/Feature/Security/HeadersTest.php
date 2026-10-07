<?php

use App\Models\Share;
use App\Models\User;

it('sends baseline security headers on pages', function () {
    $response = $this->get(route('login'))->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('adds HSTS only on https', function () {
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=15552000');
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
});

it('does not override headers a controller set', function () {
    $user = User::factory()->create();
    $file = storedFile($user, 'a.txt', 'abc');
    $share = Share::factory()->create(['node_id' => $file->id]);

    $this->get(route('share.show', $share->token))->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get(route('share.download', [$share->token, $file->id]))
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('covers file responses and error pages too', function () {
    $this->get('/definitely-not-here')->assertNotFound()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

it('does not expose Laravel\'s signed /storage routes', function () {
    $this->get('/storage/ferrite/anything')->assertNotFound();
    $this->put('/storage/ferrite/anything')->assertNotFound();
});
