<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    public const TYPE_TICKET_ASSIGNED = 'ticket.assigned';

    public const TYPE_TICKET_STATUS_CHANGED = 'ticket.status_changed';

    public const TYPE_MAINTENANCE_ASSIGNED = 'maintenance.assigned';

    public const TYPE_MAINTENANCE_APPROVED = 'maintenance.approved';

    public const TYPE_MAINTENANCE_COMPLETED = 'maintenance.completed';

    public const TYPE_ASSET_ASSIGNED = 'asset.assigned';

    public const TYPE_ASSET_RETURNED = 'asset.returned';

    /**
     * @var array<int, string>
     */
    public const TYPES = [
        self::TYPE_TICKET_ASSIGNED,
        self::TYPE_TICKET_STATUS_CHANGED,
        self::TYPE_MAINTENANCE_ASSIGNED,
        self::TYPE_MAINTENANCE_APPROVED,
        self::TYPE_MAINTENANCE_COMPLETED,
        self::TYPE_ASSET_ASSIGNED,
        self::TYPE_ASSET_RETURNED,
    ];

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
