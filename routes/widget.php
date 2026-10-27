<?php

use App\Http\Controllers\Widget\ConversationController;
use App\Http\Controllers\Widget\VisitorController;
use App\Http\Middleware\SetCurrentTenant;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::get('channels/{channel:external_id}', [VisitorController::class, 'channel'])->name('channels.show');
Route::post('channels/{channel:external_id}/visitors', [VisitorController::class, 'store'])
    ->middleware('throttle:widget-visitors')
    ->name('visitors.store');

Route::middleware(['auth:widget', SetCurrentTenant::class])->group(function (): void {
    Route::get('conversation', [ConversationController::class, 'show'])->name('conversation.show');
    Route::post('consent', [ConversationController::class, 'consent'])->name('consent.store');
    Route::delete('saved-searches/{savedSearch}', [ConversationController::class, 'unsubscribe'])->name('saved-searches.destroy');

    Route::middleware('throttle:widget-messages')->group(function (): void {
        Route::post('messages', [ConversationController::class, 'send'])->name('messages.store');
        Route::post('flats/{flat}/{request}', [ConversationController::class, 'flat'])
            ->whereIn('request', ['booking', 'similar'])
            ->name('flats.requests.store');
        Route::post('phone', [ConversationController::class, 'phone'])->name('phone.store');
    });
});

// The widget's private channel, authorized by the visitor's token rather than a session (routes/channels.php).
Broadcast::routes(['middleware' => ['auth:widget']]);
