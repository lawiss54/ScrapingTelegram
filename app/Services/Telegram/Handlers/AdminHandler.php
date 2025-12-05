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
        if (!$this->isValidRequest($request, $callbackQuery->getId())) {
            $this->logger->warning("Invalid request - stopping execution", [
                'request_id' => $requestId
            ]);
            return;
        }

        try {
            // تحديث حالة الطلب
            $request->update([
                'status' => 'approved',
                'reviewed_at' => now(),
            ]);
            
            $this->logger->info("Request status updated to approved", [
                'request_id' => $requestId
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
            try {
                Telegram::editMessageText([
                    'chat_id' => $callbackQuery->getMessage()->getChat()->getId(),
                    'message_id' => $callbackQuery->getMessage()->getMessageId(),
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
                
                $this->logger->info("Admin message updated", ['request_id' => $requestId]);
                
            } catch (\Exception $editError) {
                $this->logger->error("Failed to edit admin message", [
                    'request_id' => $requestId,
                    'error' => $editError->getMessage()
                ]);
                
                // إرسال رسالة جديدة بدلاً من التعديل
                Telegram::sendMessage([
                    'chat_id' => $callbackQuery->getMessage()->getChat()->getId(),
                    'text' =>
                        "✅ <b>تمت الموافقة على الطلب</b>\n\n" .
                        "🔖 رقم الطلب: <code>#{$requestId}</code>\n" .
                        "👤 المستخدم: {$request->user->first_name}\n" .
                        "📦 الخطة: {$request->plan_type}",
                    'parse_mode' => 'HTML'
                ]);
            }

            // إرسال رسالة ترحيبية للمستخدم
            try {
                $this->sendWelcomeMessage($request->user, $subscription);
                $this->logger->info("Welcome message sent to user", [
                    'request_id' => $requestId,
                    'user_id' => $request->user_id
                ]);
            } catch (\Exception $userError) {
                $this->logger->error("Failed to send welcome message", [
                    'request_id' => $requestId,
                    'error' => $userError->getMessage()
                ]);
            }

            // الرد على الـ callback
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '✅ تمت الموافقة',
            ]);
            
            $this->logger->success("Payment approved successfully", [
                'request_id' => $requestId,
                'subscription_id' => $subscription->id
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error("Error in approvePayment", [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
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
        if (!$this->isValidRequest($request, $callbackQuery->getId())) {
            $this->logger->warning("Invalid request - stopping execution", [
                'request_id' => $requestId
            ]);
            return;
        }

        // تحديث حالة الطلب
        $request->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
        ]);

        // تحديث رسالة الأدمن
        $this->updateAdminMessage($callbackQuery, $requestId, $request, 'rejected');

        // إرسال رسالة رفض للمستخدم
        $this->sendRejectionMessage($request);

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
            'text' => '❌ تم الرفض',
        ]);
        
        $this->logger->warning("Payment rejected", ['request_id' => $requestId]);
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
        Telegram::sendMessage([
            'chat_id' => $request->user->telegram_id,
            'text' =>
                "❌ لم يتم قبول طلب الدفع\n\n" .
                "🔖 رقم الطلب: #{$request->id}\n" .
                "الأسباب المحتملة:\n" .
                "• معلومات الدفع غير واضحة\n" .
                "• المبلغ غير مطابق\n" .
                "• بيانات خاطئة\n\n" .
                "💬 يمكنك إعادة المحاولة أو التواصل مع الدعم",
        ]);
    }
    
    /**
     * التحقق من صلاحيات الأدمن
     */
    protected function isAdmin($telegramId): bool
    {
        return in_array($telegramId, config('telegram.bots.mybot.admin_ids', []));
    }
    
    /**
     * التحقق من صحة الطلب
     */
    protected function isValidRequest(?VerificationRequest $request, $callbackId): bool
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
            
            $message = $statusMessages[$request->status] ?? '⚠️ تمت معالجة هذا الطلب مسبقاً';
            
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