<?php

namespace App\Services;

use Telegram\Bot\Objects\Message;

class DebugHelper
{
    /**
     * Debug Telegram Message Object
     */
    public static function debugMessage(Message $message, TelegramLogger $logger)
    {
        $debug = [
            'message_id' => $message->getMessageId(),
            'chat_id' => $message->getChat()->getId(),
            'from_id' => $message->getFrom() ? $message->getFrom()->getId() : null,
            'date' => $message->getDate(),
            
            // Text
            'has_text_method' => method_exists($message, 'getText'),
            'text_value' => $message->getText(),
            'text_is_null' => $message->getText() === null,
            'text_is_empty' => empty($message->getText()),
            
            // Photo
            'has_photo_method' => method_exists($message, 'getPhoto'),
            'photo_value' => $message->getPhoto(),
            'photo_is_null' => $message->getPhoto() === null,
            'photo_is_array' => is_array($message->getPhoto()),
            'photo_count' => is_array($message->getPhoto()) ? count($message->getPhoto()) : 0,
            
            // Caption (الصور قد تحتوي على caption)
            'has_caption' => $message->getCaption() !== null,
            'caption_value' => $message->getCaption(),
            
            // Document
            'has_document' => $message->getDocument() !== null,
            
            // Raw data
            'raw_keys' => array_keys($message->all()),
        ];
        
        $logger->info("🔍 DEBUG MESSAGE OBJECT", $debug);
        
        return $debug;
    }
    
    /**
     * Debug Cache State
     */
    public static function debugCacheState($chatId, TelegramLogger $logger)
    {
        $debug = [
            'chat_id' => $chatId,
            'user_state' => cache()->get("user_state_{$chatId}"),
            'selected_plan' => cache()->get("selected_plan_{$chatId}"),
            'payment_proof' => cache()->get("payment_proof_{$chatId}"),
            
            'user_state_exists' => cache()->has("user_state_{$chatId}"),
            'selected_plan_exists' => cache()->has("selected_plan_{$chatId}"),
            'payment_proof_exists' => cache()->has("payment_proof_{$chatId}"),
        ];
        
        $logger->info("🔍 DEBUG CACHE STATE", $debug);
        
        return $debug;
    }
    
    /**
     * Test Message Methods
     */
    public static function testMessageMethods(Message $message, TelegramLogger $logger)
    {
        $tests = [];
        
        // Test different ways to check for photo
        $tests['getPhoto()'] = $message->getPhoto();
        $tests['getPhoto() is null'] = $message->getPhoto() === null;
        $tests['getPhoto() is false'] = $message->getPhoto() === false;
        $tests['getPhoto() is empty'] = empty($message->getPhoto());
        $tests['getPhoto() count'] = is_array($message->getPhoto()) ? count($message->getPhoto()) : 'not array';
        
        // Test has() method if exists
        if (method_exists($message, 'has')) {
            $tests['has(photo)'] = $message->has('photo');
        }
        
        // Test raw data
        $allData = $message->all();
        $tests['raw data has photo'] = isset($allData['photo']);
        $tests['raw photo value'] = $allData['photo'] ?? 'not set';
        
        $logger->info("🔍 DEBUG MESSAGE METHODS TEST", $tests);
        
        return $tests;
    }
}