<?php

namespace App\Http\Controllers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\{TelegramBotService, TelegramLogger};
use App\Models\User;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    protected TelegramBotService $botService;
    protected TelegramLogger $logger;
    
    public function __construct(TelegramBotService $botService)
    {
        $this->botService = $botService;
        $this->logger = new TelegramLogger();
    }
    
    /**
     * معالج الـ Webhook الرئيسي
     */
    public function handle(Request $request)
    {
        try {
            // معالجة الأوامر تلقائياً (مثل /start)
            $update = Telegram::commandsHandler(true);
            
            $this->logger->info("Webhook received", [
                'has_callback' => $update->has('callback_query'),
                'has_message' => $update->has('message')
            ]);
            
            // توجيه حسب نوع الـ Update
            if ($update->has('callback_query')) {
                $this->handleCallbackQuery($update->getCallbackQuery());
            } elseif ($update->has('message')) {
                $this->handleMessage($update->getMessage());
            }
            
            return response()->json(['ok' => true]);
            
        } catch (\Exception $e) {
            $this->logger->error("Webhook error", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'ok' => false, 
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * معالجة Callback Query (الأزرار)
     */
    protected function handleCallbackQuery($callbackQuery)
    {
        $this->logger->info("Callback query received", [
            'data' => $callbackQuery->getData(),
            'from' => $callbackQuery->getFrom()->getId()
        ]);
        
        // تفويض المعالجة للـ BotService
        $this->botService->handleCallback($callbackQuery);
    }
    
    /**
     * معالجة الرسائل
     */
    protected function handleMessage($message)
    {
        $chatId = $message->getChat()->getId();
        
        // البحث عن المستخدم
        $user = User::where('telegram_id', $chatId)->first();
        
        if (!$user) {
            $this->handleUnregisteredUser($chatId);
            return;
        }
        
        $this->logger->info("Message received", [
            'user_id' => $user->id,
            'chat_id' => $chatId,
            'has_photo' => $message->getPhoto() ? 1 : 0,
            'has_text' => $message->getText() ? 1 : 0
        ]);
        
        // تفويض المعالجة للـ BotService
        $this->botService->handleMessage($message);
    }
    
    /**
     * معالجة مستخدم غير مسجل
     */
    protected function handleUnregisteredUser($chatId)
    {
        $this->logger->warning("Unregistered user", ['chat_id' => $chatId]);
        
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 
                "👋 مرحباً بك!\n\n" .
                "يبدو أنك مستخدم جديد.\n" .
                "الرجاء استخدام الأمر /start للبدء",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🚀 ابدأ الآن', 'callback_data' => 'register_user']]
                ]
            ])
        ]);
    }
}