<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StatisticsController;
use App\Http\Middleware\SetCurrentTenant;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware(['auth', SetCurrentTenant::class])->group(function (): void {
    Route::get('/catalog', CatalogController::class)->name('catalog');
    Route::get('/search', SearchController::class)->name('search');
    Route::get('/chat/{conversation?}', [ChatController::class, 'show'])->name('chat');
    Route::post('/chat', [ChatController::class, 'store'])->name('chat.store');
    Route::post('/chat/{conversation}/messages', [ChatController::class, 'send'])->name('chat.messages.store');
    Route::post('/chat/{conversation}/saved-searches/{savedSearch}/unsubscribe', [ChatController::class, 'unsubscribe'])->name('chat.saved-searches.unsubscribe');
    Route::get('/inbox/{conversation?}', [InboxController::class, 'show'])->name('inbox');
    Route::post('/inbox/{conversation}/messages', [InboxController::class, 'reply'])->name('inbox.messages.store');
    Route::post('/inbox/{conversation}/take', [InboxController::class, 'take'])->name('inbox.take');
    Route::post('/inbox/{conversation}/hand-back', [InboxController::class, 'handBack'])->name('inbox.hand-back');
    Route::post('/inbox/{conversation}/close', [InboxController::class, 'close'])->name('inbox.close');
    Route::get('/statistics', StatisticsController::class)->name('statistics');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
