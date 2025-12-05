<?php

namespace App\Services;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Models\{VerificationRequest, User};
use App\Services\TelegramLogger;

class AdminNotificationService
{
    /**
     * Logger مخصص لتتبع عمليات خدمة الإشعارات
     *
     * @var TelegramLogger
     */
    protected TelegramLogger $logger;

    /**
     * إنشاء الخدمة وتفعيل الـ Logger
     *
     * ملاحظة: يمكن اعتماد Dependency Injection مستقبلاً لتحسين الاختبارات
     */
    public function __construct()
    {
        $this->logger = new TelegramLogger();
    }

    /**
     * إرسال إشعار للأدمن بوصول طلب اشتراك جديد من مستخدم
     *
     * @param  VerificationRequest $request  طلب الاشتراك
     * @return void
     */
    public function sendVerificationRequest(VerificationRequest $request): void
    {
        // قائمة الأدمنز من ملف الإعدادات
        $adminIds = config('telegram.bots.mybot.admin_ids', []);

        if (empty($adminIds)) {
            $this->logger->error("No admin IDs configured");
            return;
        }

        // جلب بيانات المستخدم صاحب الطلب
        $user = User::find($request->user_id);

        $this->logger->info("User info fetched", [
            'user_info' => $user,
            'admin_ids' => $adminIds,
        ]);

        // أسماء الخطط
        $planNames = [
            'monthly'      => 'شهري (30 يوم)',
            'quarterly'    => 'ربع سنوي (90 يوم)',
            'semi_annual'  => 'نصف سنوي (180 يوم)',
            'yearly'       => 'سنوي (365 يوم)',
        ];

        // الأسعار (يفضل لاحقاً وضعها في config)
        $planPrices = [
            'monthly'      => '$10',
            'quarterly'    => '$25',
            'semi_annual'  => '$45',
            'yearly'       => '$90',
        ];

        $planName  = $planNames[$request->plan_type] ?? $request->plan_type;
        $planPrice = $planPrices[$request->plan_type] ?? 'غير محدد';

        /**
         * بناء رسالة الإدمن
         * تحتوي:
         * - معلومات المستخدم
         * - معلومات الخطة
         * - رقم العملية (إن وُجد)
         * - حالة الطلب
         */
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

        // رقم العملية إن وُجد
        if ($request->transaction_id) {
            $messageText .=
                "🔢 <b>رقم العملية:</b>\n<code>{$request->transaction_id}</code>\n\n";
        }

        // إثبات الدفع إن وُجد
        if ($request->payment_proof) {
            $messageText .= "📸 <b>إثبات الدفع:</b> مرفق بالصورة\n\n";
        }

        $messageText .= "⏳ <b>الحالة:</b> قيد المراجعة\n";

        /**
         * لوحة التحكم الخاصة بالأدمن:
         * - زر الموافقة
         * - زر الرفض
         * - زر عرض الملف الشخصي للمستخدم
         */
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ موافقة', 'callback_data' => "approve_{$request->id}"],
                    ['text' => '❌ رفض', 'callback_data' => "reject_{$request->id}"],
                ],
                [
                    ['text' => '👤 عرض ملف المستخدم', 'callback_data' => "user_profile_{$user->id}"],
                ],
            ],
        ];

        /**
         * إرسال نفس الرسالة لكل الأدمنز
         * مع صورة إثبات الدفع إن وُجدت
         */
        foreach ($adminIds as $adminId) {
            try {
                // إرسال الرسالة النصية
                $sentMessage = Telegram::sendMessage([
                    'chat_id'      => $adminId,
                    'text'         => $messageText,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode($keyboard),
                ]);

                // إرسال الصورة (إن وُجدت)
                if ($request->payment_proof) {
                    Telegram::sendPhoto([
                        'chat_id' => $adminId,
                        'photo'   => $request->payment_proof,
                        'caption' =>
                            "📸 إثبات الدفع - طلب #{$request->id}\n" .
                            "المستخدم: {$user->first_name} (#{$user->id})",
                        'reply_to_message_id' => $sentMessage->getMessageId(),
                    ]);
                }

                $this->logger->success("Verification request sent to admin", [
                    'admin_id'   => $adminId,
                    'request_id' => $request->id,
                ]);

            } catch (\Exception $e) {
                // تسجيل الخطأ ومتابعة بقية الأدمنز
                $this->logger->error("Failed to send to admin", [
                    'admin_id' => $adminId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * إرسال تقرير موجز للأدمن عن الطلبات المعلقة خلال آخر 24 ساعة
     */
    public function sendPendingRequestsReminder(): void
    {
        // جلب الطلبات المعلقة خلال آخر 24 ساعة
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

        // إرسال الرسالة لكل الأدمنز
        foreach ($adminIds as $adminId) {
            try {
                Telegram::sendMessage([
                    'chat_id'    => $adminId,
                    'text'       => $message,
                    'parse_mode' => 'HTML',
                ]);
            } catch (\Exception $e) {
                $this->logger->error("Failed to send reminder", [
                    'admin_id' => $adminId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }
}