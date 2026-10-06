<?php

use App\Models\User;
use Illuminate\Support\Facades\Blade;

it('ships the favicon files the pages point to', function () {
    foreach (['favicon.ico', 'favicon.svg', 'apple-touch-icon.png'] as $file) {
        expect(public_path($file))->toBeFile();
    }

    $this->get(route('login'))
        ->assertSee('href="/favicon.svg"', false)
        ->assertSee('href="/favicon.ico"', false)
        ->assertSee('href="/apple-touch-icon.png"', false);
});

it('has a valid multi-size favicon.ico with PNG images', function () {
    $ico = file_get_contents(public_path('favicon.ico'));
    ['reserved' => $reserved, 'type' => $type, 'count' => $count] = unpack('vreserved/vtype/vcount', $ico);

    expect([$reserved, $type, $count])->toBe([0, 1, 3]);

    foreach (range(0, $count - 1) as $i) {
        $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vsize/Voffset', substr($ico, 6 + $i * 16, 16));
        expect(substr($ico, $entry['offset'], 8))->toBe("\x89PNG\r\n\x1a\n");
    }
});

it('shows the shed in the sidebar and on the sign-in page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('files'))->assertSee(asset('favicon.svg'), false);

    auth()->logout();
    $this->get(route('login'))->assertSee('<img src="'.asset('favicon.svg').'"', false);
});

it('has a single-colour mark that follows the text colour', function () {
    $svg = Blade::render('<x-app-logo-icon class="size-5 text-white" />');

    expect($svg)->toContain('fill="currentColor"', 'viewBox="14 0 228 218"', 'class="size-5 text-white"')
        ->and($svg)->not->toContain('#');
});

it('keeps the logo files used by the README', function () {
    foreach (['logo.svg', 'logo-dark.svg', 'icon.svg'] as $file) {
        expect(base_path("docs/img/{$file}"))->toBeFile();
    }

    expect(file_get_contents(base_path('README.md')))->toContain('docs/img/logo.svg', 'docs/img/logo-dark.svg');
});
