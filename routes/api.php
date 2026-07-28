<?php


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\LandingController;
use App\Http\Controllers\AuthController;

use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentJobController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\ResumeController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\AIAssistantController;
use App\Http\Controllers\CompanyDashboardController;
use App\Http\Controllers\CompanyController;



Route::get('/landing/stats', [LandingController::class, 'stats']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

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

    //job posts
    Route::get('/jobs', [JobController::class, 'index']);
    Route::get('/jobs/{id}', [JobController::class, 'show']);

    Route::post('/jobs/{id}/save', [JobController::class, 'saveJob']);
    Route::delete('/jobs/{id}/save', [JobController::class, 'removeSaveJob']);
    Route::get('/jobs/{id}/saved', [JobController::class, 'checkSaved']);

    Route::get('/student/saved-jobs', [JobController::class, 'savedJobs']);

    //Apply 
    Route::post('/jobs/{id}/apply', [JobController::class, 'applyJob']);
    Route::get('/jobs/{id}/applied', [JobController::class, 'checkApplied']);
    Route::delete('/jobs/{id}/apply', [JobController::class, 'withdrawApplication']);
    Route::get('/student/applications', [JobController::class, 'myApplications']);

    //Recommendation
    Route::get('/student/recommended-jobs', [JobController::class, 'recommendedJobs']);

    //Messages
    Route::get('/messages', [MessageController::class, 'index']);
    Route::get('/messages/{user}', [MessageController::class, 'show']);
    Route::post('/messages', [MessageController::class, 'store']);

    //Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    //Settings
    Route::put('/settings/password', [SettingsController::class, 'changePassword']);
    Route::get('/settings/notifications', [SettingsController::class, 'getNotificationSettings']);
    Route::put('/settings/notifications', [SettingsController::class, 'updateNotificationSettings']);
    Route::get('/settings/privacy', [SettingsController::class, 'getPrivacySettings']);
    Route::put('/settings/privacy', [SettingsController::class, 'updatePrivacySettings']);
    Route::delete('/settings/account', [SettingsController::class, 'deleteAccount']);

    //AI Assistant
    Route::post('/ai/cv-review', [AIAssistantController::class, 'reviewCV']);
    Route::get('/ai/job-recommendations', [AIAssistantController::class, 'aiJobRecommendations']);
    Route::post('/ai/interview/questions', [AIAssistantController::class, 'generateInterviewQuestions']);
    Route::post('/ai/interview/submit', [AIAssistantController::class, 'submitInterviewAnswers']);

    //Company Dashboard
    Route::get('/company/dashboard', [CompanyDashboardController::class, 'index']);

    //Company Profile
    Route::get('/company/profile', [CompanyController::class, 'profile']);
    Route::put('/company/profile', [CompanyController::class, 'update']);
    Route::get('/company/jobs', [CompanyController::class, 'jobs']);

    //Company Job Posts
    Route::post('/company/jobs', [CompanyController::class, 'storeJob']);
    Route::post('/company/jobs/generate-description', [CompanyController::class, 'generateJobDescription']);


});


