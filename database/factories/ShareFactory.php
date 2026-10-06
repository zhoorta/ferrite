<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\Share;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Share>
 */
class ShareFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'created_by' => fn (array $attributes) => Node::query()->findOrFail((int) $attributes['node_id'])->owner_id,
            'token' => Str::random(40),
            'password_hash' => null,
            'expires_at' => null,
            'allow_download' => true,
            'revoked_at' => null,
        ];
    }

    public function password(string $password = 'secret'): static
    {
        return $this->state(['password_hash' => Hash::make($password)]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }

    public function viewOnly(): static
    {
        return $this->state(['allow_download' => false]);
    }
}
