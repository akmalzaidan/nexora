<?php

namespace Tests\Feature\Database;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'duplicate@nexora.test']);

        $this->expectException(QueryException::class);
        User::factory()->create(['email' => 'duplicate@nexora.test']);
    }

    public function test_duplicate_role_slug_is_rejected(): void
    {
        Role::create(['name' => 'Role A', 'slug' => 'role_a']);

        $this->expectException(QueryException::class);
        Role::create(['name' => 'Role B', 'slug' => 'role_a']);
    }

    public function test_duplicate_permission_slug_is_rejected(): void
    {
        Permission::create(['name' => 'Perm A', 'slug' => 'perm_a']);

        $this->expectException(QueryException::class);
        Permission::create(['name' => 'Perm B', 'slug' => 'perm_a']);
    }

    public function test_duplicate_department_code_is_rejected(): void
    {
        Department::create(['name' => 'Dept A', 'code' => 'DPA']);

        $this->expectException(QueryException::class);
        Department::create(['name' => 'Dept B', 'code' => 'DPA']);
    }

    public function test_duplicate_location_code_is_rejected(): void
    {
        Location::create(['name' => 'Loc A', 'code' => 'LCA']);

        $this->expectException(QueryException::class);
        Location::create(['name' => 'Loc B', 'code' => 'LCA']);
    }

    public function test_duplicate_asset_code_is_rejected(): void
    {
        Asset::factory()->create(['asset_code' => 'AST-DUP']);

        $this->expectException(QueryException::class);
        Asset::factory()->create(['asset_code' => 'AST-DUP']);
    }

    public function test_duplicate_category_codes_are_rejected(): void
    {
        AssetCategory::create(['name' => 'Cat A', 'code' => 'CAT-A']);
        ItemCategory::create(['name' => 'Cat B', 'code' => 'ICAT-A']);
        TicketCategory::create(['name' => 'Cat C', 'code' => 'TCAT-A']);

        try {
            AssetCategory::create(['name' => 'Cat A2', 'code' => 'CAT-A']);
            $this->fail('Duplicate asset category code was accepted.');
        } catch (QueryException) {
        }

        try {
            ItemCategory::create(['name' => 'Cat B2', 'code' => 'ICAT-A']);
            $this->fail('Duplicate item category code was accepted.');
        } catch (QueryException) {
        }

        try {
            TicketCategory::create(['name' => 'Cat C2', 'code' => 'TCAT-A']);
            $this->fail('Duplicate ticket category code was accepted.');
        } catch (QueryException) {
        }

        $this->assertTrue(true);
    }

    public function test_duplicate_item_sku_is_rejected(): void
    {
        Item::factory()->create(['sku' => 'SKU-DUP']);

        $this->expectException(QueryException::class);
        Item::factory()->create(['sku' => 'SKU-DUP']);
    }

    public function test_duplicate_ticket_number_is_rejected(): void
    {
        Ticket::factory()->create(['ticket_number' => 'TCK-00000001']);

        $this->expectException(QueryException::class);
        Ticket::factory()->create(['ticket_number' => 'TCK-00000001']);
    }
}
