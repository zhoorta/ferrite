<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;

afterEach(fn () => TrustProxies::flushState());

function applyTrustedProxies(?string $setting): void
{
    config(['ferrite.trusted_proxies' => $setting]);
    (new AppServiceProvider(app()))->boot();
}

it('ignores X-Forwarded headers unless proxies are trusted', function () {
    $this->get('http://localhost/login', ['X-Forwarded-Proto' => 'https'])
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('believes the forwarded scheme from a trusted proxy', function () {
    applyTrustedProxies('*');

    $this->get('http://localhost/login', ['X-Forwarded-Proto' => 'https'])
        ->assertHeader('Strict-Transport-Security');
});

it('accepts a list of proxies and only trusts those', function () {
    applyTrustedProxies('10.0.0.1, 10.0.0.2');

    // The test client connects from 127.0.0.1, which is not in the list.
    $this->get('http://localhost/login', ['X-Forwarded-Proto' => 'https'])
        ->assertHeaderMissing('Strict-Transport-Security');

    applyTrustedProxies('10.0.0.1, 127.0.0.1');

    $this->get('http://localhost/login', ['X-Forwarded-Proto' => 'https'])
        ->assertHeader('Strict-Transport-Security');
});
