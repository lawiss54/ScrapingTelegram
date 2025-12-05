<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\TelegramLogger;
use App\Models\User;

class MenuHandler
{
    protected TelegramLogger $logger;
    
    public function __construct(TelegramLogger $logger)
    {
        $this->logger = $logger;
    }
    
    /**
     * العودة للقائمة الرئيسية
     */
    public function backToStart($user, $chatId, $messageId, $callbackId)
    {
        
        if ($user->hasActiveSubscription()) {
            $this->showActiveSubscriptionMenu($user, $chatId, $messageId);
        } else {
            $this->showWelcomeMenu($user, $chatId, $messageId);
        }
        
        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }
    
    /**
     * قائمة الترحيب (بدون اشتراك)
     */
    protected function showWelcomeMenu($user, $chatId, $messageId)
    {
        $firstName = htmlspecialchars($user->first_name ?? 'مستخدم', ENT_QUOTES, 'UTF-8');
        
        $message = "🎉 مرحباً بك <b>{$firstName}</b>!\n\n"
            . "أهلاً بك في البوت الخاص بنا 🤖\n\n"
            . "للبدء في استخدام البوت، يمكنك اختيار:\n\n"
            . "🎁 تجربة مجانية لمدة 24 ساعة\n"
            . "💎 أو الاشتراك المباشر للحصول على جميع المميزات\n\n"
            . "اختر ما يناسبك:";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎁 فترة تجريبية 24 ساعة', 'callback_data' => 'trial_24h']
                ],
                [
                    ['text' => '💎 الاشتراك المدفوع', 'callback_data' => 'show_subscriptions']
                ]
            ]
        ];
        
        Telegram::editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    
    /**
     * قائمة المستخدم النشط (مع اشتراك)
     */
    protected function showActiveSubscriptionMenu($user, $chatId, $messageId)
    {
        $subscription = $user->activeSubscription;
        $daysLeft = now()->diffInDays($subscription->ends_at, false);
        $daysLeft = max(0, (int) ceil($daysLeft));
        
        $firstName = htmlspecialchars($user->first_name ?? 'مستخدم', ENT_QUOTES, 'UTF-8');
        $planType = $subscription->plan_type ?? 'غير محدد';
        $price = number_format($subscription->price ?? 0, 2);
        $subscriptionEmoji = $subscription->is_trial ? '🎁' : '💎';
        $subscriptionStatus = $subscription->is_trial ? 'تجريبي' : 'مدفوع';
        
        $message = "✅ مرحباً <b>{$firstName}</b>!\n\n"
            . "اشتراكك نشط ✨\n\n"
            . "{$subscriptionEmoji} النوع: {$subscriptionStatus}\n"
            . "📦 الخطة: {$planType}\n"
            . "📅 متبقي: <b>{$daysLeft}</b> يوم\n"
            . "💰 السعر: \${$price}\n\n"
            . "يمكنك الآن استخدام جميع مميزات البوت! 🎉";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 بدء الاستخدام', 'callback_data' => 'start_using'],
                    ['text' => '❓ مساعدة', 'callback_data' => 'help']
                ],
                [
                    ['text' => '📊 معلومات الاشتراك', 'callback_data' => 'subscription_info']
                ]
            ]
        ];
        
        Telegram::editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
}