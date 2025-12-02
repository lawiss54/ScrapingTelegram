<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\SubscriptionService;


// جدولة فحص الاشتراكات المنتهية
Schedule::call(function () {
    app(SubscriptionService::class)->checkAndExpireSubscriptions();
})->hourly()->name('check-expired-subscriptions')->withoutOverlapping();

// إرسال تذكير قبل انتهاء الاشتراك بـ 3 أيام
Schedule::call(function () {
    $expiringSoon = AppModelsSubscription::where('is_active', true)
        ->where('ends_at', '<=', now()->addDays(3))
        ->where('ends_at', '>', now())
        ->get();
        
    foreach ($expiringSoon as $subscription) {
        TelegramBotLaravelFacadesTelegram::sendMessage([
            'chat_id' => $subscription->user->telegram_id,
            'text' => "⚠️ اشتراكك سينتهي خلال 3 أيام!

يرجى التجديد للاستمرار في استخدام البوت."
        ]);
    }
})->daily()->at('09:00')->name('send-renewal-reminders');

// جدولة أمر مخصص
Schedule::command('app:send-daily-report')->dailyAt('10:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
