<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\{TelegramBotService, AdminNotificationService};
use App\Models\{User, VerificationRequest};


class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramBotService $botService,
        protected AdminNotificationService $adminService
    ) {}
    
    public function handle(Request $request)
    {
        $update = Telegram::commandsHandler(true);
        
        // معالجة Callbacks
        if ($callbackQuery = $update->getCallbackQuery()) {
            $this->botService->handleCallback($callbackQuery);
        }
        
        // معالجة الرسائل
        if ($message = $update->getMessage()) {
            $this->handleMessage($message);
        }
        
        return response()->json(['status' => 'ok']);
    }
    
    protected function handleMessage($message)
    {
        $chatId = $message->getChat()->getId();
        
        // التحقق من انتظار إثبات دفع
        if (cache()->has("waiting_payment_{$chatId}")) {
            $this->handlePaymentProof($message);
            cache()->forget("waiting_payment_{$chatId}");
        }
    }
    
    protected function handlePaymentProof($message)
    {
        $chatId = $message->getChat()->getId();
        $user = User::where('telegram_id', $chatId)->first();
        
        $request = VerificationRequest::create([
            'user_id' => $user->id,
            'plan_type' => 'monthly',
            'transaction_id' => $message->getText(),
            'status' => 'pending',
        ]);
        
        $this->adminService->sendVerificationRequest($request);
        
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ تم استلام طلبك #{ $request->id}
جاري المراجعة..."
        ]);
    }
}
