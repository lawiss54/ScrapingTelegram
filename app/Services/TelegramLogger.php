<?php

namespace App\Services;

use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramLogger
{
    protected $adminId;
    protected bool $enabled = true;
    
    public function __construct()
    {
        $this->adminId = config('telegram.bots.mybot.admin_ids.0');
    }
    
    public function info(string $message, array $data = [])
    {
        $this->send("ℹ️ {$message}", $data);
    }
    
    public function success(string $message, array $data = [])
    {
        $this->send("✅ {$message}", $data);
    }
    
    public function warning(string $message, array $data = [])
    {
        $this->send("⚠️ {$message}", $data);
    }
    
    public function error(string $message, array $data = [])
    {
        $this->send("❌ {$message}", $data);
    }
    
    public function exception(\Exception $e)
    {
        $message = "❌ EXCEPTION:\n\n"
            . "Message: {$e->getMessage()}\n"
            . "File: {$e->getFile()}\n"
            . "Line: {$e->getLine()}\n\n"
            . "Trace:\n" . substr($e->getTraceAsString(), 0, 500);
        
        $this->send($message);
        
        \Log::error('Telegram Command Error', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
    
    protected function send(string $message, array $data = [])
    {
        if (!$this->enabled || !$this->adminId) {
            return;
        }
        
        try {
            $timestamp = date('H:i:s');
            $fullMessage = "🔍 [{$timestamp}] {$message}";
            
            if (!empty($data)) {
                $fullMessage .= "\n\n" . $this->formatData($data);
            }
            
            Telegram::sendMessage([
                'chat_id' => $this->adminId,
                'text' => $fullMessage,
                'parse_mode' => 'HTML'
            ]);
            
            usleep(100000); // تأخير 0.1 ثانية
            
        } catch (\Exception $e) {
            \Log::error('Logger failed: ' . $e->getMessage());
        }
    }
    
    protected function formatData(array $data): string
    {
        $formatted = [];
        foreach ($data as $key => $value) {
            $formatted[] = "<b>{$key}:</b> " . ($value ?? 'null');
        }
        return implode("\n", $formatted);
    }
    
    public function disable()
    {
        $this->enabled = false;
    }
    
    public function enable()
    {
        $this->enabled = true;
    }
}