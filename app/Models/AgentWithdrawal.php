<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentWithdrawal extends Model
{
    protected $fillable = [
        'agent_id',
        'amount',
        'status',
        'bank_name',
        'bank_code',
        'account_number',
        'account_name',
        'recipient_code',
        'transfer_code',
        'transfer_reference',
        'failure_reason',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function lines()
    {
        return $this->hasMany(AgentWithdrawalLine::class, 'agent_withdrawal_id');
    }

    public function earnings()
    {
        return $this->hasMany(AgentEarning::class, 'withdrawal_id');
    }
}
