<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Company;
use App\Models\Interview;
use App\Models\JobPost;
use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use App\Services\SupabaseNotificationService;

class NotificationService
{
    public const APPLICATION_UPDATES = 'application_updates';
    public const INTERVIEW_NOTIFICATIONS = 'interview_notifications';
    public const JOB_RECOMMENDATIONS = 'job_recommendations';
    public const MESSAGES = 'messages';
    public const COMPANY_APPLICATIONS = 'company_applications';
    public const COMPANY_MESSAGES = 'company_messages';
    public const COMPANY_MATCHES = 'company_matches';
    public const COMPANY_DEADLINES = 'company_deadlines';
    public const COMPANY_INTERVIEWS = 'company_interviews';
    public const WEEKLY_APPLICATION_SUMMARY = 'weekly_application_summary';

    public static function studentRegistered(User $user)
    {
        return self::sendWithEmail(
            $user->id,
            'Welcome to Smart Career Platform',
            'Your student account has been created successfully. You can now complete your profile and start applying for opportunities.',
            'Welcome to Smart Career Platform',
            null,
            self::structuredEmail(
                'Welcome to Smart Career Platform',
                [
                    'Name' => $user->name,
                    'Email' => $user->email,
                    'Account Type' => 'Student',
                ],
                'Your student account has been created successfully. You can now complete your profile and start applying for opportunities.'
            )
        );
    }

    public static function applicationSubmitted(Application $application)
    {
        $application->loadMissing(['student', 'jobPost']);

        return self::sendWithEmail(
            $application->student->user_id,
            'Application Submitted Successfully',
            "Your application for {$application->jobPost->title} has been submitted successfully.",
            'Application Submitted Successfully',
            self::APPLICATION_UPDATES
        );
    }

    public static function newApplicationForCompany(Application $application)
    {
        $application->loadMissing(['student.user', 'jobPost.company.user']);

        $companyUserId = $application->jobPost->company->user_id ?? null;

        if (!$companyUserId) {
            return null;
        }

        $studentName = $application->student->user->name ?? 'A candidate';
        $jobTitle = $application->jobPost->title;

        return self::sendWithEmail(
            $companyUserId,
            'New Job Application',
            "{$studentName} applied for {$jobTitle}.",
            'New Job Application',
            self::COMPANY_APPLICATIONS
        );
    }

    public static function matchingCandidateForCompany(Application $application, int $threshold = 75)
    {
        $application->loadMissing(['student.user', 'jobPost.company.user']);

        if ((int) $application->match_score < $threshold) {
            return null;
        }

        $companyUserId = $application->jobPost->company->user_id ?? null;

        if (!$companyUserId) {
            return null;
        }

        $studentName = $application->student->user->name ?? 'A candidate';
        $jobTitle = $application->jobPost->title;
        $score = (int) $application->match_score;

        return self::sendOnceToday(
            $companyUserId,
            'Matching Candidate',
            "{$studentName} is a {$score}% match for {$jobTitle}.",
            self::COMPANY_MATCHES
        );
    }

    public static function pendingCompanyReply(Application $application, int $daysWaiting = 3)
    {
        $application->loadMissing(['student.user', 'jobPost.company.user']);

        $companyUserId = $application->jobPost->company->user_id ?? null;

        if (!$companyUserId) {
            return null;
        }

        $studentName = $application->student->user->name ?? 'A candidate';
        $jobTitle = $application->jobPost->title;

        return self::sendOnceToday(
            $companyUserId,
            'Application Waiting For Reply',
            "{$studentName}'s application for {$jobTitle} has been waiting for {$daysWaiting}+ days.",
            self::COMPANY_APPLICATIONS
        );
    }

    public static function applicationStatusChanged(Application $application, string $status)
    {
        $application->loadMissing(['student.user', 'jobPost.company']);

        return match ($status) {
            'Shortlisted' => self::shortlisted($application),
            'Offer' => self::offer($application),
            'Accepted', 'Hired' => self::accepted($application),
            'Rejected' => self::rejected($application),
            default => null,
        };
    }

    public static function shortlisted(Application $application)
    {
        $companyName = self::companyName($application);
        $jobTitle = $application->jobPost->title;

        return self::sendWithEmail(
            $application->student->user_id,
            'Congratulations!',
            "You have been shortlisted for {$jobTitle}.",
            'Congratulations! You have been Shortlisted',
            self::APPLICATION_UPDATES,
            self::structuredEmail(
                'Congratulations!',
                [
                    'Company Name' => $companyName,
                    'Job Title' => $jobTitle,
                ],
                "Congratulations! You have been shortlisted for {$jobTitle}. The company may contact you soon to schedule an interview."
            )
        );
    }

