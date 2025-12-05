<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\TelegramLogger;
use App\Models\{VerificationRequest, Subscription};

class AdminHandler
{
    /**
     * Logger داخلي لتتبع كل عمليات الأدمن.
     *
     * @var TelegramLogger
     */
    protected TelegramLogger $logger;

    /**
     * عدد الأيام لكل خطة اشتراك.
     */
    protected array $planDurations = [
        'monthly'     => 30,
        'quarterly'   => 90,
        'semi_annual' => 180,
        'yearly'      => 365,
    ];

    /**
     * أسعار كل خطة.
     */
    protected array $planPrices = [
        'monthly'     => 10,
        'quarterly'   => 25,
        'semi_annual' => 45,
        'yearly'      => 90,
    ];

    /**
     * أسماء الخطط بالعربية.
     */
    protected array $planNames = [
        'monthly'     => 'شهري',
        'quarterly'   => 'ربع سنوي',
        'semi_annual' => 'نصف سنوي',
        'yearly'      => 'سنوي',
    ];

    /**
     * حقن الـ Logger في الخدمة.
     */
    public function __construct(TelegramLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * معالجة طلب الموافقة على الدفع.
     * - يتحقق من صلاحيات الأدمن
     * - يتحقق من صحة الطلب
     * - يوافق على الطلب ويُنشئ اشتراك
     * - يرسل رسالة ترحيب للمستخدم
     * - يعدّل رسالة الأدمن
     */
    public function approvePayment($data, $callbackQuery)
    {
        $adminId = $callbackQuery->getFrom()->getId();

        // التأكد أن هذا الشخص أدمن
        if (!$this->isAdmin($adminId)) {
            $this->sendUnauthorizedMessage($callbackQuery->getId());
            return;
        }

        // استخراج ID الطلب من callback_data
        $requestId = str_replace('approve_', '', $data);
        $request = VerificationRequest::find($requestId);

        // التحقق من أن الطلب موجود ولم تتم معالجته سابقاً
        if (!$this->isValidRequest($request, $callbackQuery->getId())) {
            return;
        }

        // تحديث حالة الطلب
        $request->update([
            'status'      => 'approved',
            'reviewed_at' => now(),
        ]);

        // إنشاء اشتراك جديد
        $subscription = $this->createSubscription($request);

        // تفعيل حساب المستخدم
        $request->user->update(['is_active' => true]);

        // تحديث الرسالة عند الأدمن
        $this->updateAdminMessage($callbackQuery, $requestId, $request, 'approved');

        // إرسال رسالة ترحيب للمستخدم
        $this->sendWelcomeMessage($request->user, $subscription);

        // رد فوري على ضغط الزر
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
            'text'              => '✅ تمت الموافقة',
        ]);
    }

    /**
     * معالجة طلب الرفض:
     * - يتحقق من الصلاحيات
     * - يتحقق من صحة الطلب
     * - يرفض الطلب
     * - يُحدّث الرسالة عند الأدمن
     * - يرسل رسالة توضيحية للمستخدم
     */
    public function rejectPayment($data, $callbackQuery)
    {
        $adminId = $callbackQuery->getFrom()->getId();

        if (!$this->isAdmin($adminId)) {
            $this->logger->info("is npt admin", ['user_id' => $adminId]);

            $this->sendUnauthorizedMessage($callbackQuery->getId());
            return;
        }

        $requestId = str_replace('reject_', '', $data);
        $request = VerificationRequest::find($requestId);
        $this->logger->info("request data", ['rrquest' => $request]);


        if (!$this->isValidRequest($request, $callbackQuery->getId())) {
            return;
        }
        $this->logger->info("start change status", ['rrquest' => $request]);


        // تغيير حالة الطلب إلى "مرفوض"
        $request->update([
            'status'      => 'rejected',
            'reviewed_at' => now(),
        ]);
        $request->save();
        $this->logger->info("end update status", ['rrquest' => $request]);


        // تحديث الرسالة للأدمن
        $this->logger->info("start update admine message", ['rrquest' => $request]);

        $this->updateAdminMessage($callbackQuery, $requestId, $request, 'rejected');

        // إعلام المستخدم بالرفض
        $this->logger->info("start send message ro client", ['rrquest' => $request]);

        $this->sendRejectionMessage($request);

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
            'text'              => '❌ تم الرفض',
        ]);
    }

    /**
     * إنشاء اشتراك جديد بناءً على طلب الدفع.
     */
    protected function createSubscription(VerificationRequest $request): Subscription
    {
        return Subscription::create([
            'user_id'   => $request->user_id,
            'plan_type' => $request->plan_type,
            'price'     => $this->planPrices[$request->plan_type],
            'starts_at' => now(),
            'ends_at'   => now()->addDays($this->planDurations[$request->plan_type]),
            'is_active' => true,
            'is_trial'  => false,
            'status'    => 'active',
        ]);
    }

    /**
     * إرسال رسالة ترحيبية بعد الموافقة.
     */
    protected function sendWelcomeMessage($user, Subscription $subscription)
    {
        $daysLeft = now()->diffInDays($subscription->ends_at);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 بدء الاستخدام', 'callback_data' => 'start_using'],
                ],
                [
                    ['text' => '📊 معلومات الاشتراك', 'callback_data' => 'subscription_info'],
                    ['text' => '❓ مساعدة', 'callback_data' => 'help'],
                ],
            ],
        ];

        Telegram::sendMessage([
            'chat_id' => $user->telegram_id,
            'text'    =>
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
     * إرسال رسالة الرفض للمستخدم.
     */
    protected function sendRejectionMessage(VerificationRequest $request)
    {
        Telegram::sendMessage([
            'chat_id' => $request->user->telegram_id,
            'text'    =>
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
     * تحديث رسالة الأدمن بعد الموافقة أو الرفض.
     */
    protected function updateAdminMessage($callbackQuery, $requestId, $request, $status)
    {
        $statusEmoji = $status === 'approved' ? '✅' : '❌';
        $statusText  = $status === 'approved'
            ? 'تمت الموافقة على'
            : 'تم رفض';

        Telegram::editMessageText([
            'chat_id'    => $callbackQuery->getMessage()->getChat()->getId(),
            'message_id' => $callbackQuery->getMessage()->getMessageId(),
            'text'       =>
                "{$statusEmoji} {$statusText} الطلب #{$requestId}\n" .
                "المستخدم: {$request->user->first_name}\n" .
                "الخطة: {$request->plan_type}\n" .
                "بواسطة: Admin",
        ]);
    }

    /**
     * التأكد أن المستخدم أدمن.
     */
    protected function isAdmin($telegramId): bool
    {
        return in_array($telegramId, config('telegram.bots.mybot.admin_ids', []));
    }

    /**
     * التحقق من صحة الطلب.
     */
    protected function isValidRequest(?VerificationRequest $request, $callbackId): bool
    {
        if (!$request || $request->status !== 'pending') {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text'              => '⚠️ تمت المعالجة مسبقاً',
                'show_alert'        => true,
            ]);

            return false;
        }

        return true;
    }

    /**
     * إرسال رسالة "غير مصرح" عند محاولة شخص ليس أدمن.
     */
    protected function sendUnauthorizedMessage($callbackId)
    {
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text'              => '❌ غير مصرح لك',
            'show_alert'        => true,
        ]);
    }
}