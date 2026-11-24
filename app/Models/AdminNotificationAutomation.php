<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotificationAutomation extends Model
{
    protected $fillable = ['audience', 'title', 'message', 'enabled', 'frequency', 'next_run_at', 'last_sent_at'];

    protected $casts = [
        'enabled' => 'boolean',
        'next_run_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];
}
