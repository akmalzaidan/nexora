<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state. Values mirror the real writer
     * (AuditLogService): lowercase action from the controlled vocabulary,
     * snake_case resource type, scrubbed JSON values, and a timestamp.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => fake()->randomElement(['created', 'updated', 'deleted', 'status_changed', 'assigned', 'returned']),
            'entity_type' => fake()->randomElement(['user', 'asset', 'ticket', 'item', 'maintenance_request']),
            'entity_id' => fake()->numberBetween(1, 100),
            'description' => fake()->sentence(),
            'old_values' => null,
            'new_values' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'created_at' => now(),
        ];
    }
}
