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

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where('is_active', true)
            ->where('ends_at', '>', now())
            ->latest();
    }
    
    /**
     * جميع الاشتراكات
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
    
    /**
     * التحقق من وجود اشتراك نشط
     */
    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()->exists();
    }
    
    /**
     * الحصول على الاشتراك النشط (Attribute)
     */
    public function getActiveSubscriptionAttribute()
    {
        return $this->activeSubscription()->first();
    }
}