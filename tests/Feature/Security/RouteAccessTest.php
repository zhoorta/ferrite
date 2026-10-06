<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Routes a visitor without an account may reach. Everything else must be behind login. A new route
 * that is not listed here and is not protected makes this test fail, which is the point.
 */
const PUBLIC_ROUTES = [
    '/',
    'up',
    'login',
    'register',
    'forgot-password',
    'reset-password',
    'reset-password/*',
    'two-factor-challenge',
    'passkeys/login',
    'passkeys/login/options',
    '.well-known/passkey-endpoints',
    's/*',
];

/** Assets and framework endpoints with their own protection (signed URLs, static files). */
const FRAMEWORK_PREFIXES = ['flux/', 'livewire-'];

function nonPublicRoutes(): array
{
    $found = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (Str::is(PUBLIC_ROUTES, $uri) || Str::startsWith($uri, FRAMEWORK_PREFIXES)) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (! in_array($method, ['HEAD', 'OPTIONS', 'QUERY'], true)) {
                $found[] = [$method, '/'.preg_replace('/\{[^}]+\}/', '1', str_replace('?}', '}', $uri))];
            }
        }
    }

    return $found;
}

it('finds the routes it is supposed to guard', function () {
    $routes = collect(nonPublicRoutes())->map(fn ($r) => $r[1]);

    expect($routes)->toContain('/files/1', '/uploads', '/nodes/1/download', '/admin/users', '/admin/storage', '/trash', '/activity')
        ->and($routes->count())->toBeGreaterThan(30);
});

it('keeps every non-public route behind login', function () {
    $open = [];

    foreach (nonPublicRoutes() as [$method, $path]) {
        $response = $this->call($method, $path);

        $redirectsToLogin = $response->isRedirect() && str_ends_with((string) $response->headers->get('Location'), '/login');
        $refused = in_array($response->status(), [401, 403, 404, 405, 419], true);

        if (! $redirectsToLogin && ! $refused) {
            $open[] = "{$method} {$path} -> {$response->status()}";
        }
    }

    expect($open)->toBe([]);
});

it('does not let a non-admin into the admin screens or actions', function () {
    $user = User::factory()->create();

    foreach (['/admin/users', '/admin/storage'] as $path) {
        $this->actingAs($user)->get($path)->assertForbidden();
    }
});

it('does not let an unverified user in', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('files'))->assertRedirect(route('verification.notice'));
    $this->actingAs($user)->postJson(route('uploads.store'), ['path' => 'a', 'size' => 1])->assertForbidden();
});
