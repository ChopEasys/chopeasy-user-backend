<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'product_id',
        'vendor_product_item_id',
        'product_variant_id',
        'quantity',
        'price_at_addition',
        'product_snapshot',
        'variant_snapshot',
        'total_cost'
    ];

    protected $casts = [
        'product_snapshot' => 'array',
        'variant_snapshot' => 'array',
        'price_at_addition' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
