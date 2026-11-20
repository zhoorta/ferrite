<?php

use App\Models\User;
use App\Support\Docs;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->downloads = storage_path('framework/testing/downloads-'.uniqid());
    File::ensureDirectoryExists($this->downloads);
    config(['ferrite.landing' => true, 'ferrite.downloads_path' => $this->downloads, 'ferrite.repo_url' => 'https://github.com/example/ferrite']);
});

afterEach(fn () => File::deleteDirectory($this->downloads));

it('lists the documentation and renders each published page', function () {
    $this->get('/docs')->assertOk()->assertSee('Documentation')->assertSee('Mac and Android apps');

    foreach (array_keys(Docs::PAGES) as $slug) {
        $this->get("/docs/{$slug}")->assertOk()->assertSee('<h1', false);
    }
});

it('only publishes the listed pages and every one has a file', function () {
    foreach (array_keys(Docs::PAGES) as $slug) {
        expect(base_path("docs/{$slug}.md"))->toBeFile();
    }

    $this->get('/docs/encryption')->assertNotFound();
    $this->get('/docs/..%2FPLAN')->assertNotFound();
});

it('keeps links between pages on the site and sends other relative links to the repository', function () {
    $html = Docs::render('apps');

    expect($html)->toContain('href="'.route('docs.show', 'api').'"')
        ->and($html)->toContain('href="'.route('docs.show', 'api').'#connecting-an-app-browser-approval"');

    File::put(base_path('docs/zz-test.md'), '[a](missing.md#x) [b](../PLAN.md) [c](https://example.com) [d](#top)');
    config(['ferrite.repo_url' => 'https://github.com/example/ferrite']);
    $reflection = new ReflectionMethod(Docs::class, 'rewriteLinks');
    $out = $reflection->invoke(null, '<a href="missing.md#x">a</a><a href="../PLAN.md">b</a><a href="https://example.com">c</a><a href="#top">d</a>');
    File::delete(base_path('docs/zz-test.md'));

    expect($out)->toContain('href="https://github.com/example/ferrite/blob/main/docs/missing.md#x"')
        ->and($out)->toContain('href="https://github.com/example/ferrite/blob/main/PLAN.md"')
        ->and($out)->toContain('href="https://example.com"')
        ->and($out)->toContain('href="#top"');
});

it('renders tables, code and heading anchors, and drops raw html', function () {
    $html = Docs::render('install').Docs::render('apps');

    expect($html)->toContain('<table')->toContain('<pre><code')->toContain('id="connecting"')->not->toContain('<script');
});

it('has a documented row for every route under /api/v1', function () {
    $api = file_get_contents(base_path('docs/api.md'));

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $path = '/'.substr($route->uri(), strlen('api/v1/'));
        $base = preg_replace('/\{[^}]+\}/', '{id}', $path);
        $found = false;

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }
            // Rows read "GET /files/{id}/content", with the placeholder named as the author liked.
            $pattern = '/`[^`]*\b'.$method.'\b[^`]*'.preg_replace('/\\\\\{id\\\\\}/', '\{[^}]+\}', preg_quote($base, '/')).'/';
            $found = $found || preg_match($pattern, $api) === 1;
        }

        expect($found)->toBeTrue("docs/api.md has no row for {$route->uri()}");
    }
})->skip(fn () => ! collect(Route::getRoutes())->contains(fn ($r) => str_starts_with($r->uri(), 'api/v1/')), 'no API routes');

it('shows the project page, docs and downloads in the sitemap and 404s them when the page is off', function () {
    $this->get('/sitemap.xml')->assertOk()->assertSee(route('docs.show', 'install'))->assertSee(route('downloads'));

    config(['ferrite.landing' => false]);

    $this->get('/docs')->assertNotFound();
    $this->get('/docs/install')->assertNotFound();
    $this->get('/download')->assertNotFound();
    $this->get('/download/Ferrite-1.0.0.apk')->assertNotFound();
});

it('says a platform is not published when there is no file', function () {
    $this->get('/download')->assertOk()->assertSee('Ferrite for Mac')->assertSee('Ferrite for Android')->assertSee('Not published on this server yet.');
});

it('offers the newest build of each platform with size and checksum', function () {
    File::put("{$this->downloads}/Ferrite-0.9.0.apk", 'old');
    File::put("{$this->downloads}/Ferrite-0.10.0.apk", 'android build');
    File::put("{$this->downloads}/Ferrite-0.1.0.dmg", 'mac build');
    File::put("{$this->downloads}/notes.txt", 'ignored');

    $this->get('/download')->assertOk()
        ->assertSee('Ferrite-0.10.0.apk')->assertDontSee('Ferrite-0.9.0.apk')
        ->assertSee('Ferrite-0.1.0.dmg')
        ->assertSee(hash('sha256', 'android build'))->assertSee(hash('sha256', 'mac build'))
        ->assertDontSee('notes.txt')->assertDontSee('Not published on this server yet.');
});

it('serves a listed build as a download with nosniff and the right type', function () {
    File::put("{$this->downloads}/Ferrite-0.1.0.apk", 'android build');
    File::put("{$this->downloads}/Ferrite-0.1.0.dmg", 'mac build');

    $this->get('/download/Ferrite-0.1.0.apk')->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.android.package-archive')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('Ferrite-0.1.0.apk');
    $this->get('/download/Ferrite-0.1.0.dmg')->assertOk()->assertDownload('Ferrite-0.1.0.dmg');
});

it('refuses anything that is not a listed build', function () {
    File::put("{$this->downloads}/Ferrite-0.9.0.apk", 'old');
    File::put("{$this->downloads}/Ferrite-0.10.0.apk", 'new');
    File::put("{$this->downloads}/secret.txt", 'secret');
    File::put("{$this->downloads}/Ferrite-1.0.0.exe", 'exe');

    $this->get('/download/secret.txt')->assertNotFound();
    $this->get('/download/Ferrite-1.0.0.exe')->assertNotFound();
    $this->get('/download/Ferrite-0.9.0.apk')->assertNotFound(); // only the newest is offered
    $this->get('/download/..%2F..%2F.env')->assertNotFound();
    $this->get('/download/missing.apk')->assertNotFound();
});

it('links to the docs and apps from the project page', function () {
    User::factory()->create();

    $this->get('/')->assertOk()->assertSee(route('docs.index'))->assertSee(route('downloads'))->assertSee('Apps for Mac and Android');
});
