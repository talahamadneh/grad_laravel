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
use App\Http\Controllers\CompanyNoteController;
use App\Http\Controllers\ApplicationStatusController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\InterviewFeedbackController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminCompanyController;

Route::get('/landing/stats', [LandingController::class, 'stats']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    //Dashboard
    Route::get('/student/dashboard', [StudentDashboardController::class, 'index']);
    //  Route::get('/student/recommended-jobs', [StudentJobController::class, 'recommended']);

    // Student Profile
    Route::get('/student/profile', [StudentController::class, 'profile']);
    Route::put('/student/profile', [StudentController::class, 'update']);

    // Resume Routes
    Route::get('/student/resume', [ResumeController::class, 'index']);
    Route::post('/student/resume', [ResumeController::class, 'store']);
    Route::put('/student/resume/{id}', [ResumeController::class, 'update']);
    Route::delete('/student/resume/{id}', [ResumeController::class, 'destroy']);
    Route::post('/student/resume/ai-improve', [ResumeController::class, 'aiImprove']);
    Route::get('/student/resume/{id}/pdf', [ResumeController::class, 'generatePdf']);

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
    Route::get('/messages/search-recipient', [MessageController::class, 'searchRecipient']);
    Route::get('/messages/{user}', [MessageController::class, 'show']);
    Route::post('/messages', [MessageController::class, 'store']);

    Route::post('/messages/{user}/close', [MessageController::class, 'closeConversation']);
    Route::post('/messages/{user}/reopen', [MessageController::class, 'reopenConversation']);
    Route::delete('/messages/{user}', [MessageController::class, 'destroyConversation']);
    Route::post('/messages/{user}/block', [MessageController::class, 'blockUser']);
    Route::post('/messages/{user}/report', [MessageController::class, 'reportUser']);



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
    Route::post('/company/profile', [CompanyController::class, 'update']);
    Route::get('/company/jobs', [CompanyController::class, 'jobs']);

    //Company Job Posts
    Route::post('/company/jobs', [CompanyController::class, 'storeJob']);
    Route::post('/company/jobs/generate-description', [CompanyController::class, 'generateJobDescription']);

    //Company Applicants
    Route::get('/company/applicants', [CompanyController::class, 'applicants']);
    //Applicants Details
    Route::get('/company/applicants/{id}', [CompanyController::class, 'applicantDetails']);
    //Company Notes
    Route::get('/company/applicants/{applicationId}/notes', [CompanyNoteController::class, 'index']);
    Route::post('/company/applicants/{applicationId}/notes', [CompanyNoteController::class, 'store']);
    Route::put('/company/notes/{id}', [CompanyNoteController::class, 'update']);
    Route::delete('/company/notes/{id}', [CompanyNoteController::class, 'destroy']);
    Route::post('/student/resume/upload', [ResumeController::class, 'uploadFile']);

    //Application Status
    Route::put('/company/applicants/{applicationId}/status', [ApplicationStatusController::class, 'update']);
    Route::get('/company/applicants/{applicationId}/timeline', [ApplicationStatusController::class, 'timeline']);
    //Ai Summary
    Route::get('/company/applicants/{id}/ai-summary', [CompanyController::class, 'aiCandidateSummary']);
    // Complete Applicant Details
    Route::get('/company/applicants/{id}/details', [CompanyController::class, 'fullApplicantDetails']);


    //job details
    Route::get('/company/jobs/{id}', [CompanyController::class, 'jobDetails']);

    //edit job
    Route::get('/company/jobs/{id}/edit', [CompanyController::class, 'editJob']);
    Route::put('/company/jobs/{id}', [CompanyController::class, 'updateJob']);

    //delete job
    Route::delete('/company/jobs/{id}', [CompanyController::class, 'destroyJob']);


    //shortlist applicant
    Route::patch('/company/applications/{application}/shortlist', [CompanyController::class, 'shortlist']);
    Route::get('/company/jobs/{job}/shortlisted', [CompanyController::class, 'getShortlisted']);

    //interview routes in applicant details
    Route::post('/company/interviews', [CompanyController::class, 'scheduleInterview']);

    //interview routes
    Route::get('/company/interviews', [InterviewController::class, 'index']);
    Route::get('/company/interviews/stats', [InterviewController::class, 'stats']);
    Route::get('/company/interviews/calendar', [InterviewController::class, 'calendar']);

    Route::get('/company/interviews/{interview}', [InterviewController::class, 'show']);
    Route::put('/company/interviews/{interview}', [InterviewController::class, 'update']);
    Route::patch('/company/interviews/{interview}/cancel', [InterviewController::class, 'cancel']);
    Route::patch('/company/interviews/{interview}/complete', [InterviewController::class, 'complete']);

    //bulk schedule interviews
    Route::post('/company/interviews/bulk-schedule', [CompanyController::class, 'bulkSchedule']);

    //interview feedback routes
    Route::post('/company/interviews/{interview}/feedback', [InterviewFeedbackController::class, 'store']);
    Route::get('/company/interviews/{interview}/feedback', [InterviewFeedbackController::class, 'show']);
    Route::put('/company/interviews/{interview}/feedback', [InterviewFeedbackController::class, 'update']);
    Route::delete('/company/interviews/{interview}/feedback', [InterviewFeedbackController::class, 'destroy']);

    //Reports
    Route::get('/company/reports/overview', [ReportController::class, 'overview']);
    Route::get('/company/reports/jobs', [ReportController::class, 'jobs']);
    Route::get('/company/reports/pipeline', [ReportController::class, 'pipeline']);
    Route::get('/company/reports/monthly-applications', [ReportController::class, 'monthlyApplications']);


    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);


    // Admin dashboard route
    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index']);

    // Admin company routes
    Route::get('/admin/companies/pending', [AdminCompanyController::class, 'pending']);
    Route::get('/admin/companies/{id}', [AdminCompanyController::class, 'show']);
    Route::patch('/admin/companies/{id}/approve', [AdminCompanyController::class, 'approve']);
    Route::patch('/admin/companies/{id}/reject', [AdminCompanyController::class, 'reject']);
    Route::patch('/admin/companies/{id}/suspend', [AdminCompanyController::class, 'suspend']);
});