    public static function offer(Application $application)
    {
        $companyName = self::companyName($application);
        $jobTitle = $application->jobPost->title;
        $message = "Congratulations!\n\nYou have received a Job Offer for {$jobTitle}.";

        return self::sendWithEmail(
            $application->student->user_id,
            'Job Offer',
            $message,
            'Job Offer',
            self::APPLICATION_UPDATES,
            self::structuredEmail('Job Offer', [
                'Company Name' => $companyName,
                'Job Title' => $jobTitle,
            ], 'Congratulations! You have received a Job Offer. Please check your dashboard for more details.')
        );
    }

    public static function accepted(Application $application)
    {
        $application->loadMissing([
            'student.user',
            'jobPost.company',
            'interview'
        ]);

        $companyName = self::companyName($application);
        $jobTitle = $application->jobPost->title;

        $interview = $application->interview;

        return self::sendWithEmail(
            $application->student->user_id,
            'Congratulations!',
            "Congratulations! You have been accepted for {$jobTitle}.",
            'Congratulations!',
            self::APPLICATION_UPDATES,
            self::structuredEmail(
                'Congratulations!',
                [
                    'Company Name' => $companyName,
                    'Job Title' => $jobTitle,

                    'Interview Date' => $interview
                        ? $interview->interview_date->format('Y-m-d')
                        : 'N/A',

                    'Interview Time' => $interview
                        ? $interview->interview_date->format('h:i A')
                        : 'N/A',

                    'Interview Type' => $interview->type ?? 'N/A',

                    'Meeting Link' => $interview->meeting_link ?? 'N/A',

                    'Location' => $interview->location ?? 'N/A',
                ],
                "Congratulations! You have been accepted for {$jobTitle}."
            )
        );
    }

    public static function rejected(Application $application)
    {
        $companyName = self::companyName($application);
        $jobTitle = $application->jobPost->title;

        return self::sendWithEmail(
            $application->student->user_id,
            'Application Unsuccessful',
            "Unfortunately, your application for {$jobTitle} was not selected.",
            'Application Update',
            self::APPLICATION_UPDATES,
            self::structuredEmail(
                'Application Update',
                [
                    'Company Name' => $companyName,
                    'Job Title' => $jobTitle,
                ],
                "Thank you for your interest in {$jobTitle}. Unfortunately, your application was not selected this time. We encourage you to apply for future opportunities."
            )
        );
    }

    public static function interviewScheduled(Interview $interview)
    {
        $interview->loadMissing(['application.student', 'application.jobPost.company']);
        $jobTitle = $interview->application->jobPost->title;

        return self::sendWithEmail(
            $interview->application->student->user_id,
            'Interview Invitation',
            "Your interview for {$jobTitle} has been scheduled.",
            'Interview Invitation',
            self::INTERVIEW_NOTIFICATIONS,
            self::interviewEmail('Interview Invitation', $interview, 'Your interview has been scheduled.')
        );
    }

    public static function interviewRescheduled(Interview $interview)
    {
        $interview->loadMissing(['application.student', 'application.jobPost.company']);

        return self::sendWithEmail(
            $interview->application->student->user_id,
            'Interview Rescheduled',
            "Your interview for {$interview->application->jobPost->title} has been rescheduled.",
            'Interview Rescheduled',
            self::INTERVIEW_NOTIFICATIONS,
            self::interviewEmail('Interview Rescheduled', $interview, 'Your interview has been rescheduled.', true)
        );
    }

    public static function interviewCancelled(Interview $interview)
    {
        $interview->loadMissing(['application.student', 'application.jobPost.company']);
        $message = "Unfortunately, your interview has been cancelled.\n\nThe company may contact you again if another interview is scheduled.";

        return self::sendWithEmail(
            $interview->application->student->user_id,
            'Interview Cancelled',
            $message,
            'Interview Cancelled',
            self::INTERVIEW_NOTIFICATIONS,
            self::interviewEmail('Interview Cancelled', $interview, 'Unfortunately, your interview has been cancelled. The company may contact you again if another interview is scheduled.')
        );
    }

