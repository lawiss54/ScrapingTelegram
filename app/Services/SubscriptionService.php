<?php

namespace App\Services;

use App\Models\User;
use App\Models\Subscription;

class SubscriptionService
{
    /**
     * إنشاء اشتراك جديد للمستخدم.
     *
     * @param User   $user          المستخدم الذي سيتم إنشاء الاشتراك له
     * @param string $planType      نوع الخطة (monthly, quarterly, yearly...)
     * @param int    $durationDays  عدد الأيام التي يدوم فيها الاشتراك
     *
     * @return Subscription
     */
    public function createSubscription(User $user, string $planType, int $durationDays): Subscription
    {
        // تحديد السعر حسب نوع الخطة
        $price = $this->getPlanPrice($planType);

        // إنشاء سجل الاشتراك في قاعدة البيانات
        return Subscription::create([
            'user_id'   => $user->id,
            'plan_type' => $planType,
            'price'     => $price,
            'starts_at' => now(),
            'ends_at'   => now()->addDays($durationDays),
            'is_active' => true,
            'status'    => 'active',
        ]);
    }

    /**
     * فحص الاشتراكات المنتهية وتعطيلها تلقائياً.
     * الهدف: تحديث حالة الاشتراك دون تدخل يدوي.
     *
     * يستعمل عادةً في Cron Job أو Scheduler.
     *
     * @return void
     */
    public function checkAndExpireSubscriptions(): void
    {
        Subscription::where('is_active', true)
            ->where('ends_at', '<=', now())
            ->update([
                'is_active' => false,
                'status'    => 'expired',
            ]);
    }

    /**
     * جلب سعر الخطة حسب نوعها.
     *
     * @param string $planType
     * @return float
     */
    protected function getPlanPrice(string $planType): float
    {
        return match ($planType) {
            'monthly'   => 15.00,
            'quarterly' => 40.00,
            'yearly'    => 90.00,
            default     => 0.00,
        };
    }
}