<?php

namespace App\Services;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Models\{User, VerificationRequest};

class TelegramBotService
{
    public function handleCallback($callbackQuery)
    {
        $data = $callbackQuery->getData();
        $adminId = $callbackQuery->getFrom()->getId();
        
        if (!$this->isAdmin($adminId)) {
            return;
        }
        
        // معالجة الموافقة/الرفض
        if (str_starts_with($data, 'approve_')) {
            $this->approvePayment($data, $callbackQuery);
        }
        
        if (str_starts_with($data, 'reject_')) {
            $this->rejectPayment($data, $callbackQuery);
        }
    }
    
    protected function isAdmin($telegramId): bool
    {
        return in_array($telegramId, config('telegram.admin_ids'));
    }
    
    // باقي الدوال...
}