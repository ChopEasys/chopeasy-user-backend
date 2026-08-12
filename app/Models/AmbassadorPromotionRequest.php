<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmbassadorPromotionRequest extends Model
{
    protected $table = 'ambassador_promotion_requests';

    protected $fillable = [
        'agent_id',
        'current_tier',
        'requested_tier',
        'status',
        'active_agents_snapshot',
        'subordinate_count_snapshot',
        'delivery_count_snapshot',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    /**
     * Get the agent who submitted this promotion request.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Get the admin who reviewed this promotion request.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
