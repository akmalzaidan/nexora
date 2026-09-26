<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory;

    /**
     * Append-only journal; updated_at does not exist on this table.
     */
    public const UPDATED_AT = null;

    /**
     * Stock enters a warehouse.
     */
    public const TYPE_STOCK_IN = 'STOCK_IN';

    /**
     * Stock leaves a warehouse.
     */
    public const TYPE_STOCK_OUT = 'STOCK_OUT';

    /**
     * All supported movement types.
     *
     * These are the official inventory contract. Transfers between warehouses
     * are expressed as a STOCK_OUT from one warehouse plus a STOCK_IN to
     * another in a later phase.
     *
     * @var array<int, string>
     */
    public const TYPES = [
        self::TYPE_STOCK_IN,
        self::TYPE_STOCK_OUT,
    ];

    protected $fillable = [
        'item_id',
        'warehouse_id',
        'type',
        'quantity',
        'reference_type',
        'reference_id',
        'performed_by',
        'notes',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
