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
        $request = VerificationRequest::find($requestId);

        // التحقق من صحة الطلب
        if (!$this->isValidRequest($request, $callbackQuery->getId())) {
            return;
        }
        
        $this->logger->info("Approving payment", [
            'request_id' => $requestId,
            'admin_id' => $adminId
        ]);

        // تحديث حالة الطلب
        $request->update([
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        // إنشاء الاشتراك
        $subscription = $this->createSubscription($request);

        // تفعيل المستخدم
        $request->user->update(['is_active' => true]);

        // تحديث رسالة الأدمن
        $this->updateAdminMessage($callbackQuery, $requestId, $request, 'approved');

        // إرسال رسالة ترحيبية للمستخدم
        $this->sendWelcomeMessage($request->user, $subscription);

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
            'text' => '✅ تمت الموافقة',
        ]);
        
        $this->logger->success("Payment approved", [
            'request_id' => $requestId,
            'subscription_id' => $subscription->id
        ]);
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
        $request = VerificationRequest::find($requestId);

        // التحقق من صحة الطلب
        if (!$this->isValidRequest($request, $callbackQuery->getId())) {
            return;
        }
        
        $this->logger->info("Rejecting payment", [
            'request_id' => $requestId,
            'admin_id' => $adminId
        ]);

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
     * تحديث رسالة الأدمن
     */
    protected function updateAdminMessage($callbackQuery, $requestId, $request, $status)
    {
        $statusEmoji = $status === 'approved' ? '✅' : '❌';
        $statusText = $status === 'approved' ? 'تمت الموافقة على' : 'تم رفض';
        
        Telegram::editMessageText([
            'chat_id' => $callbackQuery->getMessage()->getChat()->getId(),
            'message_id' => $callbackQuery->getMessage()->getMessageId(),
            'text' =>
                "{$statusEmoji} {$statusText} الطلب #{$requestId}\n" .
                "المستخدم: {$request->user->first_name}\n" .
                "الخطة: {$request->plan_type}\n" .
                "بواسطة: Admin",
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
        if (!$request || $request->status !== 'pending') {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => '⚠️ تمت المعالجة مسبقاً',
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