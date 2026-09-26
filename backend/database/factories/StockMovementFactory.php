<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'warehouse_id' => Warehouse::factory(),
            'type' => fake()->randomElement(StockMovement::TYPES),
            'quantity' => fake()->numberBetween(1, 100),
            'reference_type' => fake()->optional()->randomElement(['purchase_order', 'maintenance', 'adjustment']),
            'reference_id' => fake()->optional()->numberBetween(1, 1000),
            'performed_by' => User::factory(),
            'notes' => fake()->optional()->sentence(),
            'created_at' => now(),
        ];
    }
}
