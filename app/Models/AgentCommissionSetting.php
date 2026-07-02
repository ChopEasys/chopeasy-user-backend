<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentCommissionSetting extends Model
{
    protected $fillable = [
        'customer_percent',
        'vendor_percent',
        'agent_percent',
        'max_vendor_rider_payout_commissions',
        'downline_percent',
    ];

    protected $casts = [
        'customer_percent' => 'decimal:2',
        'vendor_percent' => 'decimal:2',
        'agent_percent' => 'decimal:2',
        'max_vendor_rider_payout_commissions' => 'integer',
        'downline_percent' => 'decimal:2',
    ];
}
