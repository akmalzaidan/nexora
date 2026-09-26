<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['sku' => 'IT-MOUSE-001', 'name' => 'Wireless Mouse', 'category' => 'IT-SUPPLIES', 'unit' => 'unit', 'minimum_stock' => 10, 'maximum_stock' => 100],
            ['sku' => 'IT-KEYBD-001', 'name' => 'Mechanical Keyboard', 'category' => 'IT-SUPPLIES', 'unit' => 'unit', 'minimum_stock' => 5, 'maximum_stock' => 50],
            ['sku' => 'OFF-PAPER-A4', 'name' => 'A4 Paper Ream', 'category' => 'OFFICE', 'unit' => 'ream', 'minimum_stock' => 20, 'maximum_stock' => 200],
            ['sku' => 'MAINT-LUBE-01', 'name' => 'Machine Lubricant', 'category' => 'MAINT-PARTS', 'unit' => 'litre', 'minimum_stock' => 8, 'maximum_stock' => 60],
            ['sku' => 'CON-CABLE-LAN', 'name' => 'Cat6 Patch Cable', 'category' => 'CONSUMABLES', 'unit' => 'roll', 'minimum_stock' => 15, 'maximum_stock' => 150],
        ];

        foreach ($items as $item) {
            $model = Item::updateOrCreate([
                'sku' => $item['sku'],
            ], [
                'item_category_id' => ItemCategory::where('code', $item['category'])->value('id'),
                'name' => $item['name'],
                'description' => null,
                'unit' => $item['unit'],
                'minimum_stock' => $item['minimum_stock'],
                'maximum_stock' => $item['maximum_stock'],
                'is_active' => true,
            ]);

            $this->seedInitialStock($model, $item['sku']);
        }
    }

    /**
     * Seed an opening STOCK_IN per warehouse so balances are deterministic.
     * Movements are idempotent per (item, warehouse) — re-running never
     * double-counts stock.
     */
    private function seedInitialStock(Item $item, string $sku): void
    {
        $openings = [
            'MAIN' => 40,
            'IT-SUP' => 20,
            'OPS' => 10,
        ];

        $performedBy = User::where('email', 'admin@nexora.test')->value('id')
            ?? User::first()?->id;

        foreach ($openings as $warehouseCode => $quantity) {
            $warehouse = Warehouse::where('code', $warehouseCode)->value('id');

            $exists = StockMovement::where('item_id', $item->id)
                ->where('warehouse_id', $warehouse)
                ->exists();

            if ($exists) {
                continue;
            }

            StockMovement::create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse,
                'type' => StockMovement::TYPE_STOCK_IN,
                'quantity' => $quantity,
                'reference_type' => 'opening_balance',
                'reference_id' => null,
                'performed_by' => $performedBy,
                'notes' => "Opening balance for {$sku}",
            ]);
        }
    }
}
