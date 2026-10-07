<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_front_door_leads_to_the_files(): void
    {
        User::factory()->create();

        $response = $this->get(route('home'));

        $response->assertRedirect(route('files'));
    }
}
