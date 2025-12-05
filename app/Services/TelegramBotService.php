<?php

namespace App\Services;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Models\User;
use App\Services\Telegram\Handlers\{
    MenuHandler,
    TrialHandler,
    SubscriptionHandler,
    PaymentHandler,
    AdminHandler,
    UserInfoHandler
};

class TelegramBotService
{
    protected TelegramLogger $logger;
    protected MenuHandler $menuHandler;
    protected TrialHandler $trialHandler;
    protected SubscriptionHandler $subscriptionHandler;
    protected PaymentHandler $paymentHandler;
    protected AdminHandler $adminHandler;
    protected UserInfoHandler $userInfoHandler;
    
    public function __construct()
    {
        $this->logger = new TelegramLogger();
        
        // تهيئة جميع الـ Handlers
        $this->menuHandler = new MenuHandler($this->logger);
        $this->trialHandler = new TrialHandler($this->logger);
        $this->subscriptionHandler = new SubscriptionHandler($this->logger);
        $this->paymentHandler = new PaymentHandler($this->logger);
        $this->adminHandler = new AdminHandler($this->logger);
        $this->userInfoHandler = new UserInfoHandler($this->logger);
    }
    
    /**
     * معالج الـ Callbacks الرئيسي
     */
    public function handleCallback($callbackQuery)
    {
        $data = $callbackQuery->getData();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $messageId = $callbackQuery->getMessage()->getMessageId();
        $callbackId = $callbackQuery->getId();

        // التحقق من وجود المستخدم
        $user = User::where('telegram_id', $chatId)->first();

        if (!$user) {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => '❌ مستخدم غير موجود',
                'show_alert' => true,
            ]);
            return;
        }

        // توجيه الـ Callback للـ Handler المناسب
        match (true) {
            // ═══════════════════════════════════════
            // 📋 Menu Handler - القوائم والتنقل
            // ═══════════════════════════════════════
            $data === 'back_to_start' => 
                $this->menuHandler->backToStart($user, $chatId, $messageId, $callbackId),
            
            // ═══════════════════════════════════════
            // 🎁 Trial Handler - التجربة المجانية
            // ═══════════════════════════════════════
            $data === 'trial_24h' => 
                $this->trialHandler->handleTrialRequest($user, $chatId, $messageId, $callbackId),
            
            // ═══════════════════════════════════════
            // 💎 Subscription Handler - خطط الاشتراك
            // ═══════════════════════════════════════
            $data === 'show_subscriptions' => 
                $this->subscriptionHandler->showPlans($chatId, $messageId, $callbackId),
                
            str_starts_with($data, 'select_plan_') => 
                $this->subscriptionHandler->showPaymentInfo($data, $user, $chatId, $messageId, $callbackId),
            
            // ═══════════════════════════════════════
            // 💳 Payment Handler - عمليات الدفع
            // ═══════════════════════════════════════
            str_starts_with($data, 'confirm_payment_') => 
                $this->paymentHandler->requestPaymentProof($data, $user, $chatId, $callbackId),
                
            $data === 'cancel_payment' => 
                $this->paymentHandler->cancelPayment($user, $chatId, $messageId, $callbackId),
            
            // ═══════════════════════════════════════
            // 👨‍💼 Admin Handler - عمليات الأدمن
            // ═══════════════════════════════════════
            str_starts_with($data, 'approve_') => 
                $this->adminHandler->approvePayment($data, $callbackQuery),
                
            str_starts_with($data, 'reject_') => 
                $this->adminHandler->rejectPayment($data, $callbackQuery),
            
            // ═══════════════════════════════════════
            // 👤 User Info Handler - معلومات المستخدم
            // ═══════════════════════════════════════
            $data === 'start_using' => 
                $this->userInfoHandler->handleStartUsing($user, $chatId, $callbackId),
                
            $data === 'help' => 
                $this->userInfoHandler->showHelp($chatId, $callbackId),
                
            $data === 'subscription_info' => 
                $this->userInfoHandler->showSubscriptionInfo($user, $chatId, $callbackId),
            
            // ═══════════════════════════════════════
            // ❌ Unknown Callback
            // ═══════════════════════════════════════
            default => $this->handleUnknownCallback($callbackId),
        };
    }
    
    /**
     * معالج الرسائل (للصور ونصوص الدفع)
     */
    public function handleMessage($message)
    {
        $chatId = $message->getChat()->getId();
        $user = User::where('telegram_id', $chatId)->first();
        
        if (!$user) {
           return;
        }
        
        // الحصول على حالة المستخدم
        $userState = cache()->get("user_state_{$chatId}");
        
        
        // معالجة حسب الحالة
        switch ($userState) {
            case 'waiting_payment_proof':
                
                $this->paymentHandler->handlePaymentProof($message, $user);
                break;
                
            case 'waiting_transaction_id':
                
                $this->paymentHandler->handlePaymentProof($message, $user);
                break;
                
            default:
                
                // معالجة الرسائل العادية (أوامر)
                $this->handleNormalMessage($message, $user, $chatId);
        }
    }
    
    /**
     * معالجة الرسائل العادية (خارج سيناريو الدفع)
     */
    protected function handleNormalMessage($message, $user, $chatId)
    {
        if (!$message->getText()) {
            return;
        }
        
        $text = $message->getText();
        
        
        // معالجة الأوامر
        match ($text) {
            '/status' => $this->userInfoHandler->showStatus($user, $chatId),
            '/help' => $this->userInfoHandler->showHelp($chatId, null),
            default => null // تجاهل الرسائل العادية
        };
    }
    
    /**
     * معالجة Callback غير معروف
     */
    protected function handleUnknownCallback($callbackId)
    {
        
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text' => '⚠️ أمر غير معروف',
            'show_alert' => false,
        ]);
    }
}