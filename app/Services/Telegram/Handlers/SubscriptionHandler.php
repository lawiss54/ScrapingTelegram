<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\TelegramLogger;
use App\Models\User;

class SubscriptionHandler
{
    protected TelegramLogger $logger;
    
    // خطط الاشتراك
    protected array $plans = [
        'monthly' => [
            'duration' => 30,
            'price' => 10,
            'name' => 'شهري',
            'emoji' => '📦'
        ],
        'quarterly' => [
            'duration' => 90,
            'price' => 25,
            'name' => 'ربع سنوي',
            'emoji' => '📦'
        ],
        'semi_annual' => [
            'duration' => 180,
            'price' => 45,
            'name' => 'نصف سنوي',
            'emoji' => '📦'
        ],
        'yearly' => [
            'duration' => 365,
            'price' => 90,
            'name' => 'سنوي',
            'emoji' => '🔥'
        ],
    ];
    
    public function __construct(TelegramLogger $logger)
    {
        $this->logger = $logger;
    }
    
    /**
     * عرض خطط الاشتراك
     */
    public function showPlans($chatId, $messageId, $callbackId)
    {
        $this->logger->info("Showing subscription plans");
        
        $keyboard = $this->buildPlansKeyboard();
        $message = $this->buildPlansMessage();
        
        Telegram::editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'reply_markup' => json_encode($keyboard),
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }
    
    /**
     * عرض معلومات الدفع للخطة المختارة
     */
    public function showPaymentInfo($data, $user, $chatId, $messageId, $callbackId)
    {
        $planType = str_replace('select_plan_', '', $data);
        
        $this->logger->info("Showing payment info", [
            'user_id' => $user->id,
            'plan' => $planType
        ]);
        
        // التحقق من صحة الخطة
        if (!isset($this->plans[$planType])) {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => '⚠️ خطة غير صحيحة',
                'show_alert' => true
            ]);
            return;
        }
        
        $plan = $this->plans[$planType];
        
        // حفظ الخطة المختارة
        cache()->put("selected_plan_{$user->telegram_id}", $planType, now()->addHours(1));
        
        $keyboard = $this->buildPaymentKeyboard($planType);
        $message = $this->buildPaymentMessage($plan);
        
        Telegram::editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'reply_markup' => json_encode($keyboard),
        ]);

        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }
    
    /**
     * الحصول على معلومات خطة معينة
     */
    public function getPlanInfo(string $planType): ?array
    {
        return $this->plans[$planType] ?? null;
    }
    
    /**
     * بناء لوحة المفاتيح لخطط الاشتراك
     */
    protected function buildPlansKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '📦 شهري - $10', 'callback_data' => 'select_plan_monthly']],
                [['text' => '📦 ربع سنوي - $25', 'callback_data' => 'select_plan_quarterly']],
                [['text' => '📦 نصف سنوي - $45', 'callback_data' => 'select_plan_semi_annual']],
                [['text' => '🔥 سنوي - $90', 'callback_data' => 'select_plan_yearly']],
                [['text' => '« رجوع', 'callback_data' => 'back_to_start']]
            ]
        ];
    }
    
    /**
     * بناء رسالة خطط الاشتراك
     */
    protected function buildPlansMessage(): string
    {
        return "💎 خطط الاشتراك المتاحة:\n\n" .
            "1️⃣ شهري (30 يوم) - \$10\n" .
            "2️⃣ ربع سنوي (90 يوم) - \$25\n" .
            "3️⃣ نصف سنوي (180 يوم) - \$45\n" .
            "4️⃣ سنوي (365 يوم) - \$90 🔥\n\n" .
            "اختر الخطة المناسبة لك:";
    }
    
    /**
     * بناء لوحة المفاتيح لمعلومات الدفع
     */
    protected function buildPaymentKeyboard(string $planType): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تأكيد الدفع', 'callback_data' => "confirm_payment_{$planType}"]
                ],
                [
                    ['text' => '« رجوع للخطط', 'callback_data' => 'show_subscriptions']
                ]
            ]
        ];
    }
    
    /**
     * بناء رسالة معلومات الدفع
     */
    protected function buildPaymentMessage(array $plan): string
    {
        return "📋 تفاصيل الاشتراك:\n\n" .
            "📦 الخطة: {$plan['name']}\n" .
            "⏱ المدة: {$plan['duration']} يوم\n" .
            "💰 السعر: \${$plan['price']}\n\n" .
            "💳 معلومات الدفع:\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "البنك: بريدي موب \n" .
            "رقم الحساب: 00799999002476295067\n" .
            "1$ = 270DA \n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "أو عبر Binance:\n" .
            "ID: 818006042 \n\n" .
            "⚠️ بعد إتمام الدفع، اضغط على زر \"تأكيد الدفع\" أدناه\n" .
            "وأرسل صورة الإيصال أو رقم العملية";
    }
}