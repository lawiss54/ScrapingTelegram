<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\TelegramLogger;
use App\Models\{VerificationRequest, Subscription};

class AdminHandler
{
    protected TelegramLogger $logger;
    
    // مدد الخطط بالأيام
    protected array $planDurations = [
        'monthly' => 30,
        'quarterly' => 90,
        'semi_annual' => 180,
        'yearly' => 365,
    ];
    
    // أسعار الخطط
    protected array $planPrices = [
        'monthly' => 10,
        'quarterly' => 25,
        'semi_annual' => 45,
        'yearly' => 90,
    ];
    
    // أسماء الخطط بالعربية
    protected array $planNames = [
        'monthly' => 'شهري',
        'quarterly' => 'ربع سنوي',
        'semi_annual' => 'نصف سنوي',
        'yearly' => 'سنوي',
    ];
    
    public function __construct(TelegramLogger $logger)
    {
        $this->logger = $logger;
    }
    
    /**
     * الموافقة على الدفع
     */
    public function approvePayment($data, $callbackQuery)
    {
        $adminId = $callbackQuery->getFrom()->getId();

        // التحقق من صلاحيات الأدمن
        if (!$this->isAdmin($adminId)) {
            $this->sendUnauthorizedMessage($callbackQuery->getId());
            return;
        }

        $requestId = str_replace('approve_', '', $data);
        
        $this->logger->info("Approving payment", [
            'request_id' => $requestId,
            'admin_id' => $adminId
        ]);
        
        $request = VerificationRequest::find($requestId);
        
        $this->logger->info("Request loaded", [
            'request_id' => $requestId,
            'found' => $request ? 'yes' : 'no',
            'status' => $request ? $request->status : 'null',
            'user_id' => $request ? $request->user_id : 'null'
        ]);

        // التحقق من صحة الطلب
        if (!$this->isValidRequest($request, $callbackQuery->getId(), $callbackQuery)) {
            $this->logger->warning("Invalid request - stopping execution", [
                'request_id' => $requestId
            ]);
            return;
        }
        
        $this->logger->info("Request is valid, proceeding with approval", [
            'request_id' => $requestId
        ]);

        try {
            // تحديث حالة الطلب
            $this->logger->info("Updating request status to approved", [
                'request_id' => $requestId
            ]);
            
            $request->update([
                'status' => 'approved',
                'reviewed_at' => now(),
            ]);
            
            $this->logger->info("Request status updated successfully", [
                'request_id' => $requestId,
                'new_status' => $request->status
            ]);

            // إنشاء الاشتراك
            $subscription = $this->createSubscription($request);
            
            $this->logger->info("Subscription created", [
                'subscription_id' => $subscription->id,
                'user_id' => $request->user_id
            ]);

            // تفعيل المستخدم
            $request->user->update(['is_active' => true]);

            // تحديث رسالة الأدمن
            $this->logger->info("Attempting to edit admin message", [
                'request_id' => $requestId
            ]);
            
            try {
                $chatId = $callbackQuery->getMessage()->getChat()->getId();
                $messageId = $callbackQuery->getMessage()->getMessageId();
                
                $this->logger->info("Admin message details", [
                    'chat_id' => $chatId,
                    'message_id' => $messageId
                ]);
                
                Telegram::editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' =>
                        "✅ <b>تمت الموافقة على الطلب</b>\n\n" .
                        "🔖 رقم الطلب: <code>#{$requestId}</code>\n" .
                        "👤 المستخدم: {$request->user->first_name}\n" .
                        "📦 الخطة: {$request->plan_type}\n" .
                        "💰 السعر: \${$this->planPrices[$request->plan_type]}\n" .
                        "⏰ تاريخ الموافقة: " . now()->format('Y-m-d H:i') . "\n" .
                        "👨‍💼 بواسطة: Admin\n\n" .
                        "✅ تم تفعيل الاشتراك",
                    'parse_mode' => 'HTML'
                ]);
                
                $this->logger->info("Admin message updated successfully", [
                    'request_id' => $requestId
                ]);
                
            } catch (\Exception $editError) {
                $this->logger->error("Failed to edit admin message", [
                    'request_id' => $requestId,
                    'error' => $editError->getMessage(),
                    'error_code' => $editError->getCode()
                ]);
                
                // إرسال رسالة جديدة بدلاً من التعديل
                try {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' =>
                            "✅ <b>تمت الموافقة على الطلب</b>\n\n" .
                            "🔖 رقم الطلب: <code>#{$requestId}</code>\n" .
                            "👤 المستخدم: {$request->user->first_name}\n" .
                            "📦 الخطة: {$request->plan_type}",
                        'parse_mode' => 'HTML'
                    ]);
                    
