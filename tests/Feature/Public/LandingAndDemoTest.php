<?php

use App\Models\Node;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Support\Facades\File;

it('redirects guests to the sign-in form when the landing page is off', function () {
    $this->get('/')->assertRedirect(route('files'));
});

it('shows the landing page to guests but sends signed-in users to their files', function () {
    config(['ferrite.landing' => true, 'ferrite.demo_url' => 'https://demo.example.test']);

    $this->get('/')->assertOk()->assertSee('Your files, on your server.')->assertSee('https://demo.example.test');
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('files'));
});

it('hides the demo button unless demo mode is on', function () {
    $this->get(route('login'))->assertDontSee('Try the demo');

    config(['ferrite.demo.enabled' => true]);
    $this->get(route('login'))->assertSee('Try the demo');
});

it('does not start a demo when demo mode is off', function () {
    $this->post(route('demo.start'))->assertNotFound();
});

it('signs a visitor in to a seeded throwaway account', function () {
    config(['ferrite.demo.enabled' => true]);

    $this->post(route('demo.start'))->assertRedirect(route('files'));

    $user = User::query()->sole();
    expect(Demo::isDemoUser($user))->toBeTrue()
        ->and($user->quota_bytes)->toBe(20 * 1024 * 1024)
        ->and($user->used_bytes)->toBeGreaterThan(0)
        ->and(Node::query()->where('owner_id', $user->id)->whereNull('parent_id')->count())->toBe(4)
        ->and((int) Node::query()->where('owner_id', $user->id)->sum('size'))->toBe($user->used_bytes);
    $this->assertAuthenticatedAs($user);
    $this->get(route('files'))->assertOk()->assertSee('Welcome to Ferrite.md');
});

it('refuses new visitors when the demo is full', function () {
    config(['ferrite.demo.enabled' => true, 'ferrite.demo.max_accounts' => 1]);
    app(Demo::class)->createVisitor();

    $this->post(route('demo.start'))->assertRedirect(route('login'))->assertSessionHasErrors('demo');
    expect(User::query()->count())->toBe(1);
});

it('locks account settings for demo visitors but not the appearance page', function () {
    config(['ferrite.demo.enabled' => true]);
    $user = app(Demo::class)->createVisitor();

    $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('appearance.edit'));
    $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('appearance.edit'));
    $this->actingAs($user)->get(route('appearance.edit'))->assertOk();
});

it('closes registration in demo mode', function () {
    config(['ferrite.demo.enabled' => true]);

    $this->get(route('register'))->assertNotFound();
});

it('prunes old demo accounts with their files and keeps real users', function () {
    config(['ferrite.demo.enabled' => true]);
    $old = app(Demo::class)->createVisitor();
    $fresh = app(Demo::class)->createVisitor();
    $real = User::factory()->create();
    $old->forceFill(['created_at' => now()->subHours(3)])->save();

    $this->artisan('demo:prune')->assertSuccessful();

    expect(User::query()->pluck('id')->all())->toEqualCanonicalizing([$fresh->id, $real->id])
        ->and(Node::query()->where('owner_id', $old->id)->exists())->toBeFalse()
        ->and(File::allFiles(config('ferrite.local_root')))->toHaveCount(count(Node::query()->where('owner_id', $fresh->id)->whereNotNull('path')->get()));
});
