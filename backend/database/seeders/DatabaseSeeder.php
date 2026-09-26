<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed reference data and development accounts.
     *
     * All seeders are idempotent (updateOrCreate / syncWithoutDetaching), so
     * this can be re-run safely against an already-seeded database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            LocationSeeder::class,
            AssetCategorySeeder::class,
            ItemCategorySeeder::class,
            WarehouseSeeder::class,
            UserSeeder::class,
            InventorySeeder::class,
            TicketCategorySeeder::class,
            TicketSeeder::class,
            MaintenanceSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}
