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
                'is_active' => 1,
                'updated_at' => now(),
            ];
            
            if (!$existingUser) {
                $userData['created_at'] = now();
            }
            
            $this->logger->info("Data prepared", $userData);
            
            // 4. الحفظ
            $this->logger->info("Step 4: Saving to database");
            
            if ($existingUser) {
                $this->logger->info("Step 4.1: Updating existing user");
                
                $affected = \DB::table('users')
                    ->where('telegram_id', $telegramId)
                    ->update($userData);
                
                $this->logger->success("Update query executed", ['affected_rows' => $affected]);
                
                $user = User::where('telegram_id', $telegramId)->first();
                $this->logger->success("User retrieved after update");
                
            } else {
                $this->logger->info("Step 4.2: Creating new user");
                
                try {
                    $userId = \DB::table('users')->insertGetId($userData);
                    $this->logger->success("Insert completed", ['new_id' => $userId]);
                    
                } catch (\Illuminate\Database\QueryException $e) {
                    $this->logger->error("Insert failed", [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode()
                    ]);
                    
                    // محاولة استخدام Eloquent بدلاً من Query Builder
                    $this->logger->info("Trying Eloquent create instead");
                    $user = User::create([
                        'telegram_id' => $telegramId,
                        'username' => $telegramUser->getUsername(),
                        'first_name' => $telegramUser->getFirstName() ?? 'مستخدم',
                        'is_active' => true,
                    ]);
                    
                    $this->logger->success("Eloquent create succeeded", ['id' => $user->id]);
                    return $user;
                }
                
                $this->logger->info("Fetching created user");
                $user = User::find($userId);
                $this->logger->success("User fetched");
            }
            
            if (!$user) {
                $this->logger->error("User is NULL after save!");
                throw new \Exception("Failed to retrieve user after save");
            }
            
            $this->logger->success("User process completed", [
                'id' => $user->id,
                'telegram_id' => $user->telegram_id
            ]);
            
            return $user;
            
        } catch (\Illuminate\Database\QueryException $e) {
            $this->logger->error("Database Query Exception", [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql' => $e->getSql() ?? 'N/A'
            ]);
            throw $e;
            
        } catch (\PDOException $e) {
            $this->logger->error("PDO Exception", [
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            throw $e;
            
        } catch (\Exception $e) {
            $this->logger->error("General Exception", [
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }
}