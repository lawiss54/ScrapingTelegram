<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\{TelegramLogger, AdminNotificationService};
use App\Models\{User, VerificationRequest};

class PaymentHandler
{
    protected TelegramLogger $logger;
    
    public function __construct(TelegramLogger $logger)
    {
        $this->logger = $logger;
    }
    
    /**
     * طلب إثبات الدفع (الخطوة 1: صورة)
     */
    public function requestPaymentProof($data, $user, $chatId, $callbackId)
    {
        $planType = str_replace('confirm_payment_', '', $data);
        
        
        // تعيين حالة المستخدم
        cache()->put("user_state_{$chatId}", 'waiting_payment_proof', now()->addHours(1));
        cache()->put("selected_plan_{$chatId}", $planType, now()->addHours(1));

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ إلغاء العملية', 'callback_data' => 'cancel_payment']]
            ]
        ];

        Telegram::sendMessage([
            'chat_id' => $chatId,
                "📸 <b>الخطوة 1 من 2:</b> إرسال إثبات الدفع\n\n" .
                "الرجاء إرسال صورة ( لا ترسل ملف صورة فقط ) توضح:\n" .
                "• إيصال الدفع 📷\n" .
                "• لقطة شاشة من التحويل 📱\n" .
                "• أي إثبات للعملية 🧾\n\n" .
                "⚠️ تأكد من وضوح الصورة!\n\n" .
                "💡 <i>بعد إرسال الصورة، اكمل الخطوة 2</i>",
            'text' =>'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text' => '📸 أرسل صورة إثبات الدفع الآن',
        ]);
    }
    
    /**
     * معالجة إثبات الدفع (صورة + رقم عملية)
     */
    public function handlePaymentProof($message, User $user)
    {
        $chatId = $message->getChat()->getId();
        $userState = cache()->get("user_state_{$chatId}");
        
        // 🔍 DEBUG: فحص Message object
        $photos = $message->getPhoto();
        $text = $message->getText();
        
        // التحقق من الحالة
        if (!in_array($userState, ['waiting_payment_proof', 'waiting_transaction_id'])) {
            return;
        }
        
        // الخطوة 1: استلام الصورة
        if ($userState === 'waiting_payment_proof') {
            
            
            // ✅ التحقق الصحيح: استخدام empty() بدلاً من is_array()
            if ($photos && !empty($photos)) {
                $photoCount = is_countable($photos) ? count($photos) : 'unknown';
                $this->handlePaymentImage($message, $user, $chatId);
            } else {
                $this->requestValidImage($chatId);
            }
            return;
        }
        
        // الخطوة 2: استلام رقم العملية
        if ($userState === 'waiting_transaction_id') {
            
            // التحقق من أن الرسالة نص فقط (بدون صورة)
            if ($text && empty($photos)) {
                
                $this->handleTransactionId($message, $user, $chatId);
            } else {
                
                $this->requestValidTransactionId($chatId);
            }
            return;
        }
    }
    
    /**
     * معالجة صورة الدفع
     */
    protected function handlePaymentImage($message, User $user, $chatId)
    {
        try {
            $photos = $message->getPhoto();
            
            // التحقق من وجود الصور
            if (empty($photos)) {
                
                $this->requestValidImage($chatId);
                return;
            }
            
            // الحصول على أكبر حجم صورة
            // $photos يمكن أن يكون array of objects أو array of arrays
            $largestPhoto = is_array($photos) ? end($photos) : $photos[count($photos) - 1];
            
            // استخراج file_id
            $paymentProof = null;
            if (is_object($largestPhoto) && method_exists($largestPhoto, 'getFileId')) {
                $paymentProof = $largestPhoto->getFileId();
            } elseif (is_array($largestPhoto) && isset($largestPhoto['file_id'])) {
                $paymentProof = $largestPhoto['file_id'];
            } elseif (is_object($largestPhoto) && isset($largestPhoto->file_id)) {
                $paymentProof = $largestPhoto->file_id;
            }
            
            if (!$paymentProof) {
                
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => '⚠️ حدث خطأ في معالجة الصورة. الرجاء المحاولة مرة أخرى.'
                ]);
                return;
            }
            
            $this->logger->info("Payment image received successfully", [
                'user_id' => $user->id,
                'file_id' => $paymentProof
            ]);
            
            // حفظ الصورة وتغيير الحالة
            cache()->put("payment_proof_{$chatId}", $paymentProof, now()->addHours(1));
            cache()->put("user_state_{$chatId}", 'waiting_transaction_id', now()->addHours(1));
            
            $this->logger->info("Cache updated", [
                'user_id' => $user->id,
                'payment_proof' => $paymentProof,
                'new_state' => 'waiting_transaction_id'
            ]);
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '❌ إلغاء العملية', 'callback_data' => 'cancel_payment']]
                ]
            ];
            
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "✅ <b>تم استلام الصورة!</b>\n\n" .
                    "📝 <b>الخطوة 2 من 2:</b> رقم معاملة  \n\n" .
                    "الرجاء إرسال رقم العملية (Binance Order ID ) اذا ارسلت بريدي موب اكتب فقط ( بريديموب )\n" .
                    "مثال: 397732846026694657 ",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ]);
            
            
        } catch (\Exception $e) {
            
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '⚠️ حدث خطأ في معالجة الصورة. الرجاء المحاولة مرة أخرى.'
            ]);
        }
    }
    /**
     * معالجة رقم العملية
     */
    protected function handleTransactionId($message, User $user, $chatId)
    {
        $transactionId = $message->getText();
        $planType = cache()->get("selected_plan_{$chatId}");
        $paymentProof = cache()->get("payment_proof_{$chatId}");
        
        if (!$planType || !$paymentProof) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '⚠️ انتهت جلسة الدفع. الرجاء البدء من جديد.',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '🔄 العودة للقائمة', 'callback_data' => 'back_to_start']]
                    ]
                ])
            ]);
            $this->clearUserCache($chatId);
            return;
        }
        
        
        try {
            // إنشاء طلب التحقق
            $request = $this->createVerificationRequest($user, $planType, $paymentProof, $transactionId);
            
            
            // مسح الحالة
            $this->clearUserCache($chatId);
            
            // إرسال للأدمن (with error handling)
            try {
                app(AdminNotificationService::class)->sendVerificationRequest($request);
            } catch (\Exception $adminError) {
                
                // نستمر حتى لو فشل إرسال للأدمن
            }
            
            // تأكيد للمستخدم
            $this->sendConfirmationMessage($chatId, $request, $planType, $transactionId);
            
            
        } catch (\Exception $e) {
            
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '⚠️ حدث خطأ في معالجة الطلب. الرجاء المحاولة مرة أخرى.',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '🔄 العودة للقائمة', 'callback_data' => 'back_to_start']]
                    ]
                ])
            ]);
        }
    }
    
    /**
     * تخطي رقم العملية
     */
    public function skipTransactionId($user, $chatId, $callbackId)
    {
        $this->logger->info("Transaction ID skipped - START", ['user_id' => $user->id]);
        
        // ✅ Debounce: منع التنفيذ المتعدد
        $lockKey = "skip_lock_{$user->id}";
        
        if (cache()->has($lockKey)) {
            $this->logger->warning("Skip already in progress - IGNORED", [
                'user_id' => $user->id
            ]);
            
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => '⏳ جاري المعالجة...'
            ]);
            return;
        }
        
        // قفل لمدة 15 ثانية
        cache()->put($lockKey, true, now()->addSeconds(15));
        
        try {
            $planType = cache()->get("selected_plan_{$chatId}");
            $paymentProof = cache()->get("payment_proof_{$chatId}");
            
            $this->logger->info("Checking cache for skip", [
                'user_id' => $user->id,
                'plan_type' => $planType,
                'payment_proof' => $paymentProof ? 'exists' : 'missing'
            ]);
            
            if (!$planType || !$paymentProof) {
                $this->logger->error("Missing cache data for skip", [
                    'user_id' => $user->id,
                    'plan_type' => $planType,
                    'payment_proof' => $paymentProof
                ]);
                
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackId,
                    'text' => '⚠️ حدث خطأ. الرجاء المحاولة مرة أخرى',
                    'show_alert' => true
                ]);
                return;
            }
            
            // إنشاء طلب بدون رقم عملية
            $request = $this->createVerificationRequest($user, $planType, $paymentProof, null);
            
            $this->logger->info("Verification request created (skipped)", [
                'request_id' => $request->id,
                'user_id' => $user->id
            ]);
            
            // مسح الحالة BEFORE sending messages
            $this->clearUserCache($chatId);
            $this->logger->info("Cache cleared after skip", ['user_id' => $user->id]);
            
            // إرسال للأدمن
            app(AdminNotificationService::class)->sendVerificationRequest($request);
            $this->logger->info("Sent to admin", ['request_id' => $request->id]);
            
            // تأكيد للمستخدم
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "✅ <b>تم استلام طلبك!</b>\n\n" .
                    "🔖 رقم الطلب: <code>#{$request->id}</code>\n" .
                    "📦 الخطة: {$planType}\n\n" .
                    "⏳ جاري المراجعة...\n" .
                    "⏱️ عادة يتم الرد خلال 15-30 دقيقة\n\n" .
                    "سنرسل لك إشعاراً فور الموافقة! 🔔",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'back_to_start']]
                    ]
                ])
            ]);
            
            // ✅ IMPORTANT: Answer callback to stop loading
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => '✅ تم إرسال الطلب'
            ]);
            
            $this->logger->success("Skip transaction completed - END", [
                'request_id' => $request->id,
                'user_id' => $user->id
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error("Error in skipTransactionId", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => '⚠️ حدث خطأ. حاول مرة أخرى',
                'show_alert' => true
            ]);
        } finally {
            // ✅ إزالة القفل دائماً
            cache()->forget($lockKey);
            $this->logger->info("Lock released", ['user_id' => $user->id]);
        }
    }
    
    /**
     * إلغاء عملية الدفع
     */
    public function cancelPayment($user, $chatId, $messageId, $callbackId)
    {
        
        // مسح الحالة
        $this->clearUserCache($chatId);
        
        Telegram::editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => 
                "❌ تم إلغاء عملية الدفع\n\n" .
                "يمكنك البدء من جديد متى شئت!",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🔄 العودة للقائمة', 'callback_data' => 'back_to_start']]
                ]
            ])
        ]);
        
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text' => '❌ تم الإلغاء'
        ]);
    }
    
    /**
     * طلب صورة صحيحة
     */
    protected function requestValidImage($chatId)
    {
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
     * طلب رقم عملية صحيح
     */
    protected function requestValidTransactionId($chatId)
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 
                "⚠️ الرجاء إرسال رقم العملية كنص فقط\n\n" .
                "مثال: TRX123456789",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '❌ إلغاء العملية', 'callback_data' => 'cancel_payment']]
                ]
            ])
        ]);
    }
    
    /**
     * إنشاء طلب التحقق
     */
    protected function createVerificationRequest(User $user, string $planType, string $paymentProof, ?string $transactionId): VerificationRequest
    {
        return VerificationRequest::create([
            'user_id' => $user->id,
            'plan_type' => $planType,
            'payment_proof' => $paymentProof,
            'transaction_id' => $transactionId,
            'status' => 'pending',
        ]);
    }
    
    /**
     * إرسال رسالة التأكيد
     */
    protected function sendConfirmationMessage($chatId, VerificationRequest $request, string $planType, ?string $transactionId)
    {
        $transactionText = $transactionId 
            ? "🔢 رقم العملية: {$transactionId}\n" 
            : "";
        
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "✅ <b>تم استلام طلبك!</b>\n\n" .
                "🔖 رقم الطلب: <code>#{$request->id}</code>\n" .
                "📦 الخطة: {$planType}\n" .
                $transactionText . "\n" .
                "⏳ جاري المراجعة...\n" .
                "⏱️ عادة يتم الرد خلال 15-30 دقيقة\n\n" .
                "سنرسل لك إشعاراً فور الموافقة! 🔔",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'back_to_start']]
                ]
            ])
        ]);
    }
    
    /**
     * مسح بيانات المستخدم من الـ Cache
     */
    protected function clearUserCache($chatId)
    {
        cache()->forget("user_state_{$chatId}");
        cache()->forget("selected_plan_{$chatId}");
        cache()->forget("payment_proof_{$chatId}");
    }
}