<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PricingConfig extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'base_charge',
        'base_fee_threshold',
        'no_base_fee_platform_percentage',
        'service_charge',
        'service_fee_percent',
        'product_markup_percent',
        'vendor_take_percent',
        'charge_per_distance',
        'referral_bonus_percentage',
        'region_id',
        'currency',
        'is_active',
        'description',
    ];

    protected $casts = [
        'base_charge' => 'decimal:2',
        'base_fee_threshold' => 'decimal:2',
        'no_base_fee_platform_percentage' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'service_fee_percent' => 'decimal:2',
        'product_markup_percent' => 'decimal:2',
        'vendor_take_percent' => 'decimal:2',
        'charge_per_distance' => 'decimal:2',
        'referral_bonus_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the active pricing config for a region
     */
    public static function getActiveConfig(?string $regionId = 'NG-DEFAULT'): ?self
    {
        return self::where('region_id', $regionId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Relationship: Orders using this config
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Scope: Active configs only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: By region
     */
    public function scopeForRegion($query, ?string $regionId)
    {
        return $query->where('region_id', $regionId);
    }
}
