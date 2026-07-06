<?php


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\LandingController;
use App\Http\Controllers\AuthController;

use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentJobController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\ResumeController;



Route::get('/landing/stats', [LandingController::class, 'stats']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);

    //Dashboard
    Route::get('/student/dashboard', [StudentDashboardController::class, 'index']);
    Route::get('/student/recommended-jobs', [StudentJobController::class, 'recommended']);
    
    // Student Profile
    Route::get('/student/profile', [StudentController::class, 'profile']);
    Route::put('/student/profile', [StudentController::class, 'update']);

    // Resume Routes
    Route::get('/student/resume', [ResumeController::class, 'index']);
    Route::post('/student/resume', [ResumeController::class, 'store']);
    Route::put('/student/resume/{id}', [ResumeController::class, 'update']);
    Route::delete('/student/resume/{id}', [ResumeController::class, 'destroy']);
    Route::post('/student/resume/ai-improve', [ResumeController::class, 'aiImprove']);
    Route::post('/student/resume/{id}/generate-pdf', [ResumeController::class, 'generatePdf']);
});
