<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;
use App\Models\User;

class StatusCommand extends Command
{
    // اسم الأمر لي يكتبو اليوزر في التلغرام: /status
    protected string $name = 'status';

    // الوصف تاع الأمر (يظهر في /help)
    protected string $description = 'عرض حالة الاشتراك';

    public function handle()
    {
        $telegramUser = $this->getUpdate()->getMessage()->getFrom();

        // نجيبو اليوزر من الداتا بيز حسب telegram_id
        $user = User::where('telegram_id', $telegramUser->getId())->first();

        // إذا ماكانش يوزر أو ماعندوش اشتراك نشط
        if (!$user || !$user->hasActiveSubscription()) {
            $this->replyWithMessage([
                'text' => "❌ ماعندكش اشتراك نشط.\n\nاستعمل /start باش تشوف خيارات الاشتراك."
            ]);
            return;
        }

        $subscription = $user->activeSubscription;
        $daysLeft = now()->diffInDays($subscription->ends_at);

        $text =
            "📊 حالة الاشتراك تاعك:\n\n" .
            "✅ الحالة: نشط\n" .
            "📦 الخطة: {$subscription->plan_type}\n" .
            "⏰ باقي: {$daysLeft} يوم\n" .
            "📅 تاريخ انتهاء الاشتراك: " . $subscription->ends_at->format('Y-m-d');

        $this->replyWithMessage([
            'text' => $text,
        ]);
    }
}