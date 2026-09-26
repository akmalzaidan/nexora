<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssetAssignment> */
class AssetAssignmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'user_id' => User::factory(),
            'requested_by' => User::factory(),
            'approved_by' => User::factory(),
            'location_id' => Location::factory(),
            'assigned_at' => now(),
            'returned_at' => null,
            'status' => 'PENDING',
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
