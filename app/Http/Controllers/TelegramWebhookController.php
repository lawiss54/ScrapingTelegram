<?php

namespace App\Http\Controllers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\TelegramBotService;
use App\Models\User;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    protected TelegramBotService $botService;
    protected TelegramLogger $logger;
    
    public function __construct(TelegramBotService $botService)
    {
        $this->botService = $botService;
        $this->logger = new TelegramLogger();
    }
    
    public function handle(Request $request)
    {
        try {
            $update = Telegram::commandsHandler(true);
            
            // معالجة Callback Query (الأزرار)
            if ($update->has('callback_query')) {
                $this->botService->handleCallback($update->getCallbackQuery());
                return response()->json(['ok' => true]);
            }
            
            // معالجة الرسائل
            if ($update->has('message')) {
                $message = $update->getMessage();
                $this->handleMessage($message);
                return response()->json(['ok' => true]);
            }
            
            return response()->json(['ok' => true]);
            
        } catch (\Exception $e) {
            $this->logger->error("Webhook error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    protected function handleMessage($message)
    {
        $chatId = $message->getChat()->getId();
        $user = User::where('telegram_id', $chatId)->first();
        
        if (!$user) {
            $this->logger->warning("User not found", ['chat_id' => $chatId]);
            return;
        }
        
        // **الأهم: فحص حالة المستخدم أولاً**
        $userState = cache()->get("user_state_{$chatId}");
        
        $this->logger->info("Message received", [
            'user_id' => $user->id,
            'state' => $userState,
            'has_photo' => $message->has('photo'),
            'has_text' => $message->has('text')
        ]);
        
        // معالجة حسب الحالة
        switch ($userState) {
            case 'waiting_payment_proof':
                $this->handlePaymentProofInState($message, $user, $chatId);
                return;
                
            case 'waiting_transaction_id':
                $this->handleTransactionIdInState($message, $user, $chatId);
                return;
                
            default:
                // معالجة الرسائل العادية (الأوامر)
                $this->handleNormalMessage($message, $user, $chatId);
        }
    }
    
    /**
     * معالجة إثبات الدفع أثناء حالة الانتظار
     */
    protected function handlePaymentProofInState($message, $user, $chatId)
    {
        $planType = cache()->get("selected_plan_{$chatId}");
        
        if (!$planType) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '⚠️ انتهت جلسة الدفع. الرجاء البدء من جديد.',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '🔄 العودة للقائمة', 'callback_data' => 'back_to_start']]
                    ]
                ])
            ]);
            
            cache()->forget("user_state_{$chatId}");
            return;
        }
        
        // استقبال الصورة
        if ($message->has('photo')) {
            $photos = $message->getPhoto();
            $largestPhoto = end($photos);
            $paymentProof = $largestPhoto->getFileId();
            
            // حفظ الصورة مؤقتاً
            cache()->put("payment_proof_{$chatId}", $paymentProof, now()->addHours(1));
            
            $this->logger->info("Payment proof photo received", [
                'user_id' => $user->id,
                'plan' => $planType
            ]);
            
            // طلب رقم العملية
            cache()->put("user_state_{$chatId}", 'waiting_transaction_id', now()->addHours(1));
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '⏭️ تخطي رقم العملية', 'callback_data' => 'skip_transaction_id']],
                    [['text' => '❌ إلغاء', 'callback_data' => 'cancel_payment']]
                ]
            ];
            
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => 
                    "✅ تم استلام الصورة!\n\n" .
                    "🔢 الآن أرسل رقم العملية أو معرف التحويل\n\n" .
                    "أو اضغط «تخطي» إذا لم يكن متوفراً",
                'reply_markup' => json_encode($keyboard)
            ]);
            
            return;
        }
        
        // إذا لم يرسل صورة
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 
                "⚠️ الرجاء إرسال صورة إثبات الدفع\n\n" .
                "📸 يمكنك إرسال:\n" .
                "• صورة الإيصال\n" .
                "• لقطة شاشة من التحويل\n" .
                "• أي إثبات للعملية",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '❌ إلغاء العملية', 'callback_data' => 'cancel_payment']]
                ]
            ])
        ]);
    }
    
    /**
     * معالجة رقم العملية
     */
    protected function handleTransactionIdInState($message, $user, $chatId)
    {
        if (!$message->has('text')) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '⚠️ الرجاء إرسال رقم العملية كنص'
            ]);
            return;
        }
        
        $transactionId = $message->getText();
        $planType = cache()->get("selected_plan_{$chatId}");
        $paymentProof = cache()->get("payment_proof_{$chatId}");
        
        if (!$planType) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '⚠️ انتهت جلسة الدفع. الرجاء البدء من جديد.'
            ]);
            
            $this->clearUserState($chatId);
            return;
        }
        
        $this->logger->info("Transaction ID received", [
            'user_id' => $user->id,
            'plan' => $planType,
            'transaction_id' => $transactionId
        ]);
        
        // إنشاء طلب التحقق
        $this->createVerificationRequest($user, $planType, $paymentProof, $transactionId, $chatId);
    }
    
    /**
     * إنشاء طلب التحقق وإرساله للأدمن
     */
    protected function createVerificationRequest($user, $planType, $paymentProof, $transactionId, $chatId)
    {
        $request = \App\Models\VerificationRequest::create([
            'user_id' => $user->id,
            'plan_type' => $planType,
            'payment_proof' => $paymentProof,
            'transaction_id' => $transactionId,
            'status' => 'pending',
        ]);
        
        // مسح الحالة والكاش
        $this->clearUserState($chatId);
        
        // إرسال للأدمن
        app(\App\Services\AdminNotificationService::class)->sendVerificationRequest($request);
        
        // تأكيد للمستخدم
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🏠 العودة للقائمة الرئيسية', 'callback_data' => 'back_to_start']],
                [['text' => '📊 معلومات الاشتراك', 'callback_data' => 'subscription_info']]
            ]
        ];
        
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "✅ <b>تم استلام طلبك بنجاح!</b>\n\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "🔖 رقم الطلب: <code>#{$request->id}</code>\n" .
                "📦 الخطة: {$planType}\n" .
                ($transactionId ? "🔢 رقم العملية: <code>{$transactionId}</code>\n" : "") .
                "━━━━━━━━━━━━━━━━━━\n\n" .
                "⏳ جاري المراجعة من قبل الإدارة...\n" .
                "⏱️ عادة يتم الرد خلال <b>15-30 دقيقة</b>\n\n" .
                "سنرسل لك إشعاراً فور الموافقة! 🔔",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
        
        $this->logger->success("Verification request created", [
            'request_id' => $request->id,
            'user_id' => $user->id
        ]);
    }
    
    /**
     * معالجة الرسائل العادية (خارج سيناريو الدفع)
     */
    protected function handleNormalMessage($message, $user, $chatId)
    {
        // معالجة الأوامر
        if ($message->has('text')) {
            $text = $message->getText();
            
            switch ($text) {
                case '/start':
                    // يتم معالجته تلقائياً بواسطة commandsHandler
                    break;
                    
                case '/status':
                    $this->showStatus($user, $chatId);
                    break;
                    
                case '/help':
                    $this->showHelp($chatId);
                    break;
                    
                default:
                    // رسالة عادية - يمكن تجاهلها أو الرد عليها
                    $this->logger->info("Normal message", [
                        'user_id' => $user->id,
                        'text' => $text
                    ]);
            }
        }
    }
    
    /**
     * مسح حالة المستخدم والكاش
     */
    protected function clearUserState($chatId)
    {
        cache()->forget("user_state_{$chatId}");
        cache()->forget("selected_plan_{$chatId}");
        cache()->forget("payment_proof_{$chatId}");
        
        $this->logger->info("User state cleared", ['chat_id' => $chatId]);
    }
    
    protected function showStatus($user, $chatId)
    {
        $subscription = $user->activeSubscription;
        
        if (!$subscription) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "⚠️ ليس لديك اشتراك نشط حالياً\n\n" .
                         "استخدم /start للاشتراك",
            ]);
            return;
        }
        
        $daysLeft = now()->diffInDays($subscription->ends_at, false);
        $daysLeft = max(0, (int) ceil($daysLeft));
        
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 
                "📊 حالة اشتراكك:\n\n" .
                "✅ نشط\n" .
                "📦 الخطة: {$subscription->plan_type}\n" .
                "⏰ متبقي: {$daysLeft} يوم\n" .
                "📅 ينتهي: " . $subscription->ends_at->format('Y-m-d'),
        ]);
    }
    
    protected function showHelp($chatId)
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 
                "❓ المساعدة\n\n" .
                "/start - القائمة الرئيسية\n" .
                "/status - حالة الاشتراك\n" .
                "/help - المساعدة\n\n" .
                "📧 للدعم: support@yourdomain.com",
        ]);
    }
}