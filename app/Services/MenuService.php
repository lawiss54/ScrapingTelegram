<?php

namespace App\Services;

use App\Models\User;
use Telegram\Bot\Keyboard\Keyboard;

class MenuService
{
    protected TelegramLogger $logger;
    
    public function __construct()
    {
        $this->logger = new TelegramLogger();
    }
    
    public function showWelcomeMessage($command, User $user)
    {
        $this->logger->info("Showing welcome message", ['user_id' => $user->id]);
        
        $keyboard = Keyboard::make()->inline();
        
        $keyboard->row([
            Keyboard::inlineButton([
                'text' => '🎁 فترة تجريبية 24 ساعة',
                'callback_data' => 'trial_24h'
            ])
        ]);
        
        $keyboard->row([
            Keyboard::inlineButton([
                'text' => '💎 الاشتراك المدفوع',
                'callback_data' => 'show_subscriptions'
            ])
        ]);
        
        $firstName = htmlspecialchars($user->first_name ?? 'مستخدم', ENT_QUOTES, 'UTF-8');
        
        $message = "🎉 مرحباً بك <b>{$firstName}</b>!\n\n"
            . "أهلاً بك في البوت الخاص بنا 🤖\n\n"
            . "للبدء في استخدام البوت، يمكنك اختيار:\n\n"
            . "🎁 تجربة مجانية لمدة 24 ساعة\n"
            . "💎 أو الاشتراك المباشر للحصول على جميع المميزات\n\n"
            . "اختر ما يناسبك:";
        
        $command->replyWithMessage([
            'text' => $message,
            'reply_markup' => $keyboard,
            'parse_mode' => 'HTML'
        ]);
        
        $this->logger->success("Welcome message sent");
    }
    
    public function showMainMenu($command, User $user)
    {
        $this->logger->info("Showing main menu", ['user_id' => $user->id]);
        
        $subscription = $user->activeSubscription;
        
        if (!$subscription) {
            $this->logger->warning("No active subscription found");
            $this->showWelcomeMessage($command, $user);
            return;
        }
        
        $daysLeft = 0;
        if ($subscription->ends_at) {
            $daysLeft = now()->diffInDays($subscription->ends_at, false);
            $daysLeft = max(0, (int) ceil($daysLeft));
        }
        
        $keyboard = Keyboard::make()->inline();
        
        $keyboard->row([
            Keyboard::inlineButton(['text' => '🚀 بدء الاستخدام', 'callback_data' => 'start_using']),
            Keyboard::inlineButton(['text' => '❓ مساعدة', 'callback_data' => 'help'])
        ]);
        
        $keyboard->row([
            Keyboard::inlineButton(['text' => '📊 معلومات الاشتراك', 'callback_data' => 'subscription_info'])
        ]);
        
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
        
        $command->replyWithMessage([
            'text' => $message,
            'reply_markup' => $keyboard,
            'parse_mode' => 'HTML'
        ]);
        
        $this->logger->success("Main menu sent");
    }
}