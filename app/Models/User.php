<?php

namespace App\Models;

use App\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject
{
    use HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'fullname',
        'middlename',
        'guardianname',
        'username',
        'main_wallet',
        'food_wallet',
        'phoneno',
        'address',
        'lga',
        'state',
        'country',
        'user_type',
        'email',
        'gender',
        'image',
        'cover_photo',
        'date_of_birth',
        'last_login',
        'email_verified_at',
        'fcm_token',
        'ip_address',
        'continent',
        'is_verified',
        'is_active',
        'can_login',
        'two_fa',
        'password',
        'otp_expires_at',
        'email_otp',
        'main_wallet',
        'food_wallet',
        'store_name',
        'store_image',
        'opening_time',
        'closing_time',
        'cac_certificate',
        'longitude',
        'latitude',
        'vehicle',
        'referred_by_agent_id',
        'onboarding_completed',
    ];

    protected $hidden = ['password', 'remember_token', 'email_otp'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'date_of_birth' => 'date',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'can_login' => 'boolean',
        'onboarding_completed' => 'boolean',
        'two_fa' => 'boolean',
    ];

    // public function sendPasswordResetNotification($token)
    // {
    //     $this->notify(new ResetPassword($this, $token));
    // }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function shippingAddresses()
    {
        return $this->hasMany(ShippingAddress::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function vendorProducts()
{
    return $this->hasMany(VendorProductItem::class, 'vendor_id');
}

public function reviews()
{
    return $this->hasMany(ProductReview::class, 'user_id');
}

public function agentBankDetails()
{
    return $this->hasOne(AgentBankDetail::class)->latestOfMany();
}

public function vendorBankDetails()
{
    return $this->hasOne(VendorBankDetail::class);
}

public function riderBankDetails()
{
    return $this->hasOne(RiderBankDetail::class);
}

public function referredByAgent()
{
    return $this->belongsTo(User::class, 'referred_by_agent_id');
}

public function referredCustomers()
{
    return $this->hasMany(User::class, 'referred_by_agent_id');
}

public function agentEarnings()
{
    return $this->hasMany(AgentEarning::class, 'agent_id');
}

public function agentWithdrawals()
{
    return $this->hasMany(AgentWithdrawal::class, 'agent_id');
}

public function agentOrders()
{
    return $this->hasMany(Order::class, 'agent_id');
}

public function vendorPayouts()
{
    return $this->hasMany(VendorPayout::class, 'vendor_id');
}

public function riderPayouts()
{
    return $this->hasMany(RiderPayout::class, 'rider_id');
}

public function isStoreOpen()
{
    if (!$this->opening_time || !$this->closing_time) {
        return true; // If no hours set, assume always open
    }

    $now = now();
    $currentTime = $now->format('H:i:s');
    $openingTime = $this->opening_time;
    $closingTime = $this->closing_time;

    return $currentTime >= $openingTime && $currentTime <= $closingTime;
}


}
