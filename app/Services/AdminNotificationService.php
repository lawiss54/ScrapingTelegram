<?php

namespace App\Services;

use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Keyboard\Keyboard;
use App\Models\VerificationRequest;

class AdminNotificationService
{
    public function sendVerificationRequest(VerificationRequest $request)
    {
        $adminIds = config('telegram.admin_ids');
        $user = $request->user;
        
        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton([
                    'text' => '✅ موافقة',
                    'callback_data' => "approve_{$request->id}"
                ]),
                Keyboard::inlineButton([
                    'text' => '❌ رفض',
                    'callback_data' => "reject_{$request->id}"
                ])
            ]);
        
        foreach ($adminIds as $adminId) {
            Telegram::sendMessage([
                'chat_id' => trim($adminId),
                'text' => "🔔 طلب تحقق جديد

"
                    . "الطلب: #{$request->id}
"
                    . "المستخدم: {$user->first_name}
"
                    . "الخطة: {$request->plan_type}",
                'reply_markup' => $keyboard
            ]);
        }
    }
}