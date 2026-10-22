<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentGatewayController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Authentication
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:auth')->name('register');
        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:auth')->name('login');
    });

    // Everything below requires a valid JWT.
    Route::middleware(['auth:api', 'throttle:60,1'])->group(function () {

        // Orders
        Route::apiResource('orders', OrderController::class);
        Route::get('orders/{order}/payments', [PaymentController::class, 'forOrder'])
            ->name('orders.payments.index');

        // Payments
        Route::get('payment-gateways', [PaymentGatewayController::class, 'index'])
            ->name('payment-gateways.index');
        Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show']);
    });
});
