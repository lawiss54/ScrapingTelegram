<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\TelegramLogger;
use App\Models\User;

class UserInfoHandler
{
    protected TelegramLogger $logger;
    
    public function __construct(TelegramLogger $logger)
    {
        $this->logger = $logger;
    }
    
    /**
     * عرض حالة الاشتراك (من Command)
     */
    public function showStatus($user, $chatId)
    {
        $this->logger->info("Showing status via command", ['user_id' => $user->id]);
        
        $subscription = $user->activeSubscription;

        if (!$subscription) {
            $this->sendNoSubscriptionStatus($chatId);
            return;
        }

        $daysLeft = now()->diffInDays($subscription->ends_at, false);
        $daysLeft = max(0, (int) ceil($daysLeft));
        
        $statusEmoji = $subscription->is_trial ? '🎁' : '💎';
        $statusText = $subscription->is_trial ? 'تجريبي' : 'مدفوع';
        
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 
                "📊 <b>حالة اشتراكك</b>\n\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "✅ نشط\n" .
                "{$statusEmoji} النوع: {$statusText}\n" .
                "📦 الخطة: {$subscription->plan_type}\n" .
                "⏰ متبقي: <b>{$daysLeft}</b> يوم\n" .
                "📅 ينتهي في: " . $subscription->ends_at->format('Y-m-d H:i') . "\n" .
                "━━━━━━━━━━━━━━━━━━",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '📊 تفاصيل أكثر', 'callback_data' => 'subscription_info']
                    ],
                    [
                        ['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'back_to_start']
                    ]
                ]
            ])
        ]);
    }
    
    /**
     * رسالة: لا يوجد اشتراك (من Command)
     */
    protected function sendNoSubscriptionStatus($chatId)
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 
                "⚠️ ليس لديك اشتراك نشط حالياً\n\n" .
                "للبدء في استخدام البوت، استخدم /start",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🚀 ابدأ الآن', 'callback_data' => 'back_to_start']]
                ]
            ])
        ]);
    }
    
    /**
     * بدء استخدام البوت
     */
    public function handleStartUsing($user, $chatId, $callbackId)
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
    
    /**
     * عرض المساعدة
     */
    public function showHelp($chatId, $callbackId = null)
    {
        $this->logger->info("Showing help");
        
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "❓ المساعدة\n\n" .
                "الأوامر المتاحة:\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "/start - القائمة الرئيسية\n" .
                "/status - حالة الاشتراك\n" .
                "/help - المساعدة\n" .
                "/support - الدعم الفني\n\n" .
                "📧 للتواصل:\n" .
                "support@yourdomain.com\n" .
                "📱 @YourSupportBot\n\n" .
                "⏰ ساعات العمل:\n" .
                "السبت - الخميس: 9 صباحاً - 5 مساءً",
        ]);

        if ($callbackId) {
            Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
        }
    }
    
    /**
     * عرض معلومات الاشتراك
     */
    public function showSubscriptionInfo($user, $chatId, $callbackId)
    {
        $this->logger->info("Showing subscription info", ['user_id' => $user->id]);
        
        try {
            $subscription = $user->activeSubscription;

            if (!$subscription) {
                $this->logger->warning("No active subscription found", ['user_id' => $user->id]);
                $this->sendNoSubscriptionMessage($chatId, $callbackId);
                return;
            }

            $this->logger->info("Subscription found", [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'plan_type' => $subscription->plan_type
            ]);

            // بناء التفاصيل
            try {
                $subscriptionDetails = $this->buildSubscriptionDetails($subscription);
                
                $this->logger->info("Subscription details built successfully", [
                    'user_id' => $user->id,
                    'details_length' => strlen($subscriptionDetails)
                ]);
            } catch (\Exception $buildError) {
                $this->logger->error("Error building subscription details", [
                    'user_id' => $user->id,
                    'error' => $buildError->getMessage(),
                    'line' => $buildError->getLine()
                ]);
                
                // Fallback: رسالة بسيطة
                $subscriptionDetails = $this->buildSimpleSubscriptionDetails($subscription);
            }
            
            // إرسال الرسالة
            try {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $subscriptionDetails,
                    'parse_mode' => 'HTML'
                ]);
                
                $this->logger->success("Subscription info sent successfully", [
                    'user_id' => $user->id
                ]);
            } catch (\Exception $sendError) {
                $this->logger->error("Error sending message", [
                    'user_id' => $user->id,
                    'error' => $sendError->getMessage()
                ]);
                
                // محاولة بدون HTML
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => strip_tags($subscriptionDetails)
                ]);
            }

            Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
            
        } catch (\Exception $e) {
            $this->logger->error("Error in showSubscriptionInfo", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // رسالة خطأ للمستخدم
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '⚠️ حدث خطأ في عرض معلومات الاشتراك. الرجاء المحاولة لاحقاً.'
            ]);
            
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => '⚠️ حدث خطأ',
                'show_alert' => true
            ]);
        }
    }
    
    /**
     * بناء تفاصيل بسيطة (Fallback)
     */
    protected function buildSimpleSubscriptionDetails($subscription): string
    {
        $planNames = [
            'trial' => 'تجريبي',
            'monthly' => 'شهري',
            'quarterly' => 'ربع سنوي',
            'semi_annual' => 'نصف سنوي',
            'yearly' => 'سنوي',
        ];
        
        $planName = $planNames[$subscription->plan_type] ?? $subscription->plan_type;
        $statusEmoji = $subscription->is_trial ? '🎁' : '💎';
        $statusText = $subscription->is_trial ? 'تجريبي' : 'مدفوع';
        
        $remainingDays = 0;
        try {
            $remainingDays = now()->diffInDays($subscription->ends_at, false);
            $remainingDays = max(0, (int) ceil($remainingDays));
        } catch (\Exception $e) {
            // تجاهل خطأ التاريخ
        }
        
        return "📊 معلومات اشتراكك\n\n" .
               "{$statusEmoji} النوع: {$statusText}\n" .
               "📦 الخطة: {$planName}\n" .
               "💰 السعر: \${$subscription->price}\n" .
               "⏰ متبقي: {$remainingDays} يوم\n\n" .
               "✅ اشتراكك نشط";
    }
    
    /**
     * بناء تفاصيل الاشتراك
     */
    protected function buildSubscriptionDetails($subscription): string
    {
        // تسجيل بداية العملية
        $this->logger->info("Building subscription details", [
            'subscription_id' => $subscription->id
        ]);
        
        try {
            // معالجة التواريخ بحذر
            $startsAt = $subscription->starts_at;
            $endsAt = $subscription->ends_at;
            
            if (!$startsAt || !$endsAt) {
                $this->logger->warning("Missing dates in subscription", [
                    'subscription_id' => $subscription->id
                ]);
                return $this->buildSimpleSubscriptionDetails($subscription);
            }
            
            $totalDays = $startsAt->diffInDays($endsAt);
            $passedDays = $startsAt->diffInDays(now());
            $remainingDays = now()->diffInDays($endsAt, false);
            $remainingDays = max(0, (int) ceil($remainingDays));
            
            $progress = $totalDays > 0 ? ($passedDays / $totalDays) * 100 : 0;
            $progress = max(0, min(100, $progress)); // بين 0 و 100
            
            $this->logger->info("Dates calculated", [
                'total_days' => $totalDays,
                'passed_days' => $passedDays,
                'remaining_days' => $remainingDays,
                'progress' => $progress
            ]);
            
            // بناء شريط التقدم
            $progressBar = $this->buildProgressBar($progress);
            
            // تحديد حالة الاشتراك
            $statusEmoji = $subscription->is_trial ? '🎁' : '💎';
            $statusText = $subscription->is_trial ? 'تجريبي' : 'مدفوع';
            
            // أسماء الخطط
            $planNames = [
                'trial' => 'تجريبي 24 ساعة',
                'monthly' => 'شهري',
                'quarterly' => 'ربع سنوي',
                'semi_annual' => 'نصف سنوي',
                'yearly' => 'سنوي',
            ];
            
            $planName = $planNames[$subscription->plan_type] ?? $subscription->plan_type;
            
            // تنسيق التواريخ
            $startDate = $startsAt->format('Y-m-d H:i');
            $endDate = $endsAt->format('Y-m-d H:i');
            
            $message = "📊 <b>معلومات اشتراكك</b>\n" .
                   "━━━━━━━━━━━━━━━━━━\n\n" .
                   "{$statusEmoji} <b>النوع:</b> {$statusText}\n" .
                   "📦 <b>الخطة:</b> {$planName}\n" .
                   "💰 <b>السعر:</b> \${$subscription->price}\n\n" .
                   "📅 <b>تاريخ البداية:</b>\n" .
                   "   {$startDate}\n\n" .
                   "📅 <b>تاريخ الانتهاء:</b>\n" .
                   "   {$endDate}\n\n" .
                   "⏰ <b>المتبقي:</b> {$remainingDays} يوم\n\n" .
                   "📈 <b>التقدم:</b> " . round($progress) . "%\n" .
                   "{$progressBar}\n" .
                   "━━━━━━━━━━━━━━━━━━\n\n" .
                   $this->getSubscriptionWarning($remainingDays);
            
            $this->logger->info("Message built successfully", [
                'message_length' => strlen($message)
            ]);
            
            return $message;
                   
        } catch (\Exception $e) {
            $this->logger->error("Error in buildSubscriptionDetails", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'subscription_id' => $subscription->id ?? 'unknown'
            ]);
            
            // Fallback
            return $this->buildSimpleSubscriptionDetails($subscription);
        }
    }
    
    /**
     * بناء شريط التقدم
     */
    protected function buildProgressBar(float $progress): string
    {
        $filledBlocks = (int) round($progress / 10);
        $emptyBlocks = 10 - $filledBlocks;
        
        return str_repeat('▓', $filledBlocks) . str_repeat('░', $emptyBlocks);
    }
    
    /**
     * الحصول على تحذير الاشتراك
     */
    protected function getSubscriptionWarning(int $remainingDays): string
    {
        if ($remainingDays <= 0) {
            return "⚠️ <b>انتهى الاشتراك!</b>\n" .
                   "يرجى تجديد الاشتراك للاستمرار في الاستخدام.";
        }
        
        if ($remainingDays <= 3) {
            return "⚠️ <b>تحذير:</b> اشتراكك ينتهي خلال {$remainingDays} يوم!\n" .
                   "يُنصح بالتجديد قريباً.";
        }
        
        if ($remainingDays <= 7) {
            return "💡 <b>تذكير:</b> اشتراكك ينتهي خلال أسبوع.";
        }
        
        return "✅ اشتراكك نشط ومستمر!";
    }
    
    /**
     * رسالة: لا يوجد اشتراك نشط
     */
    protected function sendNoSubscriptionMessage($chatId, $callbackId)
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎁 فترة تجريبية', 'callback_data' => 'trial_24h']
                ],
                [
                    ['text' => '💎 الاشتراك المدفوع', 'callback_data' => 'show_subscriptions']
                ],
                [
                    ['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'back_to_start']
                ]
            ]
        ];
        
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 
                "⚠️ <b>ليس لديك اشتراك نشط</b>\n\n" .
                "للاستفادة من جميع مميزات البوت،\n" .
                "يمكنك اختيار:\n\n" .
                "🎁 فترة تجريبية مجانية لمدة 24 ساعة\n" .
                "💎 أو الاشتراك المدفوع مباشرة",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
        
        Telegram::answerCallbackQuery(['callback_query_id' => $callbackId]);
    }
}