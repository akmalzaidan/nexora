<?php

namespace Database\Seeders;

use App\Models\TicketCategory;
use Illuminate\Database\Seeder;

class TicketCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Hardware', 'code' => 'HARDWARE', 'description' => 'Physical device issues'],
            ['name' => 'Software', 'code' => 'SOFTWARE', 'description' => 'Application and OS issues'],
            ['name' => 'Network', 'code' => 'NETWORK', 'description' => 'Connectivity issues'],
            ['name' => 'Account', 'code' => 'ACCOUNT', 'description' => 'Access and credential issues'],
            ['name' => 'General Support', 'code' => 'GENERAL', 'description' => 'Anything else'],
        ];

        foreach ($categories as $category) {
            TicketCategory::updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
