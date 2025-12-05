<?php

namespace App\Http\Controllers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\{TelegramBotService, TelegramLogger};
use App\Models\User;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    /**
     * خدمة البوت لمعالجة المنطق العام.
     */
    protected TelegramBotService $botService;

    /**
     * لوجر مخصص لتتبع الأحداث و الأخطاء.
     */
    protected TelegramLogger $logger;

    /**
     * حقن الخدمات الأساسية داخل الكنترولر.
     *
     * @param TelegramBotService $botService
     */
    public function __construct(TelegramBotService $botService)
    {
        $this->botService = $botService;

        // إنشاء اللوجر يدوياً (ممكن لاحقاً استعمال الـ Container).
        $this->logger = new TelegramLogger();
    }

    /**
     * معالج الـ Webhook الرئيسي:
     * - يستقبل جميع الـ Updates من تلغرام
     * - يعالج الأوامر عبر commandsHandler
     * - يحدد نوع الـ Update (callback/message)
     * - يسجّل الأحداث والأخطاء
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handle(Request $request)
    {
        try {
            // Telegram SDK: يشغّل أوامر مثل /start قبل أي معالجة يدوية
            $update = Telegram::commandsHandler(true);

            // تسجيل نوع الـ Update
            $this->logger->info("Webhook received", [
                'has_callback' => $update->has('callback_query'),
                'has_message' => $update->has('message'),
            ]);

            // توجيه حسب النوع
            if ($update->has('callback_query')) {
                $this->handleCallbackQuery($update->getCallbackQuery());
            } elseif ($update->has('message')) {
                $this->handleMessage($update->getMessage());
            }

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {

            // تسجيل الأخطاء بكل التفاصيل
            $this->logger->error("Webhook error", [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * معالجة Callback Query:
     * - أي ضغط على زر inline في تلغرام يصل هنا
     *
     * @param \Telegram\Bot\Objects\CallbackQuery $callbackQuery
     */
    protected function handleCallbackQuery($callbackQuery)
    {
        $this->logger->info("Callback query received", [
            'data' => $callbackQuery->getData(),
            'from' => $callbackQuery->getFrom()->getId(),
        ]);

        // إرسال الـ Callback لمعالجته في الخدمة
        $this->botService->handleCallback($callbackQuery);
    }

    /**
     * معالجة الرسائل النصية أو الصور:
     * - يتحقق من وجود المستخدم
     * - يسجل نوع الرسالة
     * - يفوض المعالجة للخدمة
     *
     * @param \Telegram\Bot\Objects\Message $message
     */
    protected function handleMessage($message)
    {
        $chatId = $message->getChat()->getId();

        // محاولة إيجاد المستخدم حسب Telegram ID
        $user = User::where('telegram_id', $chatId)->first();

        if (!$user) {
            // غير مسجل → نرسل رسالة ترحيب مع زر التسجيل
            $this->handleUnregisteredUser($chatId);
            return;
        }

        // تسجيل معلومات الرسالة
        $this->logger->info("Message received", [
            'user_id'   => $user->id,
            'chat_id'   => $chatId,
            'has_photo' => $message->getPhoto() ? 1 : 0,
            'has_text'  => $message->getText() ? 1 : 0,
        ]);

        // تمرير الرسالة للخدمة لمعالجتها
        $this->botService->handleMessage($message);
    }

    /**
     * حالة: مستخدم غير مسجل في النظام.
     * نرسل له رسالة ترحيب و زر التسجيل.
     *
     * @param int|string $chatId
     */
    protected function handleUnregisteredUser($chatId)
    {
        $this->logger->warning("Unregistered user", [
            'chat_id' => $chatId,
        ]);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text'    =>
                "👋 مرحباً بك!\n\n" .
                "يبدو أنك مستخدم جديد.\n" .
                "الرجاء استخدام الأمر /start للبدء",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '🚀 ابدأ الآن',
                            'callback_data' => 'register_user',
                        ],
                    ],
                ],
            ]),
        ]);
    }
}