<?php

namespace Tests\Feature\Database;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuantityConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_minimum_stock_cannot_be_negative(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are only enforced on PostgreSQL.');
        }

        $this->expectException(QueryException::class);
        Item::factory()->create(['minimum_stock' => -1]);
    }

    public function test_stock_movement_quantity_must_be_positive(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are only enforced on PostgreSQL.');
        }

        $user = User::factory()->create();
        $item = Item::factory()->create();
        $warehouse = Warehouse::create([
            'name' => 'Temp Warehouse',
            'code' => 'TMP-WH',
        ]);

        $this->expectException(QueryException::class);
        StockMovement::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'IN',
            'quantity' => 0,
            'performed_by' => $user->id,
        ]);
    }
}
