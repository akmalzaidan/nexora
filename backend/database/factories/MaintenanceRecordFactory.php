<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceRecord>
 */
class MaintenanceRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'maintenance_request_id' => MaintenanceRequest::factory(),
            'asset_id' => Asset::factory(),
            'technician_id' => User::factory(),
            'started_at' => now(),
            'completed_at' => null,
            'description' => fake()->paragraph(),
            'result' => null,
            'cost' => null,
        ];
    }
}
