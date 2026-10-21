<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Authentication
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:auth')->name('register');
        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:auth')->name('login');

    });
});
