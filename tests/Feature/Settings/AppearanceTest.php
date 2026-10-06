<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_palette_defaults_to_plum_and_is_rendered_on_the_page(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->assertSame('plum', $user->fresh()->palette);
        $this->get(route('appearance.edit'))->assertOk()->assertSee('data-palette="plum"', false);
    }

    public function test_palette_can_be_changed_and_persists(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test('pages::settings.appearance')->set('palette', 'wood')->assertHasNoErrors();

        $this->assertSame('wood', $user->fresh()->palette);
        $this->get(route('appearance.edit'))->assertSee('data-palette="wood"', false);
    }

    public function test_unknown_palette_is_rejected(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test('pages::settings.appearance')->set('palette', 'neon')->assertHasErrors('palette');

        $this->assertSame('plum', $user->fresh()->palette);
    }

    public function test_light_theme_drops_the_dark_class(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('appearance.edit'))->assertSee('<html lang="en" class="dark"', false);

        Livewire::test('pages::settings.appearance')->set('palette', 'light')->assertHasNoErrors();

        $this->get(route('appearance.edit'))
            ->assertSee('data-palette="light"', false)
            ->assertDontSee('class="dark"', false);
    }
}
