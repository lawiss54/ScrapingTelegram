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
            $this->logger->info("START COMMAND TRIGGERED");
            
            $update = $this->getUpdate();
            $message = $update->getMessage();
            
            if (!$message) {
                $this->logger->warning("No message found");
                return;
            }
            
            $telegramUser = $message->getFrom();
            
            if (!$telegramUser) {
                $this->logger->error("No user data in message");
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
            
            $this->logger->success("User processed", [
                'id' => $user->id,
                'telegram_id' => $user->telegram_id
            ]);
            
            // عرض القائمة المناسبة
            if ($user->hasActiveSubscription()) {
                $this->menuService->showMainMenu($this, $user);
            } else {
                $this->menuService->showWelcomeMessage($this, $user);
            }
            
            $this->logger->success("START COMMAND COMPLETED");
            
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $this->replyWithMessage([
                'text' => '❌ حدث خطأ. يرجى المحاولة مرة أخرى.'
            ]);
        }
    }
}