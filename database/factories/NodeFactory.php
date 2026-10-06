<?php

namespace Database\Factories;

use App\Enums\NodeType;
use App\Models\Node;
use App\Models\StorageDisk;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'parent_id' => null,
            'type' => NodeType::Folder,
            'name' => fake()->unique()->words(2, true),
        ];
    }

    public function file(): static
    {
        return $this->state(fn () => [
            'type' => NodeType::File,
            'name' => fake()->unique()->word().'.txt',
            'disk_id' => StorageDisk::factory(),
            'path' => Str::random(40),
            'size' => fake()->numberBetween(1, 1_000_000),
            'mime' => 'text/plain',
        ]);
    }

    public function inside(Node $parent): static
    {
        return $this->state(fn () => [
            'owner_id' => $parent->owner_id,
            'parent_id' => $parent->id,
        ]);
    }

    public function trashed(): static
    {
        return $this->state(['trashed_at' => now()]);
    }
}
