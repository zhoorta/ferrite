<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_palette_defaults_to_ferrite_and_is_rendered_on_the_page(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->assertSame('ferrite', $user->fresh()->palette);
        $this->get(route('appearance.edit'))->assertOk()->assertSee('data-palette="ferrite"', false);
    }

    public function test_palette_can_be_changed_and_persists(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test('pages::settings.appearance')->set('palette', 'amiga')->assertHasNoErrors();

        $this->assertSame('amiga', $user->fresh()->palette);
        $this->get(route('appearance.edit'))->assertSee('data-palette="amiga"', false);
    }

    public function test_classic_mac_is_a_light_theme(): void
    {
        $this->actingAs($user = User::factory()->create(['palette' => 'mac']));

        Livewire::test('pages::settings.appearance')->assertSet('palette', 'mac');

        $html = $this->get(route('appearance.edit'))->assertSee('data-palette="mac"', false)->getContent();

        $this->assertDoesNotMatchRegularExpression('/<html[^>]*class="[^"]*dark/', $html);
        $this->assertFalse(User::isDarkPalette('mac'));
        $this->assertTrue(User::isDarkPalette('ferrite'));
    }

    public function test_amber_terminal_is_a_dark_theme(): void
    {
        $this->actingAs(User::factory()->create(['palette' => 'amber']));

        Livewire::test('pages::settings.appearance')->set('palette', 'amber')->assertHasNoErrors();

        $this->get(route('appearance.edit'))->assertSee('data-palette="amber"', false)->assertSee('class="dark"', false);
    }

    public function test_every_palette_can_be_picked_and_gets_the_right_mode(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (User::PALETTES as $palette) {
            Livewire::test('pages::settings.appearance')->set('palette', $palette)->assertHasNoErrors();

            $html = $this->get(route('appearance.edit'))->assertSee('data-palette="'.$palette.'"', false)->getContent();

            $this->assertSame(
                User::isDarkPalette($palette),
                (bool) preg_match('/<html[^>]*class="[^"]*dark/', $html),
                "Wrong colour mode for {$palette}",
            );
        }

        $this->assertSame(['ferrite-light', 'mac', 'desk95', 'zine', 'bubblegum'], User::LIGHT_PALETTES);
    }

    public function test_unknown_palette_is_rejected(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test('pages::settings.appearance')->set('palette', 'neon')->assertHasErrors('palette');

        $this->assertSame('ferrite', $user->fresh()->palette);
    }

    public function test_light_theme_drops_the_dark_class(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('appearance.edit'))->assertSee('<html lang="en" class="dark"', false);

        Livewire::test('pages::settings.appearance')->set('palette', 'zine')->assertHasNoErrors();

        $this->get(route('appearance.edit'))
            ->assertSee('data-palette="zine"', false)
            ->assertDontSee('class="dark"', false);
    }
}
