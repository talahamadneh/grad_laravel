<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\NotificationSetting;
use App\Models\PrivacySetting;
use App\Models\Company;

class SettingsController extends Controller
{
    private array $notificationDefaults = [
        'application_updates' => true,
        'interview_notifications' => true,
        'job_recommendations' => true,
        'messages' => true,
        'profile_views' => true,
        'resume_feedback' => true,
        'company_applications' => true,
        'company_messages' => true,
        'company_matches' => true,
        'company_deadlines' => true,
        'company_interviews' => true,
        'weekly_application_summary' => true,
        'job_deadline_reminders' => true,
    ];

    private array $privacyDefaults = [
        'profile_visibility' => true,
        'contact_visibility' => false,
        'ai_resume_analysis' => true,
        'ai_candidate_matching' => true,
    ];

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect'
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password)
        ]);

        return response()->json([
            'message' => 'Password updated successfully'
        ]);
    }

    public function getNotificationSettings(Request $request)
    {
        $user = $request->user();

        $settings = NotificationSetting::firstOrCreate(
            [
                'user_id' => $user->id
            ],
            $this->notificationDefaults
        );

        return response()->json($settings);
    }

    public function updateNotificationSettings(Request $request)
    {
        $request->validate([
            'application_updates' => 'boolean',
            'interview_notifications' => 'boolean',
            'job_recommendations' => 'boolean',
            'messages' => 'boolean',
            'profile_views' => 'boolean',
            'resume_feedback' => 'boolean',
            'company_applications' => 'boolean',
            'company_messages' => 'boolean',
            'company_matches' => 'boolean',
            'company_deadlines' => 'boolean',
            'company_interviews' => 'boolean',
            'weekly_application_summary' => 'boolean',
            'job_deadline_reminders' => 'boolean',
        ]);

        $settings = NotificationSetting::updateOrCreate(
            [
                'user_id' => $request->user()->id
            ],
            $request->all()
        );

        return response()->json([
            'message' => 'Notification settings updated successfully',
            'settings' => $settings
        ]);
    }

    public function getPrivacySettings(Request $request)
    {
        $user = $request->user();

        $settings = PrivacySetting::firstOrCreate(
            [
                'user_id' => $user->id
            ],
            $this->privacyDefaults
        );

        return response()->json($settings);
    }

    public function updatePrivacySettings(Request $request)
    {
        $request->validate([
            'profile_visibility' => 'boolean',
            'contact_visibility' => 'boolean',
            'ai_resume_analysis' => 'boolean',
            'ai_candidate_matching' => 'boolean'
        ]);

        $settings = PrivacySetting::updateOrCreate(
            [
                'user_id' => $request->user()->id
            ],
            $request->all()
        );

        return response()->json([
            'message' => 'Privacy settings updated successfully',
            'settings' => $settings
        ]);
    }

    public function deleteAccount(Request $request)
    {
        $request->validate([
            'password' => 'required'
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password incorrect'
            ], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Account deleted successfully'
        ]);
    }

    public function companySettings(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'Company') {
            return response()->json([
                'message' => 'Only company accounts can access company settings.'
            ], 403);
        }

        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $notifications = NotificationSetting::firstOrCreate(
            ['user_id' => $user->id],
            $this->notificationDefaults
        );

        $privacy = PrivacySetting::firstOrCreate(
            ['user_id' => $user->id],
            $this->privacyDefaults
        );

        return response()->json([
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'company' => [
                'id' => $company->id,
                'company_name' => $company->company_name,
                'industry' => $company->industry,
                'description' => $company->description,
                'website' => $company->website,
                'phone' => $company->phone,
                'location' => $company->location,
                'company_size' => $company->company_size,
                'stage' => $company->stage,
                'founded_year' => $company->founded_year,
                'logo' => $company->logo,
                'cover_image' => $company->cover_image,
                'values' => $company->values ?? [],
                'benefits' => $company->benefits ?? [],
                'is_verified' => (bool) $company->is_verified,
                'approval_status' => $company->approval_status,
            ],
            'notifications' => [
                'new_applications' => (bool) $notifications->company_applications,
                'messages' => (bool) $notifications->company_messages,
                'matching_candidates' => (bool) $notifications->company_matches,
                'job_deadlines' => (bool) $notifications->company_deadlines,
                'interview_reminders' => (bool) $notifications->company_interviews,
            ],
            'emails' => [
                'interview_reminders' => (bool) $notifications->company_interviews,
                'job_deadlines' => (bool) $notifications->job_deadline_reminders,
                'daily_weekly_application_summary' => (bool) $notifications->weekly_application_summary,
                'waiting_review_nudge' => (bool) $notifications->company_applications,
            ],
            'privacy' => [
                'profile_visibility' => (bool) $privacy->profile_visibility,
                'contact_visibility' => (bool) $privacy->contact_visibility,
                'ai_resume_analysis' => (bool) $privacy->ai_resume_analysis,
                'ai_candidate_matching' => (bool) $privacy->ai_candidate_matching,
            ],
        ]);
    }
}