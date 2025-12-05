<?php

namespace App\Services;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Models\VerificationRequest;

class AdminNotificationService
{
    protected TelegramLogger $logger;
    
    public function __construct()
    {
        $this->logger = new TelegramLogger();
    }
    
    public function sendVerificationRequest(VerificationRequest $request)
    {
        $adminIds = config('telegram.bots.mybot.admin_ids', []);
        
        if (empty($adminIds)) {
            $this->logger->error("No admin IDs configured");
            return;
        }
        $user = User::find($request->user_id);

        $this->logger->info("user info", [
            'user_info' => $user,
            'admin_ids' => $adminIds,
        ]);
        
        // تحضير معلومات الخطة
        $planNames = [
            'monthly' => 'شهري (30 يوم)',
            'quarterly' => 'ربع سنوي (90 يوم)',
            'semi_annual' => 'نصف سنوي (180 يوم)',
            'yearly' => 'سنوي (365 يوم)',
        ];
        
        $planPrices = [
            'monthly' => '$10',
            'quarterly' => '$25',
            'semi_annual' => '$45',
            'yearly' => '$90',
        ];
        
        $planName = $planNames[$request->plan_type] ?? $request->plan_type;
        $planPrice = $planPrices[$request->plan_type] ?? 'غير محدد';
        
        // تحضير نص الرسالة
        $messageText = 
            "🔔 <b>طلب اشتراك جديد</b>\n\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "👤 <b>معلومات المستخدم:</b>\n" .
            "• الاسم: {$user->first_name}\n" .
            "• المعرف: <code>{$user->telegram_id}</code>\n" .
            "• ID: #{$user->id}\n\n" .
            "📋 <b>معلومات الاشتراك:</b>\n" .
            "• الخطة: {$planName}\n" .
            "• السعر: {$planPrice}\n" .
            "• رقم الطلب: <code>#{$request->id}</code>\n" .
            "• التاريخ: " . now()->format('Y-m-d H:i') . "\n" .
            "━━━━━━━━━━━━━━━━━━\n\n";
        
        // إضافة معلومات إثبات الدفع
        if ($request->transaction_id) {
            $messageText .= "🔢 <b>رقم العملية:</b>\n<code>{$request->transaction_id}</code>\n\n";
        }
        
        if ($request->payment_proof) {
            $messageText .= "📸 <b>إثبات الدفع:</b> مرفق بالصورة\n\n";
        }
        
        $messageText .= "⏳ <b>الحالة:</b> قيد المراجعة\n";
        
        // أزرار الموافقة والرفض
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ موافقة', 'callback_data' => "approve_{$request->id}"],
                    ['text' => '❌ رفض', 'callback_data' => "reject_{$request->id}"]
                ],
                [
                    ['text' => '👤 عرض ملف المستخدم', 'callback_data' => "user_profile_{$user->id}"]
                ]
            ]
        ];
        
        // إرسال للأدمنز
        foreach ($adminIds as $adminId) {
            try {
                // إرسال النص أولاً
                $sentMessage = Telegram::sendMessage([
                    'chat_id' => $adminId,
                    'text' => $messageText,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode($keyboard)
                ]);
                
                // إرسال الصورة إن وجدت
                if ($request->payment_proof) {
                    Telegram::sendPhoto([
                        'chat_id' => $adminId,
                        'photo' => $request->payment_proof,
                        'caption' => "📸 إثبات الدفع - طلب #{$request->id}\n" .
                                   "المستخدم: {$user->first_name} (#{$user->id})",
                        'reply_to_message_id' => $sentMessage->getMessageId()
                    ]);
                }
                
                $this->logger->success("Verification request sent to admin", [
                    'admin_id' => $adminId,
                    'request_id' => $request->id
                ]);
                
            } catch (\Exception $e) {
                $this->logger->error("Failed to send to admin", [
                    'admin_id' => $adminId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * إرسال تذكير للأدمن بالطلبات المعلقة
     */
    public function sendPendingRequestsReminder()
    {
        $pendingRequests = VerificationRequest::where('status', 'pending')
            ->where('created_at', '>', now()->subHours(24))
            ->with('user')
            ->get();
            
        if ($pendingRequests->isEmpty()) {
            return;
        }
        
        $adminIds = config('telegram.bots.mybot.admin_ids', []);
        
        $message = "⚠️ <b>طلبات معلقة تحتاج مراجعة:</b>\n\n";
        
        foreach ($pendingRequests as $request) {
            $waitingTime = $request->created_at->diffForHumans();
            $message .= "• طلب #{$request->id} - {$request->user->first_name}\n";
            $message .= "  الخطة: {$request->plan_type} | منذ: {$waitingTime}\n\n";
        }
        
        foreach ($adminIds as $adminId) {
            try {
                Telegram::sendMessage([
                    'chat_id' => $adminId,
                    'text' => $message,
                    'parse_mode' => 'HTML'
                ]);
            } catch (\Exception $e) {
                $this->logger->error("Failed to send reminder", [
                    'admin_id' => $adminId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}