    public static function interviewReminder(Interview $interview)
    {
        $interview->loadMissing(['application.student', 'application.jobPost.company']);
        $dateTime = self::dateTime($interview);
        $jobTitle = $interview->application->jobPost->title;
        $message = "Reminder: Your interview for {$jobTitle} is scheduled on {$dateTime['date']} at {$dateTime['time']}.";

        return self::sendOnceTodayWithEmail(
            $interview->application->student->user_id,
            'Interview Reminder',
            $message,
            'Interview Reminder',
            self::INTERVIEW_NOTIFICATIONS,
            self::interviewEmail('Interview Reminder', $interview, $message)
        );
    }

    public static function companyInterviewReminder(Interview $interview)
    {
        $interview->loadMissing(['application.student.user', 'application.jobPost.company.user']);

        $companyUserId = $interview->application->jobPost->company->user_id ?? null;

        if (!$companyUserId) {
            return null;
        }

        $dateTime = self::dateTime($interview);
        $studentName = $interview->application->student->user->name ?? 'candidate';
        $jobTitle = $interview->application->jobPost->title;
        $message = "Reminder: {$studentName}'s interview for {$jobTitle} is scheduled on {$dateTime['date']} at {$dateTime['time']}.";

        return self::sendOnceTodayWithEmail(
            $companyUserId,
            'Interview Reminder',
            $message,
            'Interview Reminder',
            self::COMPANY_INTERVIEWS,
            self::interviewEmail('Interview Reminder', $interview, $message)
        );
    }

    public static function jobDeadlineReminder(JobPost $job, int $userId, bool $isToday)
    {
        $title = $isToday ? 'Job Deadline Today' : 'Job Deadline Tomorrow';

        $message = $isToday
            ? "Today is the last day to apply for {$job->title}."
            : "The application deadline for {$job->title} is tomorrow.";

        return self::sendOnceTodayWithEmail(
            $userId,
            $title,
            $message,
            $title,
            self::JOB_RECOMMENDATIONS,
            self::structuredEmail(
                $title,
                [
                    'Job Title' => $job->title,
                    'Company Name' => $job->company->company_name,
                    'Deadline' => $job->deadline->format('Y-m-d'),
                ],
                $message
            )
        );
    }

    public static function companyJobDeadlineReminder(JobPost $job, bool $isToday)
    {
        $job->loadMissing('company.user');

        $companyUserId = $job->company->user_id ?? null;

        if (!$companyUserId) {
            return null;
        }

        $title = $isToday ? 'Job Post Ends Today' : 'Job Post Ends Soon';

        $message = $isToday
            ? "{$job->title} reaches its deadline today."
            : "{$job->title} reaches its deadline on {$job->deadline->format('Y-m-d')}.";

        return self::sendOnceTodayWithEmail(
            $companyUserId,
            $title,
            $message,
            $title,
            self::COMPANY_DEADLINES,
            self::structuredEmail(
                $title,
                [
                    'Job Title' => $job->title,
                    'Deadline' => $job->deadline->format('Y-m-d'),
                    'Status' => $job->status,
                ],
                $message
            )
        );
    }

    public static function newMessageFromCompany(int $studentUserId, string $companyName)
    {
        return self::sendWithEmail(
            $studentUserId,
            'New Message',
            "You have received a new message from {$companyName}.",
            'New Message',
            self::MESSAGES
        );
    }

    public static function newMessageForCompany(int $companyUserId, string $studentName)
    {
        return self::send(
            $companyUserId,
            'New Message',
            "You have received a new message from {$studentName}.",
            self::COMPANY_MESSAGES
        );
    }

