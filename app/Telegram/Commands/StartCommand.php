<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use App\Models\User;
use Telegram\Bot\Laravel\Facades\Telegram;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected string $description = 'بدء استخدام البوت';

    public function handle()
    {
        $adminId = config('telegram.bots.mybot.admin_ids.0');
        
        try {
            // 📥 Log 1: بداية الأمر
            $this->sendLog($adminId, "🟢 START COMMAND TRIGGERED");
            
            $update = $this->getUpdate();
            $message = $update->getMessage();
            
            // ✅ التحقق من وجود الرسالة
            if (!$message) {
                $this->sendLog($adminId, "⚠️ No message found in update");
                return;
            }
            
            $this->sendLog($adminId, "📨 Message received");
            
            $telegramUser = $message->getFrom();
            
            // ✅ التحقق من وجود بيانات المستخدم
            if (!$telegramUser) {
                $this->sendLog($adminId, "❌ No user data in message");
                $this->replyWithMessage([
                    'text' => '❌ خطأ في استقبال بيانات المستخدم'
                ]);
                return;
            }
            
            $telegramId = $telegramUser->getId();
            $username = $telegramUser->getUsername();
            $firstName = $telegramUser->getFirstName() ?? 'مستخدم';
            $lastName = $telegramUser->getLastName();
            
            $this->sendLog($adminId, "👤 User data extracted:
ID: {$telegramId}
Username: " . ($username ?? 'null') . "
FirstName: {$firstName}");
            
            // إنشاء أو تحديث المستخدم
            $this->sendLog($adminId, "💾 Attempting to create/update user...");
            
            // ✅ التحقق من الاتصال بقاعدة البيانات
            try {
                \DB::connection()->getPdo();
                $this->sendLog($adminId, "✅ Database connection OK");
            } catch (\Exception $e) {
                $this->sendLog($adminId, "❌ Database connection FAILED: " . $e->getMessage());
                throw $e;
            }
            
            // ✅ التحقق من وجود المستخدم أولاً
            $this->sendLog($adminId, "🔍 Searching for existing user with telegram_id: {$telegramId}");
            
            $existingUser = User::where('telegram_id', $telegramId)->first();
            
            if ($existingUser) {
                $this->sendLog($adminId, "📌 User found in DB (ID: {$existingUser->id})");
            } else {
                $this->sendLog($adminId, "📌 User NOT found, will create new");
            }
            
            // ✅ البيانات التي سيتم حفظها
            $userData = [
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => true,
            ];
            
            $this->sendLog($adminId, "📝 Data to save: " . json_encode($userData, JSON_UNESCAPED_UNICODE));
            
            // ✅ محاولة الحفظ
            $this->sendLog($adminId, "💾 Executing updateOrCreate...");
            
            $user = User::updateOrCreate(
                ['telegram_id' => $telegramId],
                $userData
            );
            
            $this->sendLog($adminId, "✅ updateOrCreate completed");
            
            // ✅ التحقق من نجاح الحفظ
            if (!$user) {
                $this->sendLog($adminId, "❌ User object is NULL after save!");
                throw new \Exception("Failed to create/update user");
            }
            
            $this->sendLog($adminId, "✅ User saved successfully:
DB ID: {$user->id}
Telegram ID: {$user->telegram_id}
Name: {$user->first_name}
Active: " . ($user->is_active ? 'Yes' : 'No'));
            
            // التحقق من وجود اشتراك نشط
            $this->sendLog($adminId, "🔍 Checking subscription...");
            
            $hasSubscription = $user->hasActiveSubscription();
            
            $this->sendLog($adminId, "📋 Subscription status: " . ($hasSubscription ? 'Active ✅' : 'Inactive ❌'));
            
            if ($hasSubscription) {
                $subscription = $user->activeSubscription;
                $this->sendLog($adminId, "💎 Subscription details:
Type: " . ($subscription->plan_type ?? 'null') . "
Ends: " . ($subscription->ends_at ?? 'null') . "
Price: " . ($subscription->price ?? 'null'));
                
                $this->showMainMenu($user);
            } else {
                $this->sendLog($adminId, "🎁 Showing welcome message (no subscription)");
                $this->showWelcomeMessage($user);
            }
            
            $this->sendLog($adminId, "✅ START COMMAND COMPLETED");
            
        } catch (\Illuminate\Database\QueryException $e) {
            // ❌ خطأ في قاعدة البيانات
            $errorLog = "❌ DATABASE ERROR:

Message: {$e->getMessage()}
SQL: " . ($e->getSql() ?? 'N/A') . "
Bindings: " . json_encode($e->getBindings() ?? []) . "

File: {$e->getFile()}
Line: {$e->getLine()}";
            
            $this->sendLog($adminId, $errorLog);
            
            \Log::error('StartCommand Database Error', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
            ]);
            
            $this->replyWithMessage([
                'text' => '❌ خطأ في قاعدة البيانات. يرجى المحاولة مرة أخرى لاحقاً.'
            ]);
            
        } catch (\Exception $e) {
            // ❌ أخطاء عامة
            $errorLog = "❌ GENERAL ERROR:

Message: {$e->getMessage()}

File: {$e->getFile()}
Line: {$e->getLine()}

Trace:
" . substr($e->getTraceAsString(), 0, 800);
            
            $this->sendLog($adminId, $errorLog);
            
            \Log::error('StartCommand Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->replyWithMessage([
                'text' => '❌ حدث خطأ أثناء معالجة طلبك. يرجى المحاولة مرة أخرى.'
            ]);
        }
    }
    
    protected function showWelcomeMessage($user)
    {
        $adminId = config('telegram.bots.mybot.admin_ids.0');
        $this->sendLog($adminId, "📤 Sending welcome message to user {$user->telegram_id}");
        
        $keyboard = Keyboard::make()->inline();
        
        $keyboard->row([
            Keyboard::inlineButton([
                'text' => '🎁 فترة تجريبية 24 ساعة',
                'callback_data' => 'trial_24h'
            ])
        ]);
        
        $keyboard->row([
            Keyboard::inlineButton([
                'text' => '💎 الاشتراك المدفوع',
                'callback_data' => 'show_subscriptions'
            ])
        ]);
        
        $firstName = htmlspecialchars($user->first_name ?? 'مستخدم', ENT_QUOTES, 'UTF-8');
        
        $message = "🎉 مرحباً بك <b>{$firstName}</b>!\n\n"
            . "أهلاً بك في البوت الخاص بنا 🤖\n\n"
            . "للبدء في استخدام البوت، يمكنك اختيار:\n\n"
            . "🎁 تجربة مجانية لمدة 24 ساعة\n"
            . "💎 أو الاشتراك المباشر للحصول على جميع المميزات\n\n"
            . "اختر ما يناسبك:";
        
        try {
            $this->replyWithMessage([
                'text' => $message,
                'reply_markup' => $keyboard,
                'parse_mode' => 'HTML'
            ]);
            
            $this->sendLog($adminId, "✅ Welcome message sent successfully");
            
        } catch (\Exception $e) {
            $this->sendLog($adminId, "❌ Failed to send welcome message: " . $e->getMessage());
        }
    }
    
    protected function showMainMenu($user)
    {
        $adminId = config('telegram.bots.mybot.admin_ids.0');
        $this->sendLog($adminId, "📤 Sending main menu to user {$user->telegram_id}");
        
        $subscription = $user->activeSubscription;
        
        if (!$subscription) {
            $this->sendLog($adminId, "⚠️ Subscription became null, redirecting to welcome");
            $this->showWelcomeMessage($user);
            return;
        }
        
        $daysLeft = 0;
        if ($subscription->ends_at) {
            $daysLeft = now()->diffInDays($subscription->ends_at, false);
            $daysLeft = max(0, (int) ceil($daysLeft));
        }
        
        $this->sendLog($adminId, "📅 Days left calculated: {$daysLeft}");
        
        $keyboard = Keyboard::make()->inline();
        
        $keyboard->row([
            Keyboard::inlineButton([
                'text' => '🚀 بدء الاستخدام',
                'callback_data' => 'start_using'
            ]),
            Keyboard::inlineButton([
                'text' => '❓ مساعدة',
                'callback_data' => 'help'
            ])
        ]);
        
        $keyboard->row([
            Keyboard::inlineButton([
                'text' => '📊 معلومات الاشتراك',
                'callback_data' => 'subscription_info'
            ])
        ]);
        
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
        
        try {
            $this->replyWithMessage([
                'text' => $message,
                'reply_markup' => $keyboard,
                'parse_mode' => 'HTML'
            ]);
            
            $this->sendLog($adminId, "✅ Main menu sent successfully");
            
        } catch (\Exception $e) {
            $this->sendLog($adminId, "❌ Failed to send main menu: " . $e->getMessage());
        }
    }
    
    /**
     * إرسال log للأدمن
     */
    protected function sendLog($adminId, $message)
    {
        try {
            Telegram::sendMessage([
                'chat_id' => $adminId,
                'text' => "🔍 [StartCommand] " . date('H:i:s') . "\n\n" . $message,
                'parse_mode' => 'HTML'
            ]);
            
            // تأخير بسيط لتجنب rate limiting
            usleep(100000); // 0.1 ثانية
            
        } catch (\Exception $e) {
            \Log::error('Failed to send log: ' . $e->getMessage());
        }
    }
}