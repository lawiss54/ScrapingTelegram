<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;
use App\Models\User;
use App\Services\TelegramLogger;
use App\Services\UserService;
use App\Services\MenuService;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected string $description = 'بدء استخدام البوت';
    
    protected TelegramLogger $logger;
    protected UserService $userService;
    protected MenuService $menuService;
    
    public function __construct()
    {
        $this->logger = new TelegramLogger();
        $this->userService = new UserService();
        $this->menuService = new MenuService();
    }

    public function handle()
    {
        try {
            $update = $this->getUpdate();
            $message = $update->getMessage();
            
            if (!$message) {
                return;
            }
            
            $telegramUser = $message->getFrom();
            
            if (!$telegramUser) {
               $this->replyWithMessage(['text' => '❌ خطأ في استقبال بيانات المستخدم']);
                return;
            }
            
            $this->logger->info("User data received", [
                'telegram_id' => $telegramUser->getId(),
                'username' => $telegramUser->getUsername(),
                'first_name' => $telegramUser->getFirstName()
            ]);
            
            // إنشاء أو تحديث المستخدم
            $user = $this->userService->createOrUpdateFromTelegram($telegramUser);
          
            
            // عرض القائمة المناسبة
           
            if ($user->hasActiveSubscription()) {
                
                $this->menuService->showMainMenu($user);  // ✅ بدون $this
            } else {
                
                $this->menuService->showWelcomeMessage($user);  // ✅ بدون $this
            }
            
        } catch (\Exception $e) {
            
            $this->replyWithMessage([
                'text' => '❌ حدث خطأ. يرجى المحاولة مرة أخرى.'
            ]);
        }
    }
}