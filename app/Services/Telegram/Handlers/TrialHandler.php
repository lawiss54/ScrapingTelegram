<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\TelegramLogger;
use App\Models\{User, Subscription};

class TrialHandler
{
    /**
     * Logger لتتبع أحداث وطلبات التجربة المجانية.
     *
     * @var TelegramLogger
     */
    protected TelegramLogger $logger;

    /**
     * Inject logger.
     *
     * @param TelegramLogger $logger
     */
    public function __construct(TelegramLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * معالجة طلب التجربة المجانية.
     *
     * - يتحقق إن كان المستخدم قد استفاد سابقاً.
     * - إن لم يستفد، يتم إنشاء اشتراك تجريبي لمدة 24 ساعة.
     * - تعديل رسالة المستخدم وإعلامه بنجاح التفعيل.
     *
     * @param User   $user
     * @param int    $chatId
     * @param int    $messageId
     * @param string $callbackId
     */
    public function handleTrialRequest($user, $chatId, $messageId, $callbackId)
    {
        $this->logger->info("Trial request", ['user_id' => $user->id]);

        // المستخدم استفاد من التجربة سابقاً
        if ($this->hasUsedTrial($user)) {
            $this->sendTrialAlreadyUsed($callbackId);
            return;
        }

        // تفعيل التجربة
        $this->activateTrial($user);

        // إعلام المستخدم بالنجاح
        $this->sendTrialActivatedMessage($chatId, $messageId, $callbackId);

        $this->logger->success("Trial activated", ['user_id' => $user->id]);
    }

    /**
     * التحقق إن كان المستخدم سبق واستعمل التجربة المجانية.
     *
     * @param User $user
     * @return bool
     */
    protected function hasUsedTrial(User $user): bool
    {
        return Subscription::where('user_id', $user->id)
            ->where('plan_type', 'trial')
            ->exists();
    }

    /**
     * إنشاء اشتراك تجريبي جديد وتفعيل المستخدم لمدة 24 ساعة.
     *
     * @param User $user
     * @return Subscription
     */
    protected function activateTrial(User $user): Subscription
    {
        $subscription = Subscription::create([
            'user_id'    => $user->id,
            'plan_type'  => 'trial',
            'price'      => 0,
            'starts_at'  => now(),
            'ends_at'    => now()->addHours(24),
            'is_active'  => true,
            'is_trial'   => true,
            'status'     => 'active',
        ]);

        // تفعيل المستخدم حتى يتمكن من استعمال البوت
        $user->update(['is_active' => true]);

        return $subscription;
    }

    /**
     * رسالة: المستخدم سبق له استعمال التجربة.
     *
     * @param string $callbackId
     */
    protected function sendTrialAlreadyUsed($callbackId)
    {
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text'              => '⚠️ لقد استخدمت الفترة التجريبية من قبل',
            'show_alert'        => true,
        ]);
    }

    /**
     * رسالة: تم تفعيل التجربة بنجاح.
     *
     * @param int    $chatId
     * @param int    $messageId
     * @param string $callbackId
     */
    protected function sendTrialActivatedMessage($chatId, $messageId, $callbackId)
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 بدء الاستخدام', 'callback_data' => 'start_using'],
                    ['text' => '❓ مساعدة',        'callback_data' => 'help'],
                ]
            ],
        ];

        Telegram::editMessageText([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       =>
                "✅ تم تفعيل الفترة التجريبية!\n\n" .
                "🎁 المدة: 24 ساعة\n" .
                "⏰ تنتهي في: " . now()->addHours(24)->format('Y-m-d H:i') . "\n\n" .
                "يمكنك الآن استخدام جميع مميزات البوت! 🎉",
            'reply_markup' => json_encode($keyboard),
        ]);

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text'              => '✅ تم التفعيل',
        ]);
    }
}