<?php

namespace Database\Factories;

use App\Models\StorageDisk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorageDisk>
 */
class StorageDiskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'driver' => 'local',
            'config' => ['root' => storage_path('app/shed')],
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
