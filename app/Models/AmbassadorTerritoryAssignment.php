<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmbassadorTerritoryAssignment extends Model
{
    protected $table = 'ambassador_territory_assignments';

    protected $fillable = [
        'ambassador_id',
        'territory_id',
        'assigned_at',
        'unassigned_at',
        'is_primary',
        'notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'unassigned_at' => 'datetime',
        'is_primary' => 'boolean',
    ];

    /**
     * Get the ambassador (user) this assignment belongs to.
     */
    public function ambassador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ambassador_id');
    }

    /**
     * Get the territory this assignment belongs to.
     */
    public function territory(): BelongsTo
    {
        return $this->belongsTo(AmbassadorTerritory::class, 'territory_id');
    }
}
