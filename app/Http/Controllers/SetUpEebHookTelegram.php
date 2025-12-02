<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Http;

use Illuminate\Http\Request;

class SetUpEebHookTelegram extends Controller
{
    public function SetUpWebhook(){
      $botToken = env('TELEGRAM_BOT_TOKEN');
      $webhookUrl = env('TELEGRAM_WEBHOOK_URL');
      
      $response = Http::post("https://api.telegram.org/bot{$botToken}/setWebhook", [
          'url' => $webhookUrl
      ]);
      
      if ($response->successful()) {
        return response()->json(["success" => "Webhook set successfully!"], 200);
      } else {
        return response()->json(["Error" => $response->body()], 400);
      }
    }
}




