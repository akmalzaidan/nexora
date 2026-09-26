<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceRequest extends Model
{
    use HasFactory;

    /**
     * A request has been raised and awaits approval/assignment.
     */
    public const STATUS_REQUESTED = 'REQUESTED';

    /**
     * The request has been approved and is ready for work to start.
     */
    public const STATUS_APPROVED = 'APPROVED';

    /**
     * Work has started (a maintenance record exists).
     */
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    /**
     * The work is finished; completed_at is set.
     */
    public const STATUS_COMPLETED = 'COMPLETED';

    /**
     * The request was cancelled before completion.
     */
    public const STATUS_CANCELLED = 'CANCELLED';

    public const PRIORITY_LOW = 'LOW';

    public const PRIORITY_MEDIUM = 'MEDIUM';

    public const PRIORITY_HIGH = 'HIGH';

    public const PRIORITY_URGENT = 'URGENT';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @var array<int, string>
     */
    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    protected $fillable = [
        'asset_id',
        'requested_by',
        'assigned_to',
        'title',
        'description',
        'priority',
        'status',
        'requested_at',
        'approved_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<MaintenanceRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }
}
