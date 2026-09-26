<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $warehouses = [
            [
                'name' => 'Main Warehouse',
                'code' => 'MAIN',
                'location_id' => Location::where('code', 'WH')->value('id'),
                'description' => 'Primary storage facility',
            ],
            [
                'name' => 'IT Supplies Store',
                'code' => 'IT-SUP',
                'location_id' => Location::where('code', 'IT')->value('id'),
                'description' => 'IT consumables and spare stock',
            ],
            [
                'name' => 'Operations Depot',
                'code' => 'OPS',
                'location_id' => Location::where('code', 'OPS')->value('id'),
                'description' => 'Field operations spares',
            ],
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::updateOrCreate(['code' => $warehouse['code']], $warehouse);
        }
    }
}
