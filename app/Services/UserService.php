<?php

namespace App\Services;

use App\Models\User;
use App\Services\TelegramLogger;
use Telegram\Bot\Objects\User as TelegramUser;
use Illuminate\Support\Facades\DB;

class UserService
{
    /**
     * Logger مخصص لتتبّع عمليات إنشاء/تحديث المستخدمين.
     *
     * @var TelegramLogger
     */
    protected TelegramLogger $logger;

    /**
     * إنشاء الخدمة وتفعيل الـ Logger.
     */
    public function __construct()
    {
        $this->logger = new TelegramLogger();
    }

    /**
     * إنشاء أو تحديث مستخدم من بيانات Telegram.
     *
     * هذه العملية تمر بمراحل:
     * 1. فحص اتصال قاعدة البيانات.
     * 2. البحث عن المستخدم بناءً على telegram_id.
     * 3. تجهيز بيانات الحفظ.
     * 4. إنشاء أو تحديث حسب الحالة.
     * 5. إرجاع كائن User النهائي.
     *
     * @param TelegramUser $telegramUser
     * @return User
     *
     * @throws \Exception|\PDOException|\Illuminate\Database\QueryException
     */
    public function createOrUpdateFromTelegram(TelegramUser $telegramUser): User
    {
        $telegramId = $telegramUser->getId();

        try {
            /** ------------------------------------------------------
             * 1) فحص اتصال قاعدة البيانات
             * ------------------------------------------------------ */
            DB::connection()->getPdo();

            /** ------------------------------------------------------
             * 2) البحث عن المستخدم القديم via Query Builder
             * ------------------------------------------------------ */
            $existingUser = DB::table('users')
                ->where('telegram_id', $telegramId)
                ->first();

            /** ------------------------------------------------------
             * 3) تحضير بيانات الحفظ المشتركة
             * ------------------------------------------------------ */
            $userData = [
                'telegram_id' => $telegramId,
                'username'    => $telegramUser->getUsername(),
                'first_name'  => $telegramUser->getFirstName() ?? 'مستخدم',
                'is_active'   => 1,
                'updated_at'  => now(),
            ];

            // إضافة created_at عند إنشاء مستخدم جديد
            if (!$existingUser) {
                $userData['created_at'] = now();
            }

            /** ------------------------------------------------------
             * 4) الحفظ (Update أو Insert)
             * ------------------------------------------------------ */
            if ($existingUser) {

                // تحديث البيانات
                DB::table('users')
                    ->where('telegram_id', $telegramId)
                    ->update($userData);

                // جلب نسخة Eloquent
                $user = User::where('telegram_id', $telegramId)->first();

            } else {
                /**
                 * INSERT جديد للمستخدم
                 * مع fallback إلى Eloquent في حال خطأ Key Duplicated مثلاً
                 */
                try {
                    $userId = DB::table('users')->insertGetId($userData);
                    $user   = User::find($userId);

                } catch (\Illuminate\Database\QueryException $e) {

                    // في حال فشل QueryBuilder → إعادة المحاولة بـ Eloquent
                    $user = User::create([
                        'telegram_id' => $telegramId,
                        'username'    => $telegramUser->getUsername(),
                        'first_name'  => $telegramUser->getFirstName() ?? 'مستخدم',
                        'is_active'   => true,
                    ]);

                    return $user;
                }
            }

            /** ------------------------------------------------------
             * 5) التحقق النهائي من وجود الـ User
             * ------------------------------------------------------ */
            if (!$user) {
                throw new \Exception("Failed to retrieve user after save");
            }

            return $user;

        } catch (\Illuminate\Database\QueryException $e) {
            // خطأ Query
            $this->logger->error("QueryException: " . $e->getMessage());
            throw $e;

        } catch (\PDOException $e) {
            // خطأ اتصال قاعدة البيانات
            $this->logger->error("PDOException: " . $e->getMessage());
            throw $e;

        } catch (\Exception $e) {
            // أي خطأ عام آخر
            $this->logger->error("General Exception: " . $e->getMessage());
            throw $e;
        }
    }
}