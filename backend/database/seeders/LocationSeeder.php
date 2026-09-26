<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            ['name' => 'Head Office', 'code' => 'HO', 'description' => 'Primary administrative site', 'address' => null],
            ['name' => 'Warehouse', 'code' => 'WH', 'description' => 'Central storage facility', 'address' => null],
            ['name' => 'IT Room', 'code' => 'IT', 'description' => 'IT server and networking room', 'address' => null],
            ['name' => 'Operations Area', 'code' => 'OPS', 'description' => 'Field operations area', 'address' => null],
        ];

        foreach ($locations as $location) {
            Location::updateOrCreate(['code' => $location['code']], $location);
        }
    }
}
