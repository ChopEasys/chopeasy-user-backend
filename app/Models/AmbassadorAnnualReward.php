<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmbassadorAnnualReward extends Model
{
    protected $table = 'ambassador_annual_rewards';

    protected $fillable = [
        'ambassador_id',
        'tier_at_evaluation',
        'evaluation_start',
        'evaluation_end',
        'status',
        'reward_amount',
        'active_agents_avg',
        'delivery_count',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'evaluation_start' => 'date',
        'evaluation_end' => 'date',
        'reward_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /**
     * Get the ambassador (user) who this annual reward belongs to.
     */
    public function ambassador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ambassador_id');
    }
}
