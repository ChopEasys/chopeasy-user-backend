<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'agent_id',
        'session_id',
        'order_number',
        'total_amount',
        'customer_product_subtotal',
        'service_fee_total',
        'delivery_fee_total',
        'base_fee_total',
        'weight_fee_total',
        'distance_fee_total',
        'status',
        'shipping_address_id',
        'shipping_address_snapshot',
        'payment_status',
        'payment_type',
        'installment_count',
        'custom_amount',
        'amount_paid',
        'remaining_amount',
        'next_due_date',
        'vendor_order_code',
        'accepted_by',
        'delivery_address',
        'total_weight',
        'item_count',
        'distance_in_km',
        'computed_total_charge',
        'platform_revenue',
        'rider_payout',
        'vendor_payout',
        'pricing_config_id',
        'weight_tier_id',
        'pricing_breakdown',
        'pickup_latitude',
        'pickup_longitude',
        'delivery_latitude',
        'delivery_longitude',
    ];

    protected $casts = [
        'shipping_address_snapshot' => 'array',
        'pricing_breakdown' => 'array',
        'total_amount' => 'decimal:2',
        'customer_product_subtotal' => 'decimal:2',
        'service_fee_total' => 'decimal:2',
        'delivery_fee_total' => 'decimal:2',
        'base_fee_total' => 'decimal:2',
        'weight_fee_total' => 'decimal:2',
        'distance_fee_total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'installment_count' => 'integer',
        'custom_amount' => 'decimal:2',
        'total_weight' => 'decimal:2',
        'distance_in_km' => 'decimal:2',
        'computed_total_charge' => 'decimal:2',
        'platform_revenue' => 'decimal:2',
        'rider_payout' => 'decimal:2',
        'vendor_payout' => 'decimal:2',
        'pickup_latitude' => 'decimal:8',
        'pickup_longitude' => 'decimal:8',
        'delivery_latitude' => 'decimal:8',
        'delivery_longitude' => 'decimal:8',
    ];


    /**
     * Order is funded enough for fulfillment: outright paid, or installment with no balance left.
     * Pending / failed / installment with remaining balance are excluded.
     */
    public function isPaidForFulfillment(): bool
    {
        if ($this->payment_status === 'paid') {
            return true;
        }

        if ($this->payment_status === 'installment') {
            return (float) ($this->remaining_amount ?? 0) <= 0.00001;
        }

        return false;
    }

    public function scopePaidForFulfillment($query)
    {
        return $query->where(function ($q) {
            $q->where('payment_status', 'paid')
                ->orWhere(function ($q2) {
                    $q2->where('payment_status', 'installment')
                        ->where('remaining_amount', '<=', 0);
                });
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shippingAddress()
    {
        return $this->belongsTo(ShippingAddress::class);
    }

    public function statusLogs()
    {
        return $this->hasMany(OrderStatusLog::class)->latest();
    }

    public function vendorOrders()
    {
        return $this->hasManyThrough(VendorOrder::class, OrderItem::class, 'order_id', 'order_item_id');
    }

    public function pricingConfig()
    {
        return $this->belongsTo(PricingConfig::class);
    }

    public function weightTier()
    {
        return $this->belongsTo(WeightTier::class);
    }

    public function agentEarnings()
    {
        return $this->hasMany(AgentEarning::class);
    }

    public function vendorPayouts()
    {
        return $this->hasMany(VendorPayout::class);
    }

    public function riderPayouts()
    {
        return $this->hasMany(RiderPayout::class);
    }
}
