<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Location> */
class LocationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'code' => fake()->unique()->lexify('LOC-????'),
            'description' => fake()->sentence(),
            'address' => fake()->optional()->address(),
            'is_active' => true,
        ];
    }
}
