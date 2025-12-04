<?php

namespace App\Services;

use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Keyboard\Keyboard;

use App\Models\User;
use App\Models\VerificationRequest;
use App\Models\Subscription;

use Carbon\Carbon;

class TelegramBotService
{
    protected TelegramLogger $logger;
    
    public function __construct()
    {
        $this->logger = new TelegramLogger();
    }
    
    public function handleCallback($callbackQuery)
    {
        $data = $callbackQuery->getData();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $messageId = $callbackQuery->getMessage()->getMessageId();
        $callbackId = $callbackQuery->getId();

        $user = User::where('telegram_id', $chatId)->first();

        if (!$user) {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => '❌ مستخدم غير موجود',
                'show_alert' => true,
            ]);
            return;
        }

        $this->logger->info("Handling callback", [
            'data' => $data,
            'user_id' => $user->id
        ]);

        match (true) {
            // القائمة الرئيسية
            $data === 'back_to_start' => $this->backToStart($user, $chatId, $messageId, $callbackId),
            
            // التجربة المجانية والاشتراكات
            $data === 'trial_24h' => $this->handleTrialRequest($user, $chatId, $messageId, $callbackId),
            $data === 'show_subscriptions' => $this->showSubscriptionPlans($chatId, $messageId, $callbackId),
            
            // اختيار الخطط والدفع
            str_starts_with($data, 'select_plan_') => $this->showPaymentInfo($data, $user, $chatId, $messageId, $callbackId),
            str_starts_with($data, 'confirm_payment_') => $this->requestPaymentProof($data, $user, $chatId, $callbackId),
            
            // معالجة الطلبات (للأدمن)
            str_starts_with($data, 'approve_') => $this->approvePayment($data, $callbackQuery),
            str_starts_with($data, 'reject_') => $this->rejectPayment($data, $callbackQuery),
            
            // القوائم الفرعية
            $data === 'start_using' => $this->handleStartUsing($user, $chatId, $callbackId),
            $data === 'help' => $this->showHelp($chatId, $callbackId),
            $data === 'subscription_info' => $this->showSubscriptionInfo($user, $chatId, $callbackId),
            
            default => $this->handleUnknownCallback($callbackId),
        };
    }

    // ==================== القائمة الرئيسية ====================
    
    protected function backToStart($user, $chatId, $messageId, $callbackId)
    {
        $this->logger->info("Back to start", ['user_id' => $user->id]);
        
        $menuService = new MenuService();
        
        if ($user->hasActiveSubscription()) {
            $subscription = $user->activeSubscription;
            $daysLeft = now()->diffInDays($subscription->ends_at, false);
            $daysLeft = max(0, (int) ceil($daysLeft));
            
            $firstName = htmlspecialchars($user->first_name ?? 'مستخدم', ENT_QUOTES, 'UTF-8');
            $planType = $subscription->plan_type ?? 'غير محدد';
            $price = number_format($subscription->price ?? 0, 2);
            $subscriptionEmoji = $subscription->is_trial ? '🎁' : '💎';
            $subscriptionStatus = $subscription->is_trial ? 'تجريبي' : 'مدفوع';
            
            $message = "✅ مرحباً <b>{$firstName}</b>!\n\n"
                . "اشتراكك نشط ✨\n\n"
                . "{$subscriptionEmoji} النوع: {$subscriptionStatus}\n"
                . "📦 الخطة: {$planType}\n"
                . "📅 متبقي: <b>{$daysLeft}</b> يوم\n"
                . "💰 السعر: \${$price}\n\n"
                . "يمكنك الآن استخدام جميع مميزات البوت! 🎉";
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🚀 بدء الاستخدام', 'callback_data' => 'start_using'],
                        ['text' => '❓ مساعدة', 'callback_data' => 'help']
                    ],
                    [
                        ['text' => '📊 معلومات الاشتراك', 'callback_data' => 'subscription_info']
                    ]
                ]
            ];
        } else {
            $firstName = htmlspecialchars($user->first_name ?? 'مستخدم', ENT_QUOTES, 'UTF-8');
            
            $message = "🎉 مرحباً بك <b>{$firstName}</b>!\n\n"
                . "أهلاً بك في البوت الخاص بنا 🤖\n\n"
                . "للبدء في استخدام البوت، يمكنك اختيار:\n\n"
                . "🎁 تجربة مجانية لمدة 24 ساعة\n"
                . "💎 أو الاشتراك المباشر للحصول على جميع المميزات\n\n"
                . "اختر ما يناسبك:";
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎁 فترة تجريبية 24 ساعة', 'callback_data' => 'trial_24h']
                    ],
                    [
                        ['text' => '💎 الاشتراك المدفوع', 'callback_data' => 'show_subscriptions']
                    ]
                ]
            ];
        }
        
        Telegram::editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
        
        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }

    // ==================== التجربة المجانية ====================

    protected function handleTrialRequest($user, $chatId, $messageId, $callbackId)
    {
        $this->logger->info("Trial request", ['user_id' => $user->id]);
        
        $hasUsedTrial = Subscription::where('user_id', $user->id)
            ->where('plan_type', 'trial')
            ->exists();

        if ($hasUsedTrial) {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => '⚠️ لقد استخدمت الفترة التجريبية من قبل',
                'show_alert' => true,
            ]);
            return;
        }

        Subscription::create([
            'user_id' => $user->id,
            'plan_type' => 'trial',
            'price' => 0,
            'starts_at' => now(),
            'ends_at' => now()->addHours(24),
            'is_active' => true,
            'is_trial' => true,
            'status' => 'active',
        ]);

        $user->update(['is_active' => true]);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 بدء الاستخدام', 'callback_data' => 'start_using'],
                    ['text' => '❓ مساعدة', 'callback_data' => 'help']
                ]
            ]
        ];

        Telegram::editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' =>
                "✅ تم تفعيل الفترة التجريبية!\n\n" .
                "🎁 المدة: 24 ساعة\n" .
                "⏰ تنتهي في: " . now()->addHours(24)->format('Y-m-d H:i') . "\n\n" .
                "يمكنك الآن استخدام جميع مميزات البوت! 🎉",
            'reply_markup' => json_encode($keyboard),
        ]);

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text' => '✅ تم التفعيل',
        ]);
        
        $this->logger->success("Trial activated", ['user_id' => $user->id]);
    }

    // ==================== خطط الاشتراك ====================

    protected function showSubscriptionPlans($chatId, $messageId, $callbackId)
    {
        $this->logger->info("Showing subscription plans");
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📦 شهري - $10', 'callback_data' => 'select_plan_monthly']],
                [['text' => '📦 ربع سنوي - $25', 'callback_data' => 'select_plan_quarterly']],
                [['text' => '📦 نصف سنوي - $45', 'callback_data' => 'select_plan_semi_annual']],
                [['text' => '📦 سنوي - $90', 'callback_data' => 'select_plan_yearly']],
                [['text' => '« رجوع', 'callback_data' => 'back_to_start']]
            ]
        ];

        $message =
            "💎 خطط الاشتراك المتاحة:\n\n" .
            "1️⃣ شهري (30 يوم) - \$10\n" .
            "2️⃣ ربع سنوي (90 يوم) - \$25\n" .
            "3️⃣ نصف سنوي (180 يوم) - \$45\n" .
            "4️⃣ سنوي (365 يوم) - \$90 🔥\n\n" .
            "اختر الخطة المناسبة لك:";

        Telegram::editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'reply_markup' => json_encode($keyboard),
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }

    protected function showPaymentInfo($data, $user, $chatId, $messageId, $callbackId)
    {
        $planType = str_replace('select_plan_', '', $data);
        
        $this->logger->info("Showing payment info", [
            'user_id' => $user->id,
            'plan' => $planType
        ]);

        $plans = [
            'monthly' => ['duration' => 30, 'price' => 10, 'name' => 'شهري'],
            'quarterly' => ['duration' => 90, 'price' => 25, 'name' => 'ربع سنوي'],
            'semi_annual' => ['duration' => 180, 'price' => 45, 'name' => 'نصف سنوي'],
            'yearly' => ['duration' => 365, 'price' => 90, 'name' => 'سنوي'],
        ];

        $plan = $plans[$planType] ?? $plans['monthly'];

        cache()->put("selected_plan_{$user->telegram_id}", $planType, now()->addHours(1));

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تأكيد الدفع', 'callback_data' => "confirm_payment_{$planType}"]
                ],
                [
                    ['text' => '« رجوع للخطط', 'callback_data' => 'show_subscriptions']
                ]
            ]
        ];

        $message =
            "📋 تفاصيل الاشتراك:\n\n" .
            "📦 الخطة: {$plan['name']}\n" .
            "⏱ المدة: {$plan['duration']} يوم\n" .
            "💰 السعر: \${$plan['price']}\n\n" .
            "💳 معلومات الدفع:\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "البنك: بنك الخليج\n" .
            "رقم الحساب: 1234567890\n" .
            "الاسم: Your Business Name\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "أو عبر PayPal:\n" .
            "📧 payments@yourdomain.com\n\n" .
            "⚠️ بعد إتمام الدفع، اضغط على زر \"تأكيد الدفع\" أدناه\n" .
            "وأرسل صورة الإيصال أو رقم العملية";

        Telegram::editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'reply_markup' => json_encode($keyboard),
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }

    protected function requestPaymentProof($data, $user, $chatId, $callbackId)
    {
        $planType = str_replace('confirm_payment_', '', $data);
        
        $this->logger->info("Requesting payment proof", [
            'user_id' => $user->id,
            'plan' => $planType
        ]);
        
        cache()->put("waiting_payment_proof_{$chatId}", $planType, now()->addHours(1));

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "📸 الرجاء إرسال إثبات الدفع:\n\n" .
                "• صورة الإيصال 📷\n" .
                "• رقم العملية 🔢\n" .
                "• لقطة شاشة من التحويل 📱\n\n" .
                "⏳ سيتم المراجعة خلال دقائق",
        ]);

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text' => '✅ أرسل إثبات الدفع الآن',
        ]);
    }

    // ==================== معالجة إثبات الدفع ====================

    public function handlePaymentProof($message)
    {
        $chatId = $message->getChat()->getId();
        $user = User::where('telegram_id', $chatId)->first();

        if (!cache()->has("waiting_payment_proof_{$chatId}")) {
            return;
        }

        $planType = cache()->get("waiting_payment_proof_{$chatId}");
        
        $this->logger->info("Processing payment proof", [
            'user_id' => $user->id,
            'plan' => $planType
        ]);

        $paymentProof = null;
        $transactionId = null;

        if ($message->getPhoto()) {
            $photos = $message->getPhoto();
            $largestPhoto = end($photos);
            $paymentProof = $largestPhoto->getFileId();
        }

        if ($message->getText() && !$message->getPhoto()) {
            $transactionId = $message->getText();
        }

        $request = VerificationRequest::create([
            'user_id' => $user->id,
            'plan_type' => $planType,
            'payment_proof' => $paymentProof,
            'transaction_id' => $transactionId,
            'status' => 'pending',
        ]);

        cache()->forget("waiting_payment_proof_{$chatId}");

        app(AdminNotificationService::class)->sendVerificationRequest($request);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "✅ تم استلام طلبك!\n\n" .
                "🔖 رقم الطلب: #{$request->id}\n" .
                "⏳ جاري المراجعة...",
        ]);
        
        $this->logger->success("Payment proof submitted", [
            'request_id' => $request->id
        ]);
    }

    // ==================== موافقة/رفض الطلبات (للأدمن) ====================

    protected function approvePayment($data, $callbackQuery)
    {
        $adminId = $callbackQuery->getFrom()->getId();

        if (!$this->isAdmin($adminId)) {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '❌ غير مصرح لك',
                'show_alert' => true,
            ]);
            return;
        }

        $requestId = str_replace('approve_', '', $data);
        $request = VerificationRequest::find($requestId);

        if (!$request || $request->status !== 'pending') {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '⚠️ تمت المعالجة مسبقاً',
                'show_alert' => true,
            ]);
            return;
        }
        
        $this->logger->info("Approving payment", [
            'request_id' => $requestId,
            'admin_id' => $adminId
        ]);

        $planDurations = [
            'monthly' => 30,
            'quarterly' => 90,
            'semi_annual' => 180,
            'yearly' => 365,
        ];

        $planPrices = [
            'monthly' => 10,
            'quarterly' => 25,
            'semi_annual' => 45,
            'yearly' => 90,
        ];

        $request->update([
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        $subscription = Subscription::create([
            'user_id' => $request->user_id,
            'plan_type' => $request->plan_type,
            'price' => $planPrices[$request->plan_type],
            'starts_at' => now(),
            'ends_at' => now()->addDays($planDurations[$request->plan_type]),
            'is_active' => true,
            'is_trial' => false,
            'status' => 'active',
        ]);

        $request->user->update(['is_active' => true]);

        Telegram::editMessageText([
            'chat_id' => $callbackQuery->getMessage()->getChat()->getId(),
            'message_id' => $callbackQuery->getMessage()->getMessageId(),
            'text' =>
                "✅ تمت الموافقة على الطلب #{$requestId}\n" .
                "المستخدم: {$request->user->first_name}\n" .
                "الخطة: {$request->plan_type}\n" .
                "بواسطة: Admin",
        ]);

        $this->sendWelcomeAfterApproval($request->user, $subscription);

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
            'text' => '✅ تمت الموافقة',
        ]);
        
        $this->logger->success("Payment approved", [
            'request_id' => $requestId,
            'subscription_id' => $subscription->id
        ]);
    }

    protected function sendWelcomeAfterApproval($user, $subscription)
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

        $planNames = [
            'monthly' => 'شهري',
            'quarterly' => 'ربع سنوي',
            'semi_annual' => 'نصف سنوي',
            'yearly' => 'سنوي',
        ];

        Telegram::sendMessage([
            'chat_id' => $user->telegram_id,
            'text' =>
                "🎉 مبروك! تم تفعيل اشتراكك\n\n" .
                "📋 معلومات الاشتراك:\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "📦 الخطة: {$planNames[$subscription->plan_type]}\n" .
                "💰 السعر: \${$subscription->price}\n" .
                "📅 البداية: " . $subscription->starts_at->format('Y-m-d') . "\n" .
                "📅 الانتهاء: " . $subscription->ends_at->format('Y-m-d') . "\n" .
                "⏰ المتبقي: {$daysLeft} يوم\n" .
                "━━━━━━━━━━━━━━━━━━\n\n" .
                "اضغط على «بدء الاستخدام» للبدء 🚀",
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function rejectPayment($data, $callbackQuery)
    {
        $adminId = $callbackQuery->getFrom()->getId();

        if (!$this->isAdmin($adminId)) {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '❌ غير مصرح لك',
                'show_alert' => true,
            ]);
            return;
        }

        $requestId = str_replace('reject_', '', $data);
        $request = VerificationRequest::find($requestId);

        if (!$request || $request->status !== 'pending') {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '⚠️ تمت المعالجة مسبقاً',
                'show_alert' => true,
            ]);
            return;
        }
        
        $this->logger->info("Rejecting payment", [
            'request_id' => $requestId,
            'admin_id' => $adminId
        ]);

        $request->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
        ]);

        Telegram::editMessageText([
            'chat_id' => $callbackQuery->getMessage()->getChat()->getId(),
            'message_id' => $callbackQuery->getMessage()->getMessageId(),
            'text' =>
                "❌ تم رفض الطلب #{$requestId}\n" .
                "المستخدم: {$request->user->first_name}\n" .
                "بواسطة: Admin",
        ]);

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

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
            'text' => '❌ تم الرفض',
        ]);
        
        $this->logger->warning("Payment rejected", ['request_id' => $requestId]);
    }

    // ==================== القوائم الفرعية ====================

    protected function handleStartUsing($user, $chatId, $callbackId)
    {
        $this->logger->info("Start using", ['user_id' => $user->id]);
        
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "🚀 مرحباً بك!\n\n" .
                "الأوامر المتاحة:\n" .
                "/status - حالة الاشتراك\n" .
                "/help - المساعدة\n" .
                "/settings - الإعدادات\n\n" .
                "ابدأ الآن! 💫",
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }

    protected function showHelp($chatId, $callbackId)
    {
        $this->logger->info("Showing help");
        
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "❓ المساعدة\n\n" .
                "/start - القائمة الرئيسية\n" .
                "/status - حالة الاشتراك\n" .
                "/help - المساعدة\n" .
                "/support - الدعم\n\n" .
                "📧 support@yourdomain.com\n" .
                "📱 @YourSupportBot",
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }

    protected function showSubscriptionInfo($user, $chatId, $callbackId)
    {
        $this->logger->info("Showing subscription info", ['user_id' => $user->id]);
        
        $subscription = $user->activeSubscription;

        if (!$subscription) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "⚠️ ليس لديك اشتراك نشط",
            ]);
            
            Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
            return;
        }

        $totalDays = $subscription->starts_at->diffInDays($subscription->ends_at);
        $passedDays = $subscription->starts_at->diffInDays(now());
        $remainingDays = now()->diffInDays($subscription->ends_at, false);
        $progress = $totalDays > 0 ? ($passedDays / $totalDays) * 100 : 0;

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "📊 معلومات اشتراكك:\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "📦 الخطة: {$subscription->plan_type}\n" .
                "💰 السعر: \${$subscription->price}\n" .
                "📅 البداية: " . $subscription->starts_at->format('Y-m-d') . "\n" .
                "📅 النهاية: " . $subscription->ends_at->format('Y-m-d') . "\n" .
                "⏰ المتبقي: " . max(0, $remainingDays) . " يوم\n" .
                "📈 التقدم: " . round($progress) . "%\n" .
                "━━━━━━━━━━━━━━━━━━",
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }

    // ==================== معالجة الأخطاء ====================
    
    protected function handleUnknownCallback($callbackId)
    {
        $this->logger->warning("Unknown callback");
        
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text' => '⚠️ أمر غير معروف',
            'show_alert' => false,
        ]);
    }

    // ==================== Helper Methods ====================

    protected function isAdmin($telegramId): bool
    {
        return in_array($telegramId, config('telegram.bots.mybot.admin_ids', []));
    }
}