<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_category_id' => AssetCategory::factory(),
            'asset_code' => fake()->unique()->bothify('AST-####-????'),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'serial_number' => fake()->unique()->bothify('SN-########'),
            'status' => 'AVAILABLE',
            'condition' => 'GOOD',
            'purchase_date' => fake()->date(),
            'purchase_price' => fake()->randomNumber(7),
            'warranty_expiry' => fake()->dateTimeBetween('+1 year', '+3 years'),
        ];
    }
}
