<?php

use App\Models\ApiToken;
use App\Models\Node;
use App\Models\User;
use App\Support\AppConnect;
use Livewire\Livewire;

beforeEach(function () {
    config(['ferrite.connect_redirects' => ['ferrite://auth']]);

    $this->user = User::factory()->create();
    $this->folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Documents']);
    $this->verifier = str_repeat('v', 64);
    $this->query = [
        'client' => 'Ferrite for Mac',
        'device' => 'MacBook',
        'redirect' => 'ferrite://auth',
        'challenge' => AppConnect::challengeFor($this->verifier),
        'state' => 'abc123',
    ];
});

/** Approve in the browser page and return the code the app would receive. */
function approveInBrowser(array $query, array $set = []): string
{
    $component = Livewire::withQueryParams($query)->test('pages::connect');

    foreach ($set as $property => $value) {
        $component->set($property, $value);
    }

    $url = $component->call('approve')->assertHasNoErrors()->get('returnUrl');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    expect($url)->toStartWith('ferrite://auth?')->and($params['state'])->toBe($query['state']);

    return $params['code'];
}

describe('ping', function () {
    it('identifies the server without a token', function () {
        $this->getJson('/api/v1/ping')->assertOk()->assertJson(['app' => 'ferrite', 'api' => 1, 'connect' => true]);
    });
});

describe('the approval page', function () {
    it('asks a signed-in user, after the password check', function () {
        $this->get(route('connect', $this->query))->assertRedirect(route('login'));

        $this->actingAs($this->user)->get(route('connect', $this->query))->assertRedirect(route('password.confirm'));

        $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('connect', $this->query))->assertOk()->assertSee('Ferrite for Mac wants to connect')->assertSee('MacBook');
    });

    it('refuses a request that is not acceptable', function (array $override) {
        Livewire::actingAs($this->user)->withQueryParams($override + $this->query)->test('pages::connect')
            ->assertSee('This link is not valid')
            ->assertDontSee('Allow')
            ->call('approve')->assertForbidden();
    })->with([
        'a redirect that is not allowed' => [['redirect' => 'https://evil.example/cb']],
        'a redirect with a path added' => [['redirect' => 'ferrite://auth/../x']],
        'no challenge' => [['challenge' => '']],
        'a short challenge' => [['challenge' => 'abc']],
        'no client name' => [['client' => '']],
        'a state with odd characters' => [['state' => 'a"><b']],
    ]);

    it('hands the app a code and offers the folder choices only for the user\'s own folders', function () {
        $other = Node::factory()->for(User::factory()->create(), 'owner')->create(['name' => 'Secret']);

        $component = Livewire::actingAs($this->user)->withQueryParams($this->query)->test('pages::connect')
            ->assertSee('Documents')->assertDontSee('Secret')
            ->set('folder', (string) $other->id)->call('approve')->assertHasErrors('folder');

        expect($component->get('returnUrl'))->toBeNull();
    });

    it('sends access_denied back when cancelled', function () {
        $component = Livewire::actingAs($this->user)->withQueryParams($this->query)->test('pages::connect')->call('deny');

        expect($component->get('returnUrl'))->toBe('ferrite://auth?error=access_denied&state=abc123')
            ->and(ApiToken::count())->toBe(0);
    });

    it('cannot decide twice', function () {
        Livewire::actingAs($this->user)->withQueryParams($this->query)->test('pages::connect')
            ->call('approve')->call('approve')->assertForbidden();
    });
});

describe('swapping the code for a token', function () {
    it('gives every permission by default', function () {
        $this->actingAs($this->user);
        $code = approveInBrowser($this->query);
        auth()->logout();

        $this->postJson('/api/v1/connect/token', ['code' => $code, 'verifier' => $this->verifier])
            ->assertOk()->assertJson(['access' => 'read+write+replace+trash']);
    });

    it('makes a token with the approved folder and access, listed with the others', function () {
        $this->actingAs($this->user);
        $code = approveInBrowser($this->query, ['folder' => (string) $this->folder->id, 'access' => 'write', 'expiry' => '30']);
        auth()->logout();

        expect(ApiToken::count())->toBe(0);

        $response = $this->postJson('/api/v1/connect/token', ['code' => $code, 'verifier' => $this->verifier])
            ->assertOk()->assertJson(['name' => 'Documents', 'access' => 'read+write']);

        $token = ApiToken::first();

        expect($token->name)->toBe('Ferrite for Mac (MacBook)')
            ->and($token->folder_id)->toBe($this->folder->id)
            ->and($token->abilities)->toBe(['read', 'write'])
            ->and($token->expires_at->isFuture())->toBeTrue()
            ->and($token->tokenable_id)->toBe($this->user->id);

        // The token works against the API.
        $this->withToken($response->json('token'))->getJson('/api/v1/root')->assertOk()->assertJson(['name' => 'Documents']);
    });

    it('works once only', function () {
        $this->actingAs($this->user);
        $code = approveInBrowser($this->query);
        auth()->logout();

        $this->postJson('/api/v1/connect/token', ['code' => $code, 'verifier' => $this->verifier])->assertOk();
        $this->postJson('/api/v1/connect/token', ['code' => $code, 'verifier' => $this->verifier])->assertStatus(400);

        expect(ApiToken::count())->toBe(1);
    });

    it('needs the verifier, and a wrong guess burns the code', function () {
        $this->actingAs($this->user);
        $code = approveInBrowser($this->query);
        auth()->logout();

        $this->postJson('/api/v1/connect/token', ['code' => $code, 'verifier' => str_repeat('x', 64)])->assertStatus(400);
        $this->postJson('/api/v1/connect/token', ['code' => $code, 'verifier' => $this->verifier])->assertStatus(400);
        $this->postJson('/api/v1/connect/token', ['code' => 'nope', 'verifier' => $this->verifier])->assertStatus(400);
        $this->postJson('/api/v1/connect/token', ['code' => $code])->assertStatus(422);

        expect(ApiToken::count())->toBe(0);
    });

    it('expires after two minutes', function () {
        $this->actingAs($this->user);
        $code = approveInBrowser($this->query);
        auth()->logout();

        $this->travel(3)->minutes();

        $this->postJson('/api/v1/connect/token', ['code' => $code, 'verifier' => $this->verifier])->assertStatus(400);
    });

    it('is refused for a user disabled meanwhile', function () {
        $this->actingAs($this->user);
        $code = approveInBrowser($this->query);
        auth()->logout();
        $this->user->forceFill(['disabled_at' => now()])->save();

        $this->postJson('/api/v1/connect/token', ['code' => $code, 'verifier' => $this->verifier])->assertStatus(400);
    });
});
