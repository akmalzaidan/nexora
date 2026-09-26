<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    public function test_migrate_fresh_with_seed_runs_cleanly(): void
    {
        $exitCode = Artisan::call('migrate:fresh', ['--seed' => true]);

        $this->assertSame(0, $exitCode);

        $tables = [
            'roles',
            'permissions',
            'departments',
            'locations',
            'role_permissions',
            'asset_categories',
            'assets',
            'asset_assignments',
            'asset_histories',
            'item_categories',
            'warehouses',
            'items',
            'stock_movements',
            'ticket_categories',
            'tickets',
            'ticket_comments',
            'ticket_histories',
            'maintenance_requests',
            'maintenance_records',
            'maintenance_parts',
            'notifications',
            'audit_logs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Expected table [{$table}] to exist after migrate:fresh."
            );
        }
    }
}
