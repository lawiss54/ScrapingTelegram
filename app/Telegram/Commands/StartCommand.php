<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use App\Models\User;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected string $description = 'بدء البوت';

    public function handle()
    {
        $telegramUser = $this->getUpdate()->getMessage()->getFrom();
        
        $user = User::firstOrCreate(
            ['telegram_id' => $telegramUser->getId()],
            [
                'username' => $telegramUser->getUsername(),
                'first_name' => $telegramUser->getFirstName(),
            ]
        );
        
        if ($user->hasActiveSubscription()) {
            $this->showActiveStatus($user);
        } else {
            $this->showSubscriptionPlans();
        }
    }
    
    protected function showSubscriptionPlans()
    {
        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '📦 شهري - $10', 'callback_data' => 'plan_monthly']),
                Keyboard::inlineButton(['text' => '📦 ربع سنوي - $25', 'callback_data' => 'plan_quarterly'])
            ])
            ->row([
                Keyboard::inlineButton(['text' => '📦 سنوي - $90', 'callback_data' => 'plan_yearly'])
            ]);
        
        $this->replyWithMessage([
            'text' => "مرحباً! 👋

للاستفادة من البوت، اختر خطة الاشتراك:",
            'reply_markup' => $keyboard
        ]);
    }
}