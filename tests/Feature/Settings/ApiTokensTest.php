<?php

use App\Models\ApiToken;
use App\Models\Node;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Music']);
    $this->sub = Node::factory()->for($this->user, 'owner')->create(['name' => 'Albums', 'parent_id' => $this->folder->id]);
});

it('needs a signed-in user and a recent password confirmation', function () {
    $this->get(route('api-tokens.edit'))->assertRedirect(route('login'));

    $this->actingAs($this->user)->get(route('api-tokens.edit'))->assertRedirect(route('password.confirm'));

    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('api-tokens.edit'))->assertOk()->assertSee('API tokens');
});

it('creates a folder-scoped token that works against the API, shown once', function () {
    $component = Livewire::actingAs($this->user)->test('pages::settings.api-tokens')
        ->set('name', 'Magnetite')
        ->set('folder', (string) $this->folder->id)
        ->set('access', 'write')
        ->set('expiry', '30')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('Copy your token now');

    $plain = $component->get('plainToken');
    $token = ApiToken::first();

    expect($token->folder_id)->toBe($this->folder->id)
        ->and($token->abilities)->toBe(['read', 'write'])
        ->and($token->expires_at->isFuture())->toBeTrue()
        ->and($token->token)->not->toBe($plain);

    $component->call('dismiss')->assertDontSee('Copy your token now');

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/root', ['Authorization' => "Bearer {$plain}"])
        ->assertOk()->assertJson(['name' => 'Music', 'access' => 'read+write']);
});

it('offers folders with their path and creates a whole-drive token on request', function () {
    Livewire::actingAs($this->user)->test('pages::settings.api-tokens')
        ->assertSee('Music / Albums')
        ->set('name', 'All')
        ->call('create')
        ->assertHasNoErrors();

    expect(ApiToken::first()->folder_id)->toBeNull();
});

it('refuses another account\'s folder, a bad access and a missing name', function () {
    $foreign = Node::factory()->create();

    Livewire::actingAs($this->user)->test('pages::settings.api-tokens')
        ->set('name', 'x')->set('folder', (string) $foreign->id)->call('create')->assertHasErrors('folder')
        ->set('folder', '')->set('access', 'admin')->call('create')->assertHasErrors('access')
        ->set('access', 'read')->set('name', '')->call('create')->assertHasErrors('name');

    expect(ApiToken::count())->toBe(0);
});

it('does not offer trashed folders or folders inside them', function () {
    $this->folder->forceFill(['trashed_at' => now()])->save();

    Livewire::actingAs($this->user)->test('pages::settings.api-tokens')
        ->assertDontSee('Music')
        ->assertDontSee('Albums');
});

it('revokes only the user\'s own tokens', function () {
    $mine = $this->user->createToken('mine', ['read'])->accessToken;
    $theirs = User::factory()->create()->createToken('theirs', ['read'])->accessToken;

    Livewire::actingAs($this->user)->test('pages::settings.api-tokens')
        ->call('revoke', $theirs->id)
        ->assertSee('mine')
        ->call('revoke', $mine->id);

    expect(ApiToken::pluck('id')->all())->toBe([$theirs->id]);
});

it('records the address a token was last used from', function () {
    $plain = $this->user->createToken('t', ['read'])->plainTextToken;

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->getJson('/api/v1/root', ['Authorization' => "Bearer {$plain}"])->assertOk();

    expect(ApiToken::first()->last_used_ip)->toBe('203.0.113.9');
});

it('creates a token that may move files to the trash', function () {
    Livewire::actingAs($this->user)->test('pages::settings.api-tokens')
        ->set('name', 'Magnetite')
        ->set('folder', (string) $this->folder->id)
        ->set('access', 'trash')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('read + write + trash');

    expect(ApiToken::first()->abilities)->toBe(['read', 'write', 'trash']);
});
