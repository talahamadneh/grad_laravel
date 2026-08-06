<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Interview;
use App\Models\JobPost;
use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationService
{
    public const APPLICATION_UPDATES = 'application_updates';
    public const INTERVIEW_NOTIFICATIONS = 'interview_notifications';
    public const JOB_RECOMMENDATIONS = 'job_recommendations';
    public const MESSAGES = 'messages';

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

    public static function send($userId, $title, $message, ?string $category = null)
    {
        if (!self::shouldSend($userId, $category)) {
            return null;
        }

        return Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'is_read' => false,
        ]);
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
        ];

        if (!in_array($category, $allowedCategories, true)) {
            return true;
        }

        $settings = NotificationSetting::firstOrCreate(['user_id' => $userId]);

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