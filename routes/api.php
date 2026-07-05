<?php


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\LandingController;
use App\Http\Controllers\AuthController;

use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentJobController;

Route::get('/landing/stats', [LandingController::class, 'stats']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/student/dashboard', [StudentDashboardController::class, 'index']);
    Route::get('/student/recommended-jobs', [StudentJobController::class, 'recommended']);
});
