<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthOtpController;
use App\Http\Controllers\Api\SocialAuthController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);
});

Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// OTP-based unified auth flow
Route::prefix('auth')->controller(AuthOtpController::class)->group(function () {
    Route::post('/send-otp', 'send');
    Route::post('/verify-otp', 'verify');
    Route::post('/set-password', 'setPassword');
});

Route::prefix('auth/social')->controller(SocialAuthController::class)->group(function () {
    Route::get('{provider}/redirect', 'redirect');
    Route::get('{provider}/callback', 'callback');
});

use App\Http\Controllers\Api\PartnerTourController;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/partner/tours', [PartnerTourController::class, 'index']);
    Route::get('/partner/tours/{id}', [PartnerTourController::class, 'show']);
    Route::post('/partner/tours', [PartnerTourController::class, 'store']);
    Route::put('/partner/tours/{id}', [PartnerTourController::class, 'update']);
    Route::delete('/partner/tours/{id}', [PartnerTourController::class, 'destroy']);
});
