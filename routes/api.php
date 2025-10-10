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

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);
});

Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

use App\Http\Controllers\Api\PartnerTourController;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/partner/tours', [PartnerTourController::class, 'index']);
    Route::get('/partner/tours/{id}', [PartnerTourController::class, 'show']);
    Route::post('/partner/tours', [PartnerTourController::class, 'store']);
    Route::put('/partner/tours/{id}', [PartnerTourController::class, 'update']);
    Route::delete('/partner/tours/{id}', [PartnerTourController::class, 'destroy']);
});
