<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketHistory extends Model
{
    use HasFactory;

    /**
     * Append-only history; updated_at does not exist on this table.
     */
    public const UPDATED_AT = null;

    public const ACTION_CREATED = 'CREATED';

    public const ACTION_UPDATED = 'UPDATED';

    public const ACTION_STATUS_CHANGED = 'STATUS_CHANGED';

    public const ACTION_ASSIGNMENT_CHANGED = 'ASSIGNMENT_CHANGED';

    protected $fillable = [
        'ticket_id',
        'user_id',
        'action',
        'old_status',
        'new_status',
        'notes',
        'created_at',
    ];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
