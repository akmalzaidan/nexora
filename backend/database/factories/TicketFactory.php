<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_number' => fake()->unique()->bothify('TCK-########'),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'requester_id' => User::factory(),
            'priority' => fake()->randomElement(['LOW', 'MEDIUM', 'HIGH', 'URGENT']),
            'status' => 'OPEN',
        ];
    }
}
