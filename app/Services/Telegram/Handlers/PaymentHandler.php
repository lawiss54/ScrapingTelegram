<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\{TelegramLogger, AdminNotificationService};
use App\Models\{User, VerificationRequest};

class PaymentHandler
{
    /**
     * Logger used for debugging and tracking user payment flow.
     *
     * @var TelegramLogger
     */
    protected TelegramLogger $logger;

    /**
     * Inject Logger instance.
     *
     * @param TelegramLogger $logger
     */
    public function __construct(TelegramLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Step 1 — Ask user to send payment proof (image only).
     *
     * - Store user current state in cache.
     * - Store selected plan.
     * - Instruct user to send a photo.
     *
     * @param string $data
     * @param User   $user
     * @param int    $chatId
     * @param string $callbackId
     */
    public function requestPaymentProof($data, $user, $chatId, $callbackId)
    {
        // Extract plan type from callback_data
        $planType = str_replace('confirm_payment_', '', $data);

        // Set user state for 1 hour
        cache()->put("user_state_{$chatId}", 'waiting_payment_proof', now()->addHours(1));
        cache()->put("selected_plan_{$chatId}", $planType, now()->addHours(1));

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '❌ إلغاء العملية', 'callback_data' => 'cancel_payment']
                ]
            ]
        ];

        // Send instructions to user
        Telegram::sendMessage([
            'chat_id'      => $chatId,
            'text'         =>
                "📸 <b>الخطوة 1 من 2:</b> إرسال إثبات الدفع\n\n" .
                "الرجاء إرسال صورة (لا ترسل ملف فقط)\n\n" .
                "✔ إيصال الدفع\n" .
                "✔ لقطة شاشة من التحويل\n" .
                "✔ أي إثبات للعملية\n\n" .
                "⚠️ تأكد من وضوح الصورة\n\n" .
                "<i>بعد إرسال الصورة سيتم الانتقال للخطوة التالية</i>",
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ]);

        // Stop loading animation for user
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text'              => '📸 أرسل صورة إثبات الدفع الآن',
        ]);
    }

    /**
     * General handler for incoming photos / messages during payment workflow.
     *
     * - Step 1: receive image
     * - Step 2: receive transaction ID
     *
     * @param mixed $message
     * @param User  $user
     */
    public function handlePaymentProof($message, User $user)
    {
        $chatId    = $message->getChat()->getId();
        $userState = cache()->get("user_state_{$chatId}");

        $photos = $message->getPhoto();
        $text   = $message->getText();

        // Ignore if user is not in payment workflow
        if (!in_array($userState, ['waiting_payment_proof', 'waiting_transaction_id'])) {
            return;
        }

        // Step 1: waiting for image
        if ($userState === 'waiting_payment_proof') {
            if ($photos && !empty($photos)) {
                $this->handlePaymentImage($message, $user, $chatId);
            } else {
                $this->requestValidImage($chatId);
            }
            return;
        }

        // Step 2: waiting for text (transaction id)
        if ($userState === 'waiting_transaction_id') {
            if ($text && empty($photos)) {
                $this->handleTransactionId($message, $user, $chatId);
            } else {
                $this->requestValidTransactionId($chatId);
            }
            return;
        }
    }

    /**
     * Process payment proof (image).
     *
     * @param mixed $message
     * @param User  $user
     * @param int   $chatId
     */
    protected function handlePaymentImage($message, User $user, $chatId)
    {
        try {
            $photos = $message->getPhoto();

            // Validate photo exists
            if (empty($photos)) {
                $this->requestValidImage($chatId);
                return;
            }

            // Get the highest resolution image (last one in array)
            $largestPhoto = is_array($photos) ? end($photos) : $photos[count($photos) - 1];

            // Extract file_id properly from object or array
            $paymentProof = null;

            if (is_object($largestPhoto) && method_exists($largestPhoto, 'getFileId')) {
                $paymentProof = $largestPhoto->getFileId();
            } elseif (is_array($largestPhoto) && isset($largestPhoto['file_id'])) {
                $paymentProof = $largestPhoto['file_id'];
            } elseif (is_object($largestPhoto) && isset($largestPhoto->file_id)) {
                $paymentProof = $largestPhoto->file_id;
            }

            // Safety check
            if (!$paymentProof) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => '⚠️ حدث خطأ في معالجة الصورة. الرجاء المحاولة مرة أخرى.'
                ]);
                return;
            }

            // Log success
            $this->logger->info("Payment image received successfully", [
                'user_id' => $user->id,
                'file_id' => $paymentProof
            ]);

            // Save in cache
            cache()->put("payment_proof_{$chatId}", $paymentProof, now()->addHours(1));
            cache()->put("user_state_{$chatId}", 'waiting_transaction_id', now()->addHours(1));

            // Send next step instructions
            Telegram::sendMessage([
                'chat_id'    => $chatId,
                'text'       =>
                    "✅ <b>تم استلام الصورة!</b>\n\n" .
                    "📝 <b>الخطوة 2 من 2:</b> إرسال رقم العملية\n\n" .
                    "اكتب Binance Order ID أو كلمة (بريدي موب) إذا دفعت عبر البريد.\n" .
                    "مثال: 397732846026694657",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '❌ إلغاء العملية', 'callback_data' => 'cancel_payment']]
                    ]
                ])
            ]);
        } catch (\Exception $e) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text'    => '⚠️ حدث خطأ في معالجة الصورة. الرجاء المحاولة مرة أخرى.'
            ]);
        }
    }

    /**
     * Process transaction ID and create verification request.
     *
     * @param mixed $message
     * @param User  $user
     * @param int   $chatId
     */
    protected function handleTransactionId($message, User $user, $chatId)
    {
        $transactionId = $message->getText();
        $planType      = cache()->get("selected_plan_{$chatId}");
        $paymentProof  = cache()->get("payment_proof_{$chatId}");

        // Validate session data
        if (!$planType || !$paymentProof) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text'    => '⚠️ انتهت جلسة الدفع. الرجاء البدء من جديد.',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '🔄 العودة للقائمة', 'callback_data' => 'back_to_start']]
                    ]
                ])
            ]);

            $this->clearUserCache($chatId);
            return;
        }

        try {
            // Create new verification request
            $request = $this->createVerificationRequest($user, $planType, $paymentProof, $transactionId);

            // Clear session cache
            $this->clearUserCache($chatId);

            // Notify admins
            try {
                app(AdminNotificationService::class)->sendVerificationRequest($request);
            } catch (\Exception $adminError) {
                // Continue even if admin delivery fails
            }

            // Confirm to user
            $this->sendConfirmationMessage($chatId, $request, $planType, $transactionId);
        } catch (\Exception $e) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text'    => '⚠️ حدث خطأ في معالجة الطلب. الرجاء المحاولة مرة أخرى.',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '🔄 العودة للقائمة', 'callback_data' => 'back_to_start']]
                    ]
                ])
            ]);
        }
    }

    /**
     * Skip entering transaction ID, continue flow without it.
     */
    public function skipTransactionId($user, $chatId, $callbackId)
    {
        $this->logger->info("Transaction ID skipped - START", ['user_id' => $user->id]);

        // Prevent duplicate execution
        $lockKey = "skip_lock_{$user->id}";

        if (cache()->has($lockKey)) {
            $this->logger->warning("Skip already in progress - IGNORED", ['user_id' => $user->id]);

            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text'              => '⏳ جاري المعالجة...'
            ]);

            return;
        }

        cache()->put($lockKey, true, now()->addSeconds(15));

        try {
            $planType     = cache()->get("selected_plan_{$chatId}");
            $paymentProof = cache()->get("payment_proof_{$chatId}");

            if (!$planType || !$paymentProof) {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackId,
                    'text'              => '⚠️ حدث خطأ. حاول من جديد',
                    'show_alert'        => true
                ]);
                return;
            }

            $request = $this->createVerificationRequest($user, $planType, $paymentProof, null);

            // Clear cached workflow
            $this->clearUserCache($chatId);

            app(AdminNotificationService::class)->sendVerificationRequest($request);

            // Confirm to user
            Telegram::sendMessage([
                'chat_id'    => $chatId,
                'text'       =>
                    "✅ <b>تم استلام طلبك!</b>\n\n" .
                    "🔖 رقم الطلب: <code>#{$request->id}</code>\n" .
                    "📦 الخطة: {$planType}\n\n" .
                    "⏳ جاري المراجعة...\n" .
                    "⏱️ الرد عادة بين 15 و30 دقيقة\n\n" .
                    "سنرسل لك إشعاراً فور الموافقة! 🔔",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'back_to_start']]
                    ]
                ])
            ]);

            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text'              => '✅ تم إرسال الطلب'
            ]);

            $this->logger->success("Skip transaction completed", [
                'request_id' => $request->id,
                'user_id'    => $user->id
            ]);
        } catch (\Exception $e) {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text'              => '⚠️ حدث خطأ. حاول مرة أخرى',
                'show_alert'        => true
            ]);
        } finally {
            cache()->forget($lockKey);
        }
    }

    /**
     * User cancels payment process.
     */
    public function cancelPayment($user, $chatId, $messageId, $callbackId)
    {
        $this->clearUserCache($chatId);

        Telegram::editMessageText([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       =>
                "❌ تم إلغاء عملية الدفع.\n\n" .
                "يمكنك البدء من جديد في أي وقت.",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🔄 العودة للقائمة', 'callback_data' => 'back_to_start']]
                ]
            ])
        ]);

        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text'              => '❌ تم الإلغاء'
        ]);
    }

    /**
     * Ask user to send a valid image.
     */
    protected function requestValidImage($chatId)
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text'    =>
                "⚠️ الرجاء إرسال صورة إثبات الدفع.\n\n" .
                "📸 يمكنك إرسال:\n" .
                "• صورة الإيصال\n" .
                "• لقطة شاشة من التحويل\n" .
                "• أي إثبات للعملية",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '❌ إلغاء العملية', 'callback_data' => 'cancel_payment']]
                ]
            ])
        ]);
    }

    /**
     * Ask for proper transaction ID.
     */
    protected function requestValidTransactionId($chatId)
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text'    =>
                "⚠️ الرجاء إرسال رقم العملية كنص فقط.\n\n" .
                "مثال: TRX123456789",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '❌ إلغاء العملية', 'callback_data' => 'cancel_payment']]
                ]
            ])
        ]);
    }

    /**
     * Create a new verification request.
     */
    protected function createVerificationRequest(User $user, string $planType, string $paymentProof, ?string $transactionId): VerificationRequest
    {
        return VerificationRequest::create([
            'user_id'        => $user->id,
            'plan_type'      => $planType,
            'payment_proof'  => $paymentProof,
            'transaction_id' => $transactionId,
            'status'         => 'pending',
        ]);
    }

    /**
     * Send confirmation message to the user.
     */
    protected function sendConfirmationMessage($chatId, VerificationRequest $request, string $planType, ?string $transactionId)
    {
        $transactionText = $transactionId
            ? "🔢 رقم العملية: {$transactionId}\n"
            : "";

        Telegram::sendMessage([
            'chat_id'    => $chatId,
            'text'       =>
                "✅ <b>تم استلام طلبك!</b>\n\n" .
                "🔖 رقم الطلب: <code>#{$request->id}</code>\n" .
                "📦 الخطة: {$planType}\n" .
                $transactionText . "\n" .
                "⏳ جاري المراجعة...\n" .
                "⏱️ الرد عادة بين 15-30 دقيقة\n\n" .
                "سنرسل لك إشعاراً فور الموافقة! 🔔",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'back_to_start']]
                ]
            ])
        ]);
    }

    /**
     * Clear user temporary session data from cache.
     */
    protected function clearUserCache($chatId)
    {
        cache()->forget("user_state_{$chatId}");
        cache()->forget("selected_plan_{$chatId}");
        cache()->forget("payment_proof_{$chatId}");
    }
}