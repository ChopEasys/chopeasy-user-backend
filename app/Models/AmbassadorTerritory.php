<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmbassadorTerritory extends Model
{
    protected $table = 'ambassador_territories';

    protected $fillable = [
        'name',
        'scope',
        'state',
        'lga',
    ];

    protected $casts = [
        'scope' => 'string',
    ];

    /**
     * Get all territory assignments for this territory.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(AmbassadorTerritoryAssignment::class, 'territory_id');
    }

    /**
     * Get active (currently assigned) territory assignments.
     */
    public function activeAssignments(): HasMany
    {
        return $this->hasMany(AmbassadorTerritoryAssignment::class, 'territory_id')
            ->whereNull('unassigned_at');
    }
}
