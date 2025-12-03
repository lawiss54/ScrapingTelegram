<?php

namespace App\Services;

use App\Models\User;
use Telegram\Bot\Objects\User as TelegramUser;

class UserService
{
    protected TelegramLogger $logger;
    
    public function __construct()
    {
        $this->logger = new TelegramLogger();
    }
    
    public function createOrUpdateFromTelegram(TelegramUser $telegramUser): User
    {
        $telegramId = $telegramUser->getId();
        
        $this->logger->info("Starting user process", ['telegram_id' => $telegramId]);
        
        try {
            // 1. فحص الاتصال
            $this->logger->info("Step 1: Checking DB connection");
            \DB::connection()->getPdo();
            $this->logger->success("DB connection OK");
            
            // 2. البحث عن المستخدم
            $this->logger->info("Step 2: Searching for user");
            $existingUser = \DB::table('users')
                ->where('telegram_id', $telegramId)
                ->first();
            $this->logger->success("Search completed", [
                'found' => $existingUser ? 'yes' : 'no'
            ]);
            
            // 3. تحضير البيانات
            $this->logger->info("Step 3: Preparing data");
            $userData = [
                'telegram_id' => $telegramId,
                'username' => $telegramUser->getUsername(),
                'first_name' => $telegramUser->getFirstName() ?? 'مستخدم',
                'is_active' => true,
                'updated_at' => now(),
            ];
            
            if (!$existingUser) {
                $userData['created_at'] = now();
            }
            
            $this->logger->info("Data prepared", $userData);
            
            // 4. الحفظ
            $this->logger->info("Step 4: Saving to database");
            
            if ($existingUser) {
                \DB::table('users')
                    ->where('telegram_id', $telegramId)
                    ->update($userData);
                $user = User::where('telegram_id', $telegramId)->first();
                $this->logger->success("User updated", ['id' => $user->id]);
            } else {
                $userId = \DB::table('users')->insertGetId($userData);
                $user = User::find($userId);
                $this->logger->success("User created", ['id' => $userId]);
            }
            
            if (!$user) {
                throw new \Exception("Failed to retrieve user after save");
            }
            
            return $user;
            
        } catch (\Exception $e) {
            $this->logger->exception($e);
            throw $e;
        }
    }
}