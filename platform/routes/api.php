<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\IdentifyController;
use App\Http\Controllers\Api\PingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['site.signed', 'throttle:site-api'])
    ->group(function () {
        Route::get('ping', PingController::class);
        Route::post('events', [EventController::class, 'store']);
        Route::post('identify', IdentifyController::class);
        Route::post('catalog/sync', [CatalogController::class, 'sync']);
    });
