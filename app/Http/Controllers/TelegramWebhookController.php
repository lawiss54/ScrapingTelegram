<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            $update = Telegram::getWebhookUpdate();
            $updateId = $update->getUpdateId();
            
            // ✅ تحقق من آخر update_id معالج
            $lastProcessedId = Cache::get('last_telegram_update_id', 0);
            
            if ($updateId <= $lastProcessedId) {
                
                return response()->json(['status' => 'duplicate']);
            }
            
            // ✅ حفظ الـ update_id الحالي
            Cache::forever('last_telegram_update_id', $updateId);
            
            // Log 1: استقبال الطلب
            Telegram::sendMessage([
                'chat_id' => $adminId,
                'text' => "📥 Webhook received:
                Update ID: " . $updateId . "
                Type: " . $this->getUpdateType($update)
            ]);
    
            // ✅ معالجة Callbacks أولاً (قبل commandsHandler)
            if ($callbackQuery = $update->getCallbackQuery()) {
                
                $this->botService->handleCallback($callbackQuery);
                
                return response()->json(['status' => 'ok']);
            }
            
            // معالجة الرسائل العادية
            if ($message = $update->getMessage()) {
                $text = $message->getText() ?? '';
                
                // ✅ إذا كانت رسالة أمر (تبدأ بـ /)
                if (str_starts_with($text, '/')) {
                    
                    Telegram::commandsHandler(true);
                } 
                // ✅ إذا كانت رسالة عادية
                else {
                    
                    $this->handleMessage($message);
                }
            }
            
            return response()->json(['status' => 'ok']);
            
        } catch (Exception $e) {
            Telegram::sendMessage([
                'chat_id' => $adminId,
                'text' => "❌ Error:
                " . $e->getMessage() . "
                
                File: " . basename($e->getFile()) . ":" . $e->getLine()
            ]);
            
            return response()->json(['status' => 'error'], 500);
        }
    }
    
    // Helper function
    private function getUpdateType($update): string
    {
        if ($update->getMessage()) return 'message';
        if ($update->getCallbackQuery()) return 'callback';
        if ($update->getEditedMessage()) return 'edited_message';
        return 'unknown';
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
