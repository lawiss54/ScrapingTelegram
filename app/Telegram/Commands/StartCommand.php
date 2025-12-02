<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use App\Models\User;

class StartCommand extends Command
{
    protected $name = 'start';
    protected $description = 'بدء استخدام البوت';

    public function handle()
    {
        $telegramUser = $this->getUpdate()->getMessage()->getFrom();
        
        // إنشاء أو تحديث المستخدم
        $user = User::firstOrCreate(
            ['telegram_id' => $telegramUser->getId()],
            [
                'username' => $telegramUser->getUsername(),
                'first_name' => $telegramUser->getFirstName(),
                'is_active' => false,
            ]
        );
        
        // التحقق من وجود اشتراك نشط
        if ($user->hasActiveSubscription()) {
            $this->showMainMenu($user);
        } else {
            $this->showWelcomeMessage($user);
        }
    }
    
    protected function showWelcomeMessage($user)
    {
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
        
        $message = "🎉 مرحباً بك {$user->first_name}!\n\n"
            . "أهلاً بك في البوت الخاص بنا 🤖\n\n"
            . "للبدء في استخدام البوت، يمكنك اختيار:\n\n"
            . "🎁 تجربة مجانية لمدة 24 ساعة\n"
            . "💎 أو الاشتراك المباشر للحصول على جميع المميزات\n\n"
            . "اختر ما يناسبك:";
        
        $this->replyWithMessage([
            'text' => $message,
            'reply_markup' => $keyboard,
            'parse_mode' => 'HTML'
        ]);
    }
    
    protected function showMainMenu($user)
    {
        $subscription = $user->activeSubscription;
        
        if (!$subscription) {
            $this->showWelcomeMessage($user);
            return;
        }
        
        $daysLeft = now()->diffInDays($subscription->ends_at, false);
        $daysLeft = max(0, ceil($daysLeft)); // تجنب القيم السالبة
        
        $keyboard = Keyboard::make()->inline();
        
        $keyboard->row([
            Keyboard::inlineButton([
                'text' => '🚀 بدء الاستخدام',
                'callback_data' => 'start_using'
            ]),
            Keyboard::inlineButton([
                'text' => '❓ مساعدة',
                'callback_data' => 'help'
            ])
        ]);
        
        $keyboard->row([
            Keyboard::inlineButton([
                'text' => '📊 معلومات الاشتراك',
                'callback_data' => 'subscription_info'
            ])
        ]);
        
        $planType = $subscription->plan_type ?? 'غير محدد';
        $price = $subscription->price ?? 0;
        
        $message = "✅ مرحباً {$user->first_name}!\n\n"
            . "اشتراكك نشط ✨\n\n"
            . "📦 الخطة: {$planType}\n"
            . "📅 متبقي: {$daysLeft} يوم\n"
            . "💰 السعر: \${$price}\n\n"
            . "يمكنك الآن استخدام جميع مميزات البوت! 🎉";
        
        $this->replyWithMessage([
            'text' => $message,
            'reply_markup' => $keyboard,
            'parse_mode' => 'HTML'
        ]);
    }
}