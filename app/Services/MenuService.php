<?php

namespace App\Services;

use App\Models\User;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\TelegramLogger;

class MenuService
{
    /**
     * Logger مخصص لتتبّع الرسائل والأخطاء.
     *
     * @var TelegramLogger
     */
    protected TelegramLogger $logger;

    /**
     * إنشاء الخدمة وتهيئة الـ Logger.
     */
    public function __construct()
    {
        $this->logger = new TelegramLogger();
    }

    /**
     * عرض رسالة الترحيب للمستخدم الجديد بدون اشتراك.
     *
     * @param User $user
     * @return void
     */
    public function showWelcomeMessage(User $user): void
    {
        $firstName = htmlspecialchars($user->first_name ?? 'مستخدم', ENT_QUOTES, 'UTF-8');

        $message =
            "🎉 مرحباً بك <b>{$firstName}</b>!\n\n" .
            "أهلاً بك في البوت الخاص بنا 🤖\n\n" .
            "للبدء في استخدام البوت، يمكنك اختيار:\n\n" .
            "🎁 تجربة مجانية لمدة 24 ساعة\n" .
            "💎 أو الاشتراك المباشر للحصول على جميع المميزات\n\n" .
            "اختر ما يناسبك:";

        Telegram::sendMessage([
            'chat_id'      => $user->telegram_id,
            'text'         => $message,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '🎁 فترة تجريبية 24 ساعة', 'callback_data' => 'trial_24h'],
                    ],
                    [
                        ['text' => '💎 الاشتراك المدفوع', 'callback_data' => 'show_subscriptions'],
                    ],
                ],
            ]),
        ]);

        $this->logger->info("Welcome message sent", ['user_id' => $user->id]);
    }

    /**
     * عرض القائمة الرئيسية للمستخدم صاحب اشتراك نشط.
     * وفي حال عدم وجود اشتراك، يعيد المستخدم لقائمة الترحيب.
     *
     * @param User $user
     * @return void
     */
    public function showMainMenu(User $user): void
    {
        $subscription = $user->activeSubscription;

        // إذا لم يكن للمستخدم اشتراك → رجوع لقائمة الترحيب
        if (!$subscription) {
            $this->showWelcomeMessage($user);
            return;
        }

        // حساب الأيام المتبقية بدقة
        $daysLeft = 0;
        if ($subscription->ends_at) {
            $daysLeft = now()->diffInDays($subscription->ends_at, false);
            $daysLeft = max(0, (int) ceil($daysLeft));
        }

        $firstName           = htmlspecialchars($user->first_name ?? 'مستخدم', ENT_QUOTES, 'UTF-8');
        $planType            = $subscription->plan_type ?? 'غير محدد';
        $price               = number_format($subscription->price ?? 0, 2);
        $subscriptionEmoji   = $subscription->is_trial ? '🎁' : '💎';
        $subscriptionStatus  = $subscription->is_trial ? 'تجريبي' : 'مدفوع';

        $message =
            "✅ مرحباً <b>{$firstName}</b>!\n\n" .
            "اشتراكك نشط ✨\n\n" .
            "{$subscriptionEmoji} النوع: {$subscriptionStatus}\n" .
            "📦 الخطة: {$planType}\n" .
            "📅 متبقي: <b>{$daysLeft}</b> يوم\n" .
            "💰 السعر: \${$price}\n\n" .
            "يمكنك الآن استخدام جميع مميزات البوت! 🎉";

        Telegram::sendMessage([
            'chat_id'      => $user->telegram_id,
            'text'         => $message,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '🚀 بدء الاستخدام', 'callback_data' => 'start_using'],
                        ['text' => '❓ مساعدة', 'callback_data' => 'help'],
                    ],
                    [
                        ['text' => '📊 معلومات الاشتراك', 'callback_data' => 'subscription_info'],
                    ],
                ],
            ]),
        ]);

        $this->logger->info("Main menu sent", [
            'user_id'     => $user->id,
            'subscription' => $subscription->id,
        ]);
    }
}