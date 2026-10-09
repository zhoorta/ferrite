<?php

use App\Models\Node;
use App\Models\User;

function apiToken(User $user, ?Node $folder, array $abilities = ['read'], $expires = null): string
{
    $token = $user->createToken('test', $abilities, $expires);
    $token->accessToken->forceFill(['folder_id' => $folder?->id])->save();

    return $token->plainTextToken;
}

function apiGet(string $uri, ?string $token = null)
{
    // The guard keeps the user it resolved for the previous request of the same test.
    app('auth')->forgetGuards();

    return test()->getJson($uri, $token ? ['Authorization' => "Bearer {$token}"] : []);
}

beforeEach(function () {
    $this->user = User::factory()->create(['quota_bytes' => 1000, 'used_bytes' => 10]);
    $this->folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Music']);
});

it('describes the folder, access and quota of the token', function () {
    apiGet('/api/v1/root', apiToken($this->user, $this->folder, ['read', 'write']))
        ->assertOk()
        ->assertJson([
            'name' => 'Music',
            'id' => $this->folder->id,
            'access' => 'read+write',
            'expires_at' => null,
            'quota' => ['used' => 10, 'limit' => 1000],
        ])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

it('names a whole-drive token', function () {
    apiGet('/api/v1/root', apiToken($this->user, null))
        ->assertOk()
        ->assertJson(['name' => 'Whole drive', 'id' => null, 'access' => 'read']);
});

it('records when a token was last used', function () {
    $plain = apiToken($this->user, $this->folder);

    apiGet('/api/v1/root', $plain)->assertOk();

    expect($this->user->tokens()->first()->last_used_at)->not->toBeNull();
});

it('answers 401 without a token, with a wrong one and with a revoked one', function () {
    apiGet('/api/v1/root')->assertUnauthorized();
    apiGet('/api/v1/root', 'nope')->assertUnauthorized();

    $plain = apiToken($this->user, $this->folder);
    $this->user->tokens()->delete();
    $this->app['auth']->forgetGuards();

    apiGet('/api/v1/root', $plain)->assertUnauthorized();
});

it('answers 401 for an expired token', function () {
    apiGet('/api/v1/root', apiToken($this->user, $this->folder, ['read'], now()->subMinute()))->assertUnauthorized();
});

it('does not accept a browser session', function () {
    $this->actingAs($this->user)->getJson('/api/v1/root')->assertUnauthorized();
});

it('answers 403 for a disabled owner', function () {
    $plain = apiToken($this->user, $this->folder);
    $this->user->forceFill(['disabled_at' => now()])->save();

    apiGet('/api/v1/root', $plain)->assertForbidden();
});

it('answers 404 when the folder is trashed, and works again when it is restored', function () {
    $plain = apiToken($this->user, $this->folder);

    $this->folder->forceFill(['trashed_at' => now()])->save();
    apiGet('/api/v1/root', $plain)->assertNotFound();

    $this->folder->forceFill(['trashed_at' => null])->save();
    $this->app['auth']->forgetGuards();
    apiGet('/api/v1/root', $plain)->assertOk();
});

it('answers 404 when the folder is inside a trashed one', function () {
    $inner = Node::factory()->for($this->user, 'owner')->create(['parent_id' => $this->folder->id]);
    $plain = apiToken($this->user, $inner);

    $this->folder->forceFill(['trashed_at' => now()])->save();

    apiGet('/api/v1/root', $plain)->assertNotFound();
});

it('follows the folder when it is renamed or moved', function () {
    $plain = apiToken($this->user, $this->folder);
    $other = Node::factory()->for($this->user, 'owner')->create(['name' => 'Elsewhere']);
    $this->folder->forceFill(['name' => 'Renamed', 'parent_id' => $other->id])->save();

    apiGet('/api/v1/root', $plain)->assertOk()->assertJson(['name' => 'Renamed']);
});

it('reaches a folder, its descendants and nothing else', function () {
    $child = Node::factory()->for($this->user, 'owner')->create(['parent_id' => $this->folder->id]);
    $grandchild = Node::factory()->for($this->user, 'owner')->file()->create(['parent_id' => $child->id]);
    $sibling = Node::factory()->for($this->user, 'owner')->create();
    $token = $this->user->createToken('t', ['read'])->accessToken;
    $token->forceFill(['folder_id' => $this->folder->id])->save();

    expect($token->reaches($this->folder))->toBeTrue()
        ->and($token->reaches($child))->toBeTrue()
        ->and($token->reaches($grandchild))->toBeTrue()
        ->and($token->reaches($sibling))->toBeFalse();

    $parent = Node::factory()->for($this->user, 'owner')->create();
    $this->folder->forceFill(['parent_id' => $parent->id])->save();
    expect($token->reaches($parent))->toBeFalse();

    $child->forceFill(['trashed_at' => now()])->save();
    expect($token->reaches($grandchild))->toBeFalse();
});

it('does not reach another account\'s nodes, even through a whole-drive token', function () {
    $foreign = Node::factory()->create();
    $token = $this->user->createToken('t', ['read'])->accessToken;

    expect($token->reaches($foreign))->toBeFalse();
});
