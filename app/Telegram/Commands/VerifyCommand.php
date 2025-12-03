<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;
use App\Models\User;

class VerifyCommand extends Command
{
    protected string $name = 'verify';
    protected string $description = 'تأكيد الدفع';

    public function handle()
    {
        $chatId = $this->getUpdate()->getMessage()->getFrom()->getId();
        $user = User::where('telegram_id', $chatId)->first();
        
        if (!$user) {
            $this->replyWithMessage(['text' => '❌ ابدأ بـ /start أولاً']);
            return;
        }
        
        $this->replyWithMessage([
            'text' => "📸 أرسل صورة إيصال الدفع أو رقم العملية"
        ]);
        
        cache()->put("waiting_payment_{$chatId}", true, now()->addMinutes(320));
    }
}