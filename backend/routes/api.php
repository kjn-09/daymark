<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HolidayController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware(['web', 'limit.registrations']);
Route::middleware('web')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/mobile/verify', [AuthController::class, 'verifyMobile']);
    Route::post('/mobile/resend', [AuthController::class, 'resendMobileCode']);
});
Route::get('/holidays/{year}', [HolidayController::class, 'index']);

Route::middleware(['web', 'auth.api'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/accounts', [AuthController::class, 'accounts']);
});
