<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Slide extends Model
{
    protected $fillable = [
        'title',
        'description',
        'button_text',
        'image_path',
        'type',
        'order',
        'is_active',
        'url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
