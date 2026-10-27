<?php

use App\Http\Controllers\Webhooks\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('telegram/{channel}', TelegramWebhookController::class)->name('telegram');
