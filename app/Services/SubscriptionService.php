<?php
namespace App\Services;

use App\Models\{User, Subscription};

class SubscriptionService
{
    public function createSubscription(User $user, string $planType, int $durationDays)
    {
        $price = $this->getPlanPrice($planType);
        
        return Subscription::create([
            'user_id' => $user->id,
            'plan_type' => $planType,
            'price' => $price,
            'starts_at' => now(),
            'ends_at' => now()->addDays($durationDays),
            'is_active' => true,
            'status' => 'active',
        ]);
    }
    
    public function checkAndExpireSubscriptions()
    {
        Subscription::where('is_active', true)
            ->where('ends_at', '<=', now())
            ->update([
                'is_active' => false,
                'status' => 'expired'
            ]);
    }
    
    protected function getPlanPrice(string $planType): float
    {
        return match($planType) {
            'monthly' => 15.00,
            'quarterly' => 40.00,
            'yearly' => 90.00,
            default => 0.00,
        };
    }
}