    public static function companyApplicationSummary(Company $company, string $period, $from, $to): void
    {
        $company->loadMissing('user');

        if (!$company->user?->email) {
            return;
        }

        if (!self::shouldSend($company->user_id, self::WEEKLY_APPLICATION_SUMMARY)) {
            return;
        }

        $applications = Application::with(['student.user', 'jobPost'])
            ->whereHas('jobPost', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->whereBetween('applied_at', [$from, $to])
            ->latest('applied_at')
            ->get();

        if ($applications->isEmpty()) {
            return;
        }

        $subject = ucfirst($period) . ' Application Summary';
        $rows = $applications
            ->groupBy('job_post_id')
            ->map(function ($items) {
                $job = $items->first()->jobPost;

                return [
                    'Job Title' => $job->title ?? 'Job',
                    'New Applications' => $items->count(),
                    'Top Match' => (int) $items->max('match_score') . '%',
                ];
            })
            ->values();

        $details = [
            'Company' => $company->company_name,
            'Period' => ucfirst($period),
            'New Applications' => $applications->count(),
            'From' => $from->format('Y-m-d H:i'),
            'To' => $to->format('Y-m-d H:i'),
        ];

        $jobRows = $rows
            ->map(fn ($item) => '<tr><td style="padding:8px 12px;border:1px solid #e5e7eb;">' . e($item['Job Title']) . '</td><td style="padding:8px 12px;border:1px solid #e5e7eb;">' . e((string) $item['New Applications']) . '</td><td style="padding:8px 12px;border:1px solid #e5e7eb;">' . e($item['Top Match']) . '</td></tr>')
            ->implode('');

        $html = self::structuredEmail($subject, $details, "Here is your {$period} summary of new applications.")
            . '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#111827;margin-top:16px;">'
            . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:640px;">'
            . '<tr><th style="text-align:left;padding:8px 12px;background:#f6f8fa;border:1px solid #e5e7eb;">Job</th><th style="text-align:left;padding:8px 12px;background:#f6f8fa;border:1px solid #e5e7eb;">Applications</th><th style="text-align:left;padding:8px 12px;background:#f6f8fa;border:1px solid #e5e7eb;">Top Match</th></tr>'
            . $jobRows
            . '</table></div>';

        self::email($company->user_id, $subject, "{$applications->count()} new applications received.", $html);
    }

    public static function companyWaitingReviewNudge(Company $company, int $daysWaiting = 7): void
    {
        $company->loadMissing('user');

        if (!$company->user?->email) {
            return;
        }

        if (!self::shouldSend($company->user_id, self::COMPANY_APPLICATIONS)) {
            return;
        }

        $waitingCount = Application::whereHas('jobPost', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->whereIn('status', ['Applied', 'Screening', 'Under Review'])
            ->where('applied_at', '<=', now()->subDays($daysWaiting))
            ->count();

        if ($waitingCount === 0) {
            return;
        }

        $shortlistedCount = Application::whereHas('jobPost', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->where('status', 'Shortlisted')
            ->count();

        $interviewCount = Application::whereHas('jobPost', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->where('status', 'Interview')
            ->count();

        $subject = 'Applicants Waiting For Review';
        $message = "You have {$waitingCount} applicants waiting for review.";

        $html = self::structuredEmail(
            $subject,
            [
                'Applicants Waiting' => $waitingCount,
                'Shortlisted' => $shortlistedCount,
                'Interview' => $interviewCount,
                'Waiting Since' => $daysWaiting . '+ days',
            ],
            $message
        );

        self::email($company->user_id, $subject, $message, $html);
    }

    public static function calculateApplicationMatchScore(Application $application): int
    {
        $application->loadMissing(['student.skills', 'jobPost.skills']);

        return self::calculateJobStudentMatchScore($application->jobPost, $application->student);
    }

    public static function calculateJobStudentMatchScore(JobPost $job, Student $student): int
    {
        $studentSkills = $student->skills->pluck('id')->toArray();
        $jobSkills = $job->skills->pluck('id')->toArray();
        $score = 0;

        if (count($jobSkills) > 0) {
            $score += (count(array_intersect($studentSkills, $jobSkills)) / count($jobSkills)) * 80;
        }

        if ($student->location && $job->location && strtolower(trim($student->location)) === strtolower(trim($job->location))) {
            $score += 10;
        }

        if ($student->preferred_employment_type && $job->employment_type && strtolower($student->preferred_employment_type) === strtolower($job->employment_type)) {
            $score += 5;
        }

        if ($student->major && $job->required_major && strtolower($student->major) === strtolower($job->required_major)) {
            $score += 5;
        }

        return (int) round(min($score, 100));
    }

    public static function send($userId, $title, $message, ?string $category = null)
    {
        if (!self::shouldSend($userId, $category)) {
            return null;
        }

        $notification = Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'is_read' => false,
        ]);

        SupabaseNotificationService::send($notification);

        return $notification;
    }

    public static function sendOnceToday($userId, $title, $message, ?string $category = null)
    {
        if (!self::shouldSend($userId, $category)) {
            return null;
        }

        $exists = Notification::where('user_id', $userId)
            ->where('title', $title)
            ->where('message', $message)
            ->whereDate('created_at', today())
            ->exists();

        if ($exists) {
            return null;
        }

        return self::send($userId, $title, $message, $category);
    }

    public static function sendOnceTodayWithEmail($userId, $title, $message, $subject = null, ?string $category = null, ?string $html = null)
    {
        if (!self::shouldSend($userId, $category)) {
            return null;
        }

        $exists = Notification::where('user_id', $userId)
            ->where('title', $title)
            ->where('message', $message)
            ->whereDate('created_at', today())
            ->exists();

        if ($exists) {
            return null;
        }

        $notification = self::send($userId, $title, $message, $category);
        self::email($userId, $subject ?? $title, $message, $html);

        return $notification;
    }

    public static function sendWithEmail($userId, $title, $message, $subject = null, ?string $category = null, ?string $html = null)
    {
        if (!self::shouldSend($userId, $category)) {
            return null;
        }

        $notification = self::send($userId, $title, $message, $category);

        self::email($userId, $subject ?? $title, $message, $html);

        return $notification;
    }

    public static function email($userId, $subject, $message, ?string $html = null): void
    {
        $user = User::find($userId);

        if (!$user || !$user->email) {
            Log::warning('NotificationService::email skipped, no user or email', [
                'user_id' => $userId,
            ]);
            return;
        }

        try {
            if ($html) {
                Mail::html($html, function ($mail) use ($user, $subject) {
                    $mail->to($user->email)->subject($subject);
                });
            } else {
                Mail::raw($message, function ($mail) use ($user, $subject) {
                    $mail->to($user->email)->subject($subject);
                });
            }

            Log::info('NotificationService::email sent', [
                'user_id' => $userId,
                'email' => $user->email,
                'subject' => $subject,
            ]);
        } catch (Throwable $exception) {
            Log::error('NotificationService::email failed', [
                'user_id' => $userId,
                'email' => $user->email,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private static function shouldSend($userId, ?string $category): bool
    {
        if (!$category) {
            return true;
        }

        $allowedCategories = [
            self::APPLICATION_UPDATES,
            self::INTERVIEW_NOTIFICATIONS,
            self::JOB_RECOMMENDATIONS,
            self::MESSAGES,
            self::COMPANY_APPLICATIONS,
            self::COMPANY_MESSAGES,
            self::COMPANY_MATCHES,
            self::COMPANY_DEADLINES,
            self::COMPANY_INTERVIEWS,
            self::WEEKLY_APPLICATION_SUMMARY,
        ];

        if (!in_array($category, $allowedCategories, true)) {
            return true;
        }

        $settings = NotificationSetting::firstOrCreate(
            ['user_id' => $userId],
            [
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
            ]
        );

        if (!isset($settings->{$category})) {
            return true;
        }

        Log::info('Notification setting check', [
            'user_id' => $userId,
            'category' => $category,
            'value' => $settings->{$category},
            'settings' => $settings->toArray(),
        ]);

        return (bool) $settings->{$category};
    }

    private static function interviewEmail(string $title, Interview $interview, string $intro, bool $rescheduled = false): string
    {
        $dateTime = self::dateTime($interview);

        return self::structuredEmail($title, [
            'Company Name' => self::companyName($interview->application),
            'Job Title' => $interview->application->jobPost->title,
            $rescheduled ? 'New Date' : 'Date' => $dateTime['date'],
            $rescheduled ? 'New Time' : 'Time' => $dateTime['time'],
            'Type' => $interview->type,
            'Meeting Link' => $interview->meeting_link ?: 'N/A',
            'Location' => $interview->location ?: 'N/A',
        ], $intro);
    }

    private static function structuredEmail(string $title, array $details, string $intro): string
    {
        $rows = collect($details)
            ->map(function ($value, $label) {
                return '<tr><th style="text-align:left;padding:8px 12px;background:#f6f8fa;border:1px solid #e5e7eb;">' . e($label) . '</th><td style="padding:8px 12px;border:1px solid #e5e7eb;">' . e((string) $value) . '</td></tr>';
            })
            ->implode('');

        return '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#111827;">'
            . '<h2 style="margin:0 0 12px;">' . e($title) . '</h2>'
            . '<p style="margin:0 0 16px;">' . e($intro) . '</p>'
            . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:640px;">'
            . $rows
            . '</table>'
            . '</div>';
    }

    private static function dateTime(Interview $interview): array
    {
        $date = $interview->interview_date instanceof CarbonInterface
            ? $interview->interview_date
            : \Carbon\Carbon::parse($interview->interview_date);

        return [
            'date' => $date->format('Y-m-d'),
            'time' => $date->format('h:i A'),
        ];
    }

    private static function companyName(Application $application): string
    {
        return $application->jobPost->company->company_name ?? 'Company';
    }
}