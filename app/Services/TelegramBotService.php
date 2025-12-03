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
    public function handleCallback($callbackQuery)
    {
        $data = $callbackQuery->getData();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $messageId = $callbackQuery->getMessage()->getMessageId();
        $callbackId = $callbackQuery->getId();

        $user = User::where('telegram_id', $chatId)->first();

        match (true) {
            $data === 'trial_24h' => $this->handleTrialRequest($user, $chatId, $messageId, $callbackId),
            $data === 'show_subscriptions' => $this->showSubscriptionPlans($chatId, $messageId, $callbackId),
            str_starts_with($data, 'select_plan_') => $this->showPaymentInfo($data, $user, $chatId, $messageId, $callbackId),
            str_starts_with($data, 'confirm_payment_') => $this->requestPaymentProof($data, $user, $chatId, $callbackId),
            str_starts_with($data, 'approve_') => $this->approvePayment($data, $callbackQuery),
            str_starts_with($data, 'reject_') => $this->rejectPayment($data, $callbackQuery),
            $data === 'start_using' => $this->handleStartUsing($user, $chatId, $callbackId),
            $data === 'help' => $this->showHelp($chatId, $callbackId),
            $data === 'subscription_info' => $this->showSubscriptionInfo($user, $chatId, $callbackId),
            default => null,
        };
    }

    protected function handleTrialRequest($user, $chatId, $messageId, $callbackId)
    {
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
            'status' => 'active',
        ]);

        $user->update(['is_active' => true]);

        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '🚀 بدء الاستخدام', 'callback_data' => 'start_using']),
                Keyboard::inlineButton(['text' => '❓ مساعدة', 'callback_data' => 'help']),
            ]);

        Telegram::editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' =>
                "✅ تم تفعيل الفترة التجريبية!\n\n" .
                "🎁 المدة: 24 ساعة\n" .
                "⏰ تنتهي في: " . now()->addHours(24)->format('Y-m-d H:i') . "\n\n" .
                "يمكنك الآن استخدام جميع مميزات البوت! 🎉",
            'reply_markup' => $keyboard,
        ]);

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text' => '✅ تم التفعيل',
        ]);
    }

    protected function showSubscriptionPlans($chatId, $messageId, $callbackId)
    {
        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => '📦 شهري - $10', 'callback_data' => 'select_plan_monthly'])])
            ->row([Keyboard::inlineButton(['text' => '📦 ربع سنوي - $25', 'callback_data' => 'select_plan_quarterly'])])
            ->row([Keyboard::inlineButton(['text' => '📦 نصف سنوي - $45', 'callback_data' => 'select_plan_semi_annual'])])
            ->row([Keyboard::inlineButton(['text' => '📦 سنوي - $90', 'callback_data' => 'select_plan_yearly'])])
            ->row([Keyboard::inlineButton(['text' => '« رجوع', 'callback_data' => 'back_to_start'])]);

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
            'reply_markup' => $keyboard,
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }

    protected function showPaymentInfo($data, $user, $chatId, $messageId, $callbackId)
    {
        $planType = str_replace('select_plan_', '', $data);

        $plans = [
            
            'monthly' => ['duration' => 1, 'price' => 0, 'name' => 'تجريبي'],
            'monthly' => ['duration' => 30, 'price' => 10, 'name' => 'شهري'],
            'quarterly' => ['duration' => 90, 'price' => 25, 'name' => 'ربع سنوي'],
            'semi_annual' => ['duration' => 180, 'price' => 45, 'name' => 'نصف سنوي'],
            'yearly' => ['duration' => 365, 'price' => 90, 'name' => 'سنوي'],
        ];

        $plan = $plans[$planType] ?? $plans['monthly'];

        cache()->put("selected_plan_{$user->telegram_id}", $planType, now()->addHours(1));

        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton([
                    'text' => '✅ تأكيد الدفع',
                    'callback_data' => "confirm_payment_{$planType}",
                ]),
            ])
            ->row([
                Keyboard::inlineButton([
                    'text' => '« رجوع للخطط',
                    'callback_data' => 'show_subscriptions',
                ]),
            ]);

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
            'reply_markup' => $keyboard,
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }

    protected function requestPaymentProof($data, $user, $chatId, $callbackId)
    {
        $planType = str_replace('confirm_payment_', '', $data);
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

    public function handlePaymentProof($message)
    {
        $chatId = $message->getChat()->getId();
        $user = User::where('telegram_id', $chatId)->first();

        if (!cache()->has("waiting_payment_proof_{$chatId}")) {
            return;
        }

        $planType = cache()->get("waiting_payment_proof_{$chatId}");

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
    }

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
    }

    protected function sendWelcomeAfterApproval($user, $subscription)
    {
        $daysLeft = now()->diffInDays($subscription->ends_at);

        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '🚀 بدء الاستخدام', 'callback_data' => 'start_using']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '📊 معلومات الاشتراك', 'callback_data' => 'subscription_info']),
                Keyboard::inlineButton(['text' => '❓ مساعدة', 'callback_data' => 'help']),
            ]);

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
            'reply_markup' => $keyboard,
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
    }

    protected function handleStartUsing($user, $chatId, $callbackId)
    {
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
        $subscription = $user->activeSubscription;

        if (!$subscription) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "⚠️ ليس لديك اشتراك نشط",
            ]);
            return;
        }

        $totalDays = $subscription->starts_at->diffInDays($subscription->ends_at);
        $passedDays = $subscription->starts_at->diffInDays(now());
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
                "⏰ المتبقي: " . now()->diffInDays($subscription->ends_at) . " يوم\n" .
                "📈 التقدم: " . round($progress) . "%\n" .
                "━━━━━━━━━━━━━━━━━━",
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }

    protected function isAdmin($telegramId): bool
    {
        return in_array($telegramId, config('telegram.admin_ids'));
    }
}