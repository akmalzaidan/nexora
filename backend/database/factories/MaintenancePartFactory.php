<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenancePart>
 */
class MaintenancePartFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'maintenance_record_id' => MaintenanceRecord::factory(),
            'item_id' => Item::factory(),
            'quantity' => fake()->numberBetween(1, 10),
        ];
    }
}
