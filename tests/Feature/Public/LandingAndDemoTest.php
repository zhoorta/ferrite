<?php

use App\Actions\Sharing\CreateShareLink;
use App\Actions\Sharing\ShareWithUser;
use App\Enums\Permission;
use App\Models\Node;
use App\Models\Upload;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

it('redirects guests to the sign-in form when the landing page is off', function () {
    config(['ferrite.landing' => false]);

    $this->get('/')->assertRedirect(route('files'));
});

it('shows the landing page to everyone, with a link to the files for signed-in users', function () {
    config(['ferrite.landing' => true, 'ferrite.demo_url' => 'https://demo.example.test']);

    $this->get('/')->assertOk()->assertSee('Your files, on your server.')->assertSee('https://demo.example.test');
    $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('Open my files');
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

describe('demo restrictions', function () {
    beforeEach(function () {
        config(['ferrite.demo.enabled' => true]);
        $this->visitor = app(Demo::class)->createVisitor();
        $this->actingAs($this->visitor);
    });

    it('does not let visitors share anything', function () {
        $node = Node::query()->where('owner_id', $this->visitor->id)->firstOrFail();

        expect($this->visitor->can('share', $node))->toBeFalse();
        expect(fn () => app(CreateShareLink::class)->handle($this->visitor, $node))
            ->toThrow(AuthorizationException::class);
        expect(fn () => app(ShareWithUser::class)->handle($this->visitor, $node, 'x@example.com', Permission::View))
            ->toThrow(AuthorizationException::class);
    });

    it('still lets ordinary users share outside the demo', function () {
        $user = User::factory()->create();
        $node = Node::factory()->for($user, 'owner')->create();

        expect($user->can('share', $node))->toBeTrue();
    });

    it('serves no public share links in demo mode', function () {
        $this->get('/s/'.str_repeat('a', 40))->assertNotFound();
    });

    it('refuses other file types and big files before any upload starts', function () {
        $this->postJson(route('uploads.store'), ['path' => 'setup.exe', 'size' => 10])->assertUnprocessable()->assertJsonValidationErrors('path');
        $this->postJson(route('uploads.store'), ['path' => 'page.html', 'size' => 10])->assertUnprocessable();
        $this->postJson(route('uploads.store'), ['path' => 'big.png', 'size' => 3 * 1024 * 1024])->assertUnprocessable()->assertJsonValidationErrors('size');
        $this->postJson(route('uploads.store'), ['path' => 'ok.PNG', 'size' => 1024])->assertCreated();
    });

    it('checks the content as well as the name', function () {
        expect(fn () => Demo::assertContentAllowed($this->visitor, 'application/x-dosexec'))->toThrow(ValidationException::class)
            ->and(fn () => Demo::assertContentAllowed($this->visitor, 'text/html'))->toThrow(ValidationException::class)
            ->and(fn () => Demo::assertContentAllowed($this->visitor, 'text/x-php'))->toThrow(ValidationException::class);

        Demo::assertContentAllowed($this->visitor, 'image/png');
        Demo::assertContentAllowed($this->visitor, 'application/pdf');
        Demo::assertContentAllowed($this->visitor, 'text/plain');
    });

    it('fails an upload whose content is not what its name claims', function () {
        $content = '<?php echo 1;';
        $id = $this->postJson(route('uploads.store'), ['path' => 'photo.png', 'size' => strlen($content)])->assertCreated()->json('id');

        $this->call('PATCH', route('uploads.update', $id), [], [], [], [
            'HTTP_UPLOAD_OFFSET' => 0, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream',
        ], $content);

        expect(Upload::query()->findOrFail($id)->status)->toBe('failed')
            ->and(Node::query()->where('owner_id', $this->visitor->id)->where('name', 'photo.png')->exists())->toBeFalse();
    });

    it('does not restrict uploads for ordinary users', function () {
        $this->actingAs(User::factory()->create());

        $this->postJson(route('uploads.store'), ['path' => 'tool.exe', 'size' => 5 * 1024 * 1024])->assertCreated();
    });

    it('shows the notice banner, with the contact when configured', function () {
        config(['ferrite.demo.contact' => 'abuse@example.test']);

        $this->get(route('files'))->assertOk()->assertSee('Public demo')->assertSee('abuse@example.test');
    });
});
