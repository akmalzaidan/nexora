<?php

namespace Database\Seeders;

use App\Models\AssetCategory;
use Illuminate\Database\Seeder;

class AssetCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Laptop', 'code' => 'LAPTOP', 'description' => 'Portable computers'],
            ['name' => 'Desktop', 'code' => 'DESKTOP', 'description' => 'Workstation computers'],
            ['name' => 'Monitor', 'code' => 'MONITOR', 'description' => 'Display devices'],
            ['name' => 'Printer', 'code' => 'PRINTER', 'description' => 'Printing devices'],
            ['name' => 'Network Device', 'code' => 'NETWORK', 'description' => 'Routers, switches, and access points'],
            ['name' => 'Mobile Device', 'code' => 'MOBILE', 'description' => 'Phones and tablets'],
        ];

        foreach ($categories as $category) {
            AssetCategory::updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