                    $this->logger->info("Sent new admin message instead");
                } catch (\Exception $sendError) {
                    $this->logger->error("Failed to send new admin message", [
                        'error' => $sendError->getMessage()
                    ]);
                }
            }

            // إرسال رسالة ترحيبية للمستخدم
            $this->logger->info("Attempting to send welcome message to user", [
                'request_id' => $requestId,
                'user_id' => $request->user_id
            ]);
            
            try {
                $this->sendWelcomeMessage($request->user, $subscription);
                
                $this->logger->info("Welcome message sent to user successfully", [
                    'request_id' => $requestId,
                    'user_id' => $request->user_id
                ]);
            } catch (\Exception $userError) {
                $this->logger->error("Failed to send welcome message", [
                    'request_id' => $requestId,
                    'user_id' => $request->user_id,
                    'error' => $userError->getMessage(),
                    'trace' => $userError->getTraceAsString()
                ]);
            }

            // الرد على الـ callback
            $this->logger->info("Answering callback query", [
                'request_id' => $requestId
            ]);
            
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '✅ تمت الموافقة',
            ]);
            
            $this->logger->success("Payment approved successfully - ALL STEPS COMPLETED", [
                'request_id' => $requestId,
                'subscription_id' => $subscription->id
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error("CRITICAL ERROR in approvePayment", [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString()
            ]);
            
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '⚠️ حدث خطأ في الموافقة',
                'show_alert' => true
            ]);
        }
    }
    
    /**
     * رفض الدفع
     */
    public function rejectPayment($data, $callbackQuery)
    {
        $adminId = $callbackQuery->getFrom()->getId();

        // التحقق من صلاحيات الأدمن
        if (!$this->isAdmin($adminId)) {
            $this->sendUnauthorizedMessage($callbackQuery->getId());
            return;
        }

        $requestId = str_replace('reject_', '', $data);
        
        $this->logger->info("Rejecting payment", [
            'request_id' => $requestId,
            'admin_id' => $adminId
        ]);
        
        $request = VerificationRequest::find($requestId);
        
        $this->logger->info("Request loaded", [
            'request_id' => $requestId,
            'found' => $request ? 'yes' : 'no',
            'status' => $request ? $request->status : 'null',
            'user_id' => $request ? $request->user_id : 'null'
        ]);

        // التحقق من صحة الطلب
        if (!$this->isValidRequest($request, $callbackQuery->getId(), $callbackQuery)) {
            $this->logger->warning("Invalid request - stopping execution", [
                'request_id' => $requestId
            ]);
            return;
        }

        try {
            // تحديث حالة الطلب
            $this->logger->info("Updating request status to rejected", [
                'request_id' => $requestId
            ]);
            
            $request->update([
                'status' => 'rejected',
                'reviewed_at' => now(),
            ]);
            
            $this->logger->info("Request status updated successfully", [
                'request_id' => $requestId,
                'new_status' => $request->status
            ]);

            // تحديث رسالة الأدمن
            $this->logger->info("Attempting to edit admin message", [
                'request_id' => $requestId
            ]);
            
            try {
                $chatId = $callbackQuery->getMessage()->getChat()->getId();
                $messageId = $callbackQuery->getMessage()->getMessageId();
                
                $this->logger->info("Admin message details", [
                    'chat_id' => $chatId,
                    'message_id' => $messageId
                ]);
                
                Telegram::editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' =>
                        "❌ <b>تم رفض الطلب</b>\n\n" .
                        "🔖 رقم الطلب: <code>#{$requestId}</code>\n" .
                        "👤 المستخدم: {$request->user->first_name}\n" .
                        "📦 الخطة: {$request->plan_type}\n" .
                        "⏰ تاريخ الرفض: " . now()->format('Y-m-d H:i') . "\n" .
                        "👨‍💼 بواسطة: Admin",
                    'parse_mode' => 'HTML'
                ]);
                
                $this->logger->info("Admin message updated successfully", [
                    'request_id' => $requestId
                ]);
                
            } catch (\Exception $editError) {
                $this->logger->error("Failed to edit admin message", [
                    'request_id' => $requestId,
                    'error' => $editError->getMessage(),
                    'error_code' => $editError->getCode()
                ]);
                
                // إرسال رسالة جديدة بدلاً من التعديل
                try {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' =>
                            "❌ <b>تم رفض الطلب</b>\n\n" .
                            "🔖 رقم الطلب: <code>#{$requestId}</code>\n" .
                            "👤 المستخدم: {$request->user->first_name}\n" .
                            "📦 الخطة: {$request->plan_type}",
                        'parse_mode' => 'HTML'
                    ]);
                    
                    $this->logger->info("Sent new admin message instead");
                } catch (\Exception $sendError) {
                    $this->logger->error("Failed to send new admin message", [
                        'error' => $sendError->getMessage()
                    ]);
                }
            }

            // إرسال رسالة رفض للمستخدم
            $this->logger->info("Attempting to send rejection to user", [
                'request_id' => $requestId,
                'user_id' => $request->user_id
            ]);
            
            try {
                $this->sendRejectionMessage($request);
                
                $this->logger->info("Rejection message sent to user successfully", [
                    'request_id' => $requestId,
                    'user_id' => $request->user_id
                ]);
            } catch (\Exception $userError) {
                $this->logger->error("Failed to send rejection to user", [
                    'request_id' => $requestId,
                    'user_id' => $request->user_id,
                    'error' => $userError->getMessage(),
                    'trace' => $userError->getTraceAsString()
                ]);
            }

            // الرد على الـ callback
            $this->logger->info("Answering callback query", [
                'request_id' => $requestId
            ]);
            
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '❌ تم رفض الطلب',
            ]);
            
            $this->logger->success("Payment rejected successfully - ALL STEPS COMPLETED", [
                'request_id' => $requestId
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error("CRITICAL ERROR in rejectPayment", [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString()
            ]);
            
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '⚠️ حدث خطأ في رفض الطلب',
                'show_alert' => true
            ]);
        }
    }
    
    /**
     * إنشاء اشتراك جديد
     */
    protected function createSubscription(VerificationRequest $request): Subscription
    {
        return Subscription::create([
            'user_id' => $request->user_id,
            'plan_type' => $request->plan_type,
            'price' => $this->planPrices[$request->plan_type],
            'starts_at' => now(),
            'ends_at' => now()->addDays($this->planDurations[$request->plan_type]),
            'is_active' => true,
            'is_trial' => false,
            'status' => 'active',
        ]);
    }
    
    /**
     * إرسال رسالة ترحيبية بعد الموافقة
     */
    protected function sendWelcomeMessage($user, Subscription $subscription)
    {
        $daysLeft = now()->diffInDays($subscription->ends_at);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 بدء الاستخدام', 'callback_data' => 'start_using']
                ],
                [
                    ['text' => '📊 معلومات الاشتراك', 'callback_data' => 'subscription_info'],
                    ['text' => '❓ مساعدة', 'callback_data' => 'help']
                ]
            ]
        ];

        Telegram::sendMessage([
            'chat_id' => $user->telegram_id,
            'text' =>
                "🎉 مبروك! تم تفعيل اشتراكك\n\n" .
                "📋 معلومات الاشتراك:\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "📦 الخطة: {$this->planNames[$subscription->plan_type]}\n" .
                "💰 السعر: \${$subscription->price}\n" .
                "📅 البداية: " . $subscription->starts_at->format('Y-m-d') . "\n" .
                "📅 الانتهاء: " . $subscription->ends_at->format('Y-m-d') . "\n" .
                "⏰ المتبقي: {$daysLeft} يوم\n" .
                "━━━━━━━━━━━━━━━━━━\n\n" .
                "اضغط على «بدء الاستخدام» للبدء 🚀",
            'reply_markup' => json_encode($keyboard),
        ]);
    }
    
    /**
     * إرسال رسالة رفض للمستخدم
     */
    protected function sendRejectionMessage(VerificationRequest $request)
    {
        $this->logger->info("Preparing rejection message for user", [
            'request_id' => $request->id,
            'user_id' => $request->user_id,
            'telegram_id' => $request->user->telegram_id ?? 'null'
        ]);
        
        // التحقق من وجود المستخدم
        if (!$request->user) {
            $this->logger->error("User not found for request", [
                'request_id' => $request->id,
                'user_id' => $request->user_id
            ]);
            throw new \Exception("User not found");
        }
        
        // التحقق من وجود telegram_id
        if (!$request->user->telegram_id) {
            $this->logger->error("User has no telegram_id", [
                'request_id' => $request->id,
                'user_id' => $request->user_id
            ]);
            throw new \Exception("User has no telegram_id");
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 إعادة المحاولة', 'callback_data' => 'show_subscriptions']
                ],
                [
                    ['text' => '💬 التواصل مع الدعم', 'url' => 'https://t.me/YourSupportBot']
                ],
                [
                    ['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'back_to_start']
                ]
            ]
        ];
        
        $this->logger->info("Sending rejection message to user", [
            'request_id' => $request->id,
            'telegram_id' => $request->user->telegram_id
        ]);
        
        try {
            $result = Telegram::sendMessage([
                'chat_id' => $request->user->telegram_id,
                'text' =>
                    "❌ <b>تم رفض طلب الدفع</b>\n\n" .
                    "🔖 رقم الطلب: <code>#{$request->id}</code>\n" .
                    "📦 الخطة: {$request->plan_type}\n\n" .
                    "⚠️ <b>الأسباب المحتملة:</b>\n" .
                    "• صورة إثبات الدفع غير واضحة\n" .
                    "• المبلغ المدفوع غير مطابق\n" .
                    "• معلومات الدفع غير صحيحة\n" .
                    "• رقم العملية غير صحيح\n\n" .
                    "💡 <b>يمكنك:</b>\n" .
                    "• إعادة المحاولة بإثبات دفع واضح\n" .
                    "• التواصل مع الدعم الفني\n\n" .
                    "نعتذر عن الإزعاج 🙏",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ]);
            
            $this->logger->success("Rejection message sent successfully to user", [
                'request_id' => $request->id,
                'telegram_id' => $request->user->telegram_id,
                'message_id' => $result->getMessageId() ?? 'unknown'
            ]);
            
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $telegramError) {
            $this->logger->error("Telegram API error sending rejection", [
                'request_id' => $request->id,
                'telegram_id' => $request->user->telegram_id,
                'error' => $telegramError->getMessage(),
                'error_code' => $telegramError->getCode()
            ]);
            
            // أخطاء شائعة
            if (strpos($telegramError->getMessage(), 'bot was blocked') !== false) {
                $this->logger->warning("User blocked the bot", [
                    'user_id' => $request->user_id
                ]);
            } elseif (strpos($telegramError->getMessage(), 'user is deactivated') !== false) {
                $this->logger->warning("User account is deactivated", [
                    'user_id' => $request->user_id
                ]);
            }
            
            throw $telegramError;
            
        } catch (\Exception $generalError) {
            $this->logger->error("General error sending rejection", [
                'request_id' => $request->id,
                'error' => $generalError->getMessage(),
                'line' => $generalError->getLine(),
                'file' => $generalError->getFile()
            ]);
            
            throw $generalError;
        }
    }
    
    /**
     * التحقق من صحة الطلب
     */
    protected function isValidRequest(?VerificationRequest $request, $callbackId, $callbackQuery = null): bool
    {
        if (!$request) {
            $this->logger->error("Request not found", ['request_id' => 'null']);
            
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => '⚠️ الطلب غير موجود',
                'show_alert' => true,
            ]);
            return false;
        }
        
        if ($request->status !== 'pending') {
            $this->logger->warning("Request already processed", [
                'request_id' => $request->id,
                'current_status' => $request->status,
                'reviewed_at' => $request->reviewed_at
            ]);
            
            // رسالة توضيحية حسب الحالة
            $statusMessages = [
                'approved' => '✅ تمت الموافقة على هذا الطلب مسبقاً',
                'rejected' => '❌ تم رفض هذا الطلب مسبقاً',
            ];
            
            $statusEmojis = [
                'approved' => '✅',
                'rejected' => '❌',
            ];
            
            $message = $statusMessages[$request->status] ?? '⚠️ تمت معالجة هذا الطلب مسبقاً';
            $emoji = $statusEmojis[$request->status] ?? '⚠️';
            
            // محاولة تحديث رسالة الأدمن لتوضيح الحالة
            if ($callbackQuery) {
                try {
                    $reviewedTime = $request->reviewed_at ? $request->reviewed_at->format('Y-m-d H:i') : 'غير محدد';
                    
                    Telegram::editMessageText([
                        'chat_id' => $callbackQuery->getMessage()->getChat()->getId(),
                        'message_id' => $callbackQuery->getMessage()->getMessageId(),
                        'text' =>
                            "{$emoji} <b>تمت المعالجة مسبقاً</b>\n\n" .
                            "🔖 رقم الطلب: <code>#{$request->id}</code>\n" .
                            "📊 الحالة: <b>{$request->status}</b>\n" .
                            "👤 المستخدم: {$request->user->first_name}\n" .
                            "📦 الخطة: {$request->plan_type}\n" .
                            "⏰ تاريخ المعالجة: {$reviewedTime}\n\n" .
                            "⚠️ لا يمكن معالجة الطلب مرة أخرى",
                        'parse_mode' => 'HTML'
                    ]);
                    
                    $this->logger->info("Updated admin message with 'already processed' status");
                    
                } catch (\Exception $e) {
                    $this->logger->warning("Could not update admin message", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => $message,
                'show_alert' => true,
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * التحقق من صلاحيات الأدمن
     */
    protected function isAdmin($telegramId): bool
    {
        return in_array($telegramId, config('telegram.bots.mybot.admin_ids', []));
    }
    
    /**
     * رسالة: غير مصرح
     */
    protected function sendUnauthorizedMessage($callbackId)
    {
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text' => '❌ غير مصرح لك',
            'show_alert' => true,
        ]);
    }
}