<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorProductItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'category_id',
        'product_id',
        'product_variant_id',
        'name',
        'display_name',
        'variant_label',
        'uom',
        'weight',
        'quantity',
        'manual_out_of_stock',
        'price',
        'vendor_price',
        'category_name',
        'logo',
    ];

    protected $casts = [
        'product_variant_id' => 'integer',
        'weight' => 'decimal:2',
        'quantity' => 'integer',
        'manual_out_of_stock' => 'boolean',
        'price' => 'decimal:2',
        'vendor_price' => 'decimal:2',
    ];

    /**
     * A vendor product item belongs to a vendor (user).
     */
    public function vendor()
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }
}
