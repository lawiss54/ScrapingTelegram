<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{TelegramWebhookController, SetUpEebHookTelegram};

Route::get('/telegram/setup-webhook', [SetUpEebHookTelegram::class, 'SetUpWebhook']);
Route::any('/telegram/webhook', [TelegramWebhookController::class, 'handle']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
