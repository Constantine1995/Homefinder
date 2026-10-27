<?php

use App\Http\Controllers\Demo\FloorPlanController;
use App\Http\Controllers\Demo\SiteController;
use App\Http\Controllers\Demo\YandexFeedController;
use Illuminate\Support\Facades\Route;

Route::get('feeds/yandex.xml', YandexFeedController::class)->name('feeds.yandex');

Route::get('plans/{layout}.svg', FloorPlanController::class)
    ->middleware('cache.headers:public;max_age=86400;etag')
    ->name('plans.show');

Route::view('privacy', 'demo.privacy')->name('privacy');

Route::get('site', SiteController::class)->name('site');
