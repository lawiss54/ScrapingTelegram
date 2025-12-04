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
        try {
            // 1. فحص الاتصال
           \DB::connection()->getPdo();
            
            
            // 2. البحث عن المستخدم
           $existingUser = \DB::table('users')
                ->where('telegram_id', $telegramId)
                ->first();
            
            
            // 3. تحضير البيانات
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
            // 4. الحفظ
            
            if ($existingUser) {
                
                $affected = \DB::table('users')
                    ->where('telegram_id', $telegramId)
                    ->update($userData);
                
                $user = User::where('telegram_id', $telegramId)->first();
                
            } else {
                
                try {
                    $userId = \DB::table('users')->insertGetId($userData);
                    
                } catch (\Illuminate\Database\QueryException $e) {
                    
                    // محاولة استخدام Eloquent بدلاً من Query Builder
                    $user = User::create([
                        'telegram_id' => $telegramId,
                        'username' => $telegramUser->getUsername(),
                        'first_name' => $telegramUser->getFirstName() ?? 'مستخدم',
                        'is_active' => true,
                    ]);
                    
                    return $user;
                }
                
                $user = User::find($userId);
            }
            
            if (!$user) {
                throw new \Exception("Failed to retrieve user after save");
            }
            
            return $user;
            
        } catch (\Illuminate\Database\QueryException $e) {
            throw $e;
            
        } catch (\PDOException $e) {
            
            throw $e;
            
        } catch (\Exception $e) {
            
            throw $e;
        }
    }
}