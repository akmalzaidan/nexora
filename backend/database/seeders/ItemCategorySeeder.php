<?php

namespace Database\Seeders;

use App\Models\ItemCategory;
use Illuminate\Database\Seeder;

class ItemCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'IT Supplies', 'code' => 'IT-SUPPLIES', 'description' => 'Consumable IT materials'],
            ['name' => 'Office Supplies', 'code' => 'OFFICE', 'description' => 'General office consumables'],
            ['name' => 'Maintenance Parts', 'code' => 'MAINT-PARTS', 'description' => 'Spare parts for asset maintenance'],
            ['name' => 'Consumables', 'code' => 'CONSUMABLES', 'description' => 'Miscellaneous consumable goods'],
        ];

        foreach ($categories as $category) {
            ItemCategory::updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
