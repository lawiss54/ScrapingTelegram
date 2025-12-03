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
        $adminId = config('telegram.bots.mybot.admin_ids.0');
        
        try {
            // Log 1: استقبال الطلب
            Telegram::sendMessage([
                'chat_id' => $adminId,
                'text' => "📥 Webhook received:
    " . json_encode($request->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ]);

            $update = Telegram::getWebhookUpdate();
        
            Telegram::commandsHandler(true);
            
            // Log 2: بعد معالجة الأوامر
            Telegram::sendMessage([
                'chat_id' => $adminId,
                'text' => "✅ Commands processed
    Update type: " . ($update->getMessage() ? 'message' : ($update->getCallbackQuery() ? 'callback' : 'other'))
            ]);
            
            // معالجة Callbacks
            if ($callbackQuery = $update->getCallbackQuery()) {
                Telegram::sendMessage([
                    'chat_id' => $adminId,
                    'text' => "🔘 Processing callback: " . $callbackQuery->getData()
                ]);
                $this->botService->handleCallback($callbackQuery);
            }
            
            // معالجة الرسائل
            if ($message = $update->getMessage()) {
                $text = $message->getText() ?? 'no text';
                Telegram::sendMessage([
                    'chat_id' => $adminId,
                    'text' => "💬 Processing message: " . $text
                ]);
                $this->handleMessage($message);
            }
            
            return response()->json(['status' => 'ok']);
            
        } catch (Exception $e) {
            // Log الأخطاء
            Telegram::sendMessage([
                'chat_id' => $adminId,
                'text' => "❌ Error:
    " . $e->getMessage() . "
    
    File: " . $e->getFile() . "
    Line: " . $e->getLine()
            ]);
            
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
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
