<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\TelegramLogger;
use App\Models\{User, Subscription};

class TrialHandler
{
    protected TelegramLogger $logger;
    
    public function __construct(TelegramLogger $logger)
    {
        $this->logger = $logger;
    }
    
    /**
     * معالجة طلب التجربة المجانية
     */
    public function handleTrialRequest($user, $chatId, $messageId, $callbackId)
    {
        $this->logger->info("Trial request", ['user_id' => $user->id]);
        
        // التحقق من استخدام التجربة سابقاً
        if ($this->hasUsedTrial($user)) {
            $this->sendTrialAlreadyUsed($callbackId);
            return;
        }
        
        // تفعيل التجربة المجانية
        $this->activateTrial($user);
        
        // إرسال رسالة التأكيد
        $this->sendTrialActivatedMessage($chatId, $messageId, $callbackId);
        
        $this->logger->success("Trial activated", ['user_id' => $user->id]);
    }
    
    /**
     * التحقق من استخدام التجربة المجانية سابقاً
     */
    protected function hasUsedTrial(User $user): bool
    {
        return Subscription::where('user_id', $user->id)
            ->where('plan_type', 'trial')
            ->exists();
    }
    
    /**
     * تفعيل التجربة المجانية
     */
    protected function activateTrial(User $user): Subscription
    {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_type' => 'trial',
            'price' => 0,
            'starts_at' => now(),
            'ends_at' => now()->addHours(24),
            'is_active' => true,
            'is_trial' => true,
            'status' => 'active',
        ]);
        
        $user->update(['is_active' => true]);
        
        return $subscription;
    }
    
    /**
     * رسالة: التجربة المجانية مستخدمة مسبقاً
     */
    protected function sendTrialAlreadyUsed($callbackId)
    {
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text' => '⚠️ لقد استخدمت الفترة التجريبية من قبل',
            'show_alert' => true,
        ]);
    }
    
    /**
     * رسالة: تم تفعيل التجربة المجانية
     */
    protected function sendTrialActivatedMessage($chatId, $messageId, $callbackId)
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 بدء الاستخدام', 'callback_data' => 'start_using'],
                    ['text' => '❓ مساعدة', 'callback_data' => 'help']
                ]
            ]
        ];

        Telegram::editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' =>
                "✅ تم تفعيل الفترة التجريبية!\n\n" .
                "🎁 المدة: 24 ساعة\n" .
                "⏰ تنتهي في: " . now()->addHours(24)->format('Y-m-d H:i') . "\n\n" .
                "يمكنك الآن استخدام جميع مميزات البوت! 🎉",
            'reply_markup' => json_encode($keyboard),
        ]);

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text' => '✅ تم التفعيل',
        ]);
    }
}