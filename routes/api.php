<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthOtpController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\TourController;
use App\Http\Controllers\Api\PartnerTourController;
use App\Http\Controllers\Api\Admin\AdminAccountController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\PartnerController as AdminPartnerController;
use App\Http\Controllers\Api\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\TourController as AdminTourController;

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

// Social authentication routes
Route::prefix('auth/social')->controller(SocialAuthController::class)->group(function () {
    Route::get('{provider}/redirect', 'redirect');
    Route::get('{provider}/callback', 'callback');
});

Route::get('/home', [HomeController::class, 'index']);
Route::get('/home/highlight-categories', [HomeController::class, 'highlightCategories']);
Route::get('/promotions/active', [PromotionController::class, 'active']);
Route::get('/tours/trending', [TourController::class, 'trending']);
Route::get('/search/suggestions', [TourController::class, 'suggestions']);
Route::get('/tours', [TourController::class, 'index']);
Route::get('/tours/{id}', [TourController::class, 'show']);

// Partner routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/partner/tours', [PartnerTourController::class, 'index']);
    Route::get('/partner/tours/{id}', [PartnerTourController::class, 'show']);
    Route::post('/partner/tours', [PartnerTourController::class, 'store']);
    Route::put('/partner/tours/{id}', [PartnerTourController::class, 'update']);
    Route::delete('/partner/tours/{id}', [PartnerTourController::class, 'destroy']);
});

// Admin routes
Route::middleware(['auth:sanctum', 'ensure_admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', AdminDashboardController::class);

    Route::get('/users', [AdminUserController::class, 'index']);
    Route::get('/users/{id}', [AdminUserController::class, 'show']);
    Route::patch('/users/{id}/status', [AdminUserController::class, 'updateStatus']);

    Route::get('/partners', [AdminPartnerController::class, 'index']);
    Route::post('/partners', [AdminPartnerController::class, 'store']);
    Route::get('/partners/{id}', [AdminPartnerController::class, 'show']);
    Route::patch('/partners/{id}', [AdminPartnerController::class, 'update']);

    Route::get('/categories', [AdminCategoryController::class, 'index']);
    Route::post('/categories', [AdminCategoryController::class, 'store']);
    Route::put('/categories/{id}', [AdminCategoryController::class, 'update']);
    Route::delete('/categories/{id}', [AdminCategoryController::class, 'destroy']);

    Route::get('/promotions', [AdminPromotionController::class, 'index']);
    Route::post('/promotions', [AdminPromotionController::class, 'store']);
    Route::put('/promotions/{id}', [AdminPromotionController::class, 'update']);
    Route::delete('/promotions/{id}', [AdminPromotionController::class, 'destroy']);

    Route::get('/tours', [AdminTourController::class, 'index']);
    Route::get('/tours/{id}', [AdminTourController::class, 'show']);
    Route::patch('/tours/{id}/status', [AdminTourController::class, 'updateStatus']);

    Route::get('/staff', [AdminAccountController::class, 'index']);
    Route::post('/staff', [AdminAccountController::class, 'store']);
    Route::put('/staff/{id}', [AdminAccountController::class, 'update']);
    Route::delete('/staff/{id}', [AdminAccountController::class, 'destroy']);
});
