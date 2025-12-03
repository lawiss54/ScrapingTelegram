<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'telegram_id',
        'username',
        'first_name',
        'is_active',
    ];

    protected $hidden = [];

    protected $casts = [
        'telegram_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where('is_active', true)
            ->where('ends_at', '>', now());
    }

    public function verificationRequests()
    {
        return $this->hasMany(VerificationRequest::class);
    }

    public function hasActiveSubscription(): bool
    {
        // لازم تستعمل exists() ولا first()
        return $this->activeSubscription()->exists();
    }
}