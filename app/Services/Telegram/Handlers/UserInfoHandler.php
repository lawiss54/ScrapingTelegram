<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\TelegramLogger;
use App\Models\User;

class UserInfoHandler
{
    /**
     * Logger لتسجيل الأخطاء والأحداث.
     *
     * @var TelegramLogger
     */
    protected TelegramLogger $logger;

    /**
     * Inject logger.
     */
    public function __construct(TelegramLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * عرض حالة الاشتراك عند تنفيذ "/status".
     *
     * @param User $user
     * @param int  $chatId
     */
    public function showStatus($user, $chatId)
    {
        $subscription = $user->activeSubscription;

        // المستخدم بدون اشتراك
        if (!$subscription) {
            $this->sendNoSubscriptionStatus($chatId);
            return;
        }

        // حساب الأيام المتبقية
        $daysLeft = now()->diffInDays($subscription->ends_at, false);
        $daysLeft = max(0, (int) ceil($daysLeft));

        // نوع الاشتراك
        $statusEmoji = $subscription->is_trial ? '🎁' : '💎';
        $statusText  = $subscription->is_trial ? 'تجريبي' : 'مدفوع';

        Telegram::sendMessage([
            'chat_id'    => $chatId,
            'text'       =>
                "📊 <b>حالة اشتراكك</b>\n\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "✅ نشط\n" .
                "{$statusEmoji} النوع: {$statusText}\n" .
                "📦 الخطة: {$subscription->plan_type}\n" .
                "⏰ متبقي: <b>{$daysLeft}</b> يوم\n" .
                "📅 ينتهي في: " . $subscription->ends_at->format('Y-m-d H:i') . "\n" .
                "━━━━━━━━━━━━━━━━━━",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '📊 تفاصيل أكثر', 'callback_data' => 'subscription_info']],
                    [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'back_to_start']],
                ],
            ]),
        ]);
    }

    /**
     * رسالة: لا يوجد اشتراك عند تنفيذ "/status".
     */
    protected function sendNoSubscriptionStatus($chatId)
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text'    =>
                "⚠️ ليس لديك اشتراك نشط حالياً\n\n" .
                "للبدء في استخدام البوت، استخدم /start",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🚀 ابدأ الآن', 'callback_data' => 'back_to_start']],
                ],
            ]),
        ]);
    }

    /**
     * بدء استخدام البوت — ردّ على زر "start_using".
     */
    public function handleStartUsing($user, $chatId, $callbackId)
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text'    =>
                "🚀 مرحباً بك!\n\n" .
                "الأوامر المتاحة:\n" .
                "/status - حالة الاشتراك\n" .
                "/help - المساعدة\n" .
                "/settings - الإعدادات\n\n" .
                "ابدأ الآن! 💫",
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }

    /**
     * عرض قائمة المساعدة.
     */
    public function showHelp($chatId, $callbackId = null)
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text'    =>
                "❓ <b>المساعدة</b>\n\n" .
                "الأوامر المتاحة:\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "/start - القائمة الرئيسية\n" .
                "/status - حالة الاشتراك\n" .
                "/help - المساعدة\n" .
                "/support - الدعم الفني\n\n" .
                "📧 للتواصل:\n" .
                "support@yourdomain.com\n" .
                "📱 @YourSupportBot\n\n" .
                "⏰ ساعات العمل:\n" .
                "السبت - الخميس: 9 صباحاً - 5 مساءً",
            'parse_mode' => 'HTML',
        ]);

        if ($callbackId) {
            Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
        }
    }

    /**
     * عرض تفاصيل الاشتراك (زر "subscription_info").
     *
     * يحتوي على fallback إذا حدث خطأ في بناء التفاصيل.
     */
    public function showSubscriptionInfo($user, $chatId, $callbackId)
    {
        try {
            $subscription = $user->activeSubscription;

            if (!$subscription) {
                $this->sendNoSubscriptionMessage($chatId, $callbackId);
                return;
            }

            // محاولة بناء التفاصيل الكاملة
            try {
                $subscriptionDetails = $this->buildSubscriptionDetails($subscription);
            } catch (\Exception $buildError) {
                // fallback على نسخة بسيطة
                $subscriptionDetails = $this->buildSimpleSubscriptionDetails($subscription);
            }

            // محاولة إرسال HTML
            try {
                Telegram::sendMessage([
                    'chat_id'    => $chatId,
                    'text'       => $subscriptionDetails,
                    'parse_mode' => 'HTML',
                ]);
            } catch (\Exception $sendError) {
                // fallback إرسال بدون HTML
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => strip_tags($subscriptionDetails),
                ]);
            }

            Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
        } catch (\Exception $e) {
            // فشل عام
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text'    => '⚠️ حدث خطأ في عرض معلومات الاشتراك. الرجاء المحاولة لاحقاً.',
            ]);

            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text'              => '⚠️ حدث خطأ',
                'show_alert'        => true,
            ]);
        }
    }

    /**
     * Fallback: عرض تفاصيل بسيطة عن الاشتراك.
     */
    protected function buildSimpleSubscriptionDetails($subscription): string
    {
        $planNames = [
            'trial'       => 'تجريبي',
            'monthly'     => 'شهري',
            'quarterly'   => 'ربع سنوي',
            'semi_annual' => 'نصف سنوي',
            'yearly'      => 'سنوي',
        ];

        $planName    = $planNames[$subscription->plan_type] ?? $subscription->plan_type;
        $statusEmoji = $subscription->is_trial ? '🎁' : '💎';
        $statusText  = $subscription->is_trial ? 'تجريبي' : 'مدفوع';

        // حساب الأيام المتبقية
        $remainingDays = 0;
        try {
            $remainingDays = now()->diffInDays($subscription->ends_at, false);
            $remainingDays = max(0, (int) ceil($remainingDays));
        } catch (\Exception $e) {
            // تجاهل أي خطأ في التواريخ
        }

        return
            "📊 <b>معلومات اشتراكك</b>\n\n" .
            "{$statusEmoji} النوع: {$statusText}\n" .
            "📦 الخطة: {$planName}\n" .
            "💰 السعر: \${$subscription->price}\n" .
            "⏰ المتبقي: {$remainingDays} يوم\n\n" .
            "✅ اشتراكك نشط";
    }

    /**
     * بناء تفاصيل اشتراك كاملة (مع progress bar).
     */
    protected function buildSubscriptionDetails($subscription): string
    {
        $startsAt = $subscription->starts_at;
        $endsAt   = $subscription->ends_at;

        if (!$startsAt || !$endsAt) {
            return $this->buildSimpleSubscriptionDetails($subscription);
        }

        // تحويل التواريخ إذا لم تكن Carbon
        if (!($startsAt instanceof \Carbon\Carbon)) {
            $startsAt = \Carbon\Carbon::parse($startsAt);
        }

        if (!($endsAt instanceof \Carbon\Carbon)) {
            $endsAt = \Carbon\Carbon::parse($endsAt);
        }

        // الحسابات
        $totalDays     = $startsAt->diffInDays($endsAt);
        $passedDays    = $startsAt->diffInDays(now());
        $remainingDays = max(0, (int) ceil(now()->diffInDays($endsAt, false)));
        $progress      = $totalDays > 0 ? ($passedDays / $totalDays) * 100 : 0;
        $progress      = max(0, min(100, $progress));

        // progress bar
        $progressBar = $this->buildProgressBar($progress);

        // نوع الخطة
        $planNames = [
            'trial'       => 'تجريبي 24 ساعة',
            'monthly'     => 'شهري',
            'quarterly'   => 'ربع سنوي',
            'semi_annual' => 'نصف سنوي',
            'yearly'      => 'سنوي',
        ];

        $planName    = $planNames[$subscription->plan_type] ?? $subscription->plan_type;
        $statusEmoji = $subscription->is_trial ? '🎁' : '💎';
        $statusText  = $subscription->is_trial ? 'تجريبي' : 'مدفوع';

        // التواريخ
        $startDate = $startsAt->format('Y-m-d H:i');
        $endDate   = $endsAt->format('Y-m-d H:i');

        return
            "📊 <b>معلومات اشتراكك</b>\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "{$statusEmoji} <b>النوع:</b> {$statusText}\n" .
            "📦 <b>الخطة:</b> {$planName}\n" .
            "💰 <b>السعر:</b> \${$subscription->price}\n\n" .
            "📅 <b>تاريخ البداية:</b>\n   {$startDate}\n\n" .
            "📅 <b>تاريخ الانتهاء:</b>\n   {$endDate}\n\n" .
            "⏰ <b>المتبقي:</b> {$remainingDays} يوم\n\n" .
            "📈 <b>التقدم:</b> " . round($progress) . "%\n" .
            "{$progressBar}\n\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            $this->getSubscriptionWarning($remainingDays);
    }

    /**
     * إنشاء progress bar من 10 مربعات.
     */
    protected function buildProgressBar(float $progress): string
    {
        $filledBlocks = (int) round($progress / 10);
        $emptyBlocks  = 10 - $filledBlocks;

        return str_repeat('▓', $filledBlocks) . str_repeat('░', $emptyBlocks);
    }

    /**
     * تحذيرات أو تنبيهات حسب الأيام المتبقية.
     */
    protected function getSubscriptionWarning(int $remainingDays): string
    {
        if ($remainingDays <= 0) {
            return "⚠️ <b>انتهى الاشتراك!</b>\nيرجى التجديد للاستمرار في الاستخدام.";
        }

        if ($remainingDays <= 3) {
            return "⚠️ <b>اشتراكك ينتهي خلال {$remainingDays} يوم!</b>\nننصح بالتجديد قريباً.";
        }

        if ($remainingDays <= 7) {
            return "💡 <b>تذكير:</b> اشتراكك ينتهي خلال أسبوع.";
        }

        return "✅ اشتراكك نشط.";
    }

    /**
     * رسالة عند عدم وجود اشتراك نشط (زر "subscription_info").
     */
    protected function sendNoSubscriptionMessage($chatId, $callbackId)
    {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🎁 فترة تجريبية',       'callback_data' => 'trial_24h']],
                [['text' => '💎 الاشتراك المدفوع',   'callback_data' => 'show_subscriptions']],
                [['text' => '🏠 القائمة الرئيسية',   'callback_data' => 'back_to_start']],
            ],
        ];

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text'    =>
                "⚠️ <b>ليس لديك اشتراك نشط</b>\n\n" .
                "استفد من الفترة التجريبية أو اشترك للاستفادة من كامل المميزات.",
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }
}