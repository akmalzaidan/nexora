<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssetHistory> */
class AssetHistoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'user_id' => User::factory(),
            'action' => 'STATUS_CHANGE',
            'old_status' => 'DRAFT',
            'new_status' => 'ACTIVE',
            'old_location_id' => null,
            'new_location_id' => null,
            'notes' => fake()->optional()->sentence(),
            'created_at' => now(),
        ];
    }
}
