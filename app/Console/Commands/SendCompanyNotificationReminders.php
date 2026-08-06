<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Company;
use App\Models\Interview;
use App\Models\JobPost;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendCompanyNotificationReminders extends Command
{
    protected $signature = 'notifications:send-company-reminders {--summary=daily : Summary period: daily or weekly}';

    protected $description = 'Send company platform notifications and application summary emails.';

    public function handle(): int
    {
        $this->sendInterviewReminders(today()->copy()->addDay());
        $this->sendInterviewReminders(today());

        $this->sendDeadlineReminders(today()->copy()->addDays(3), false);
        $this->sendDeadlineReminders(today(), true);

        $this->sendPendingReplyReminders();
        $this->sendApplicationSummaries((string) $this->option('summary'));
        $this->sendWaitingReviewNudges((string) $this->option('summary'));

        $this->info('Company notification reminders sent.');

        return self::SUCCESS;
    }

    private function sendInterviewReminders($date): void
    {
        Interview::with(['application.student.user', 'application.jobPost.company.user'])
            ->where('status', 'Scheduled')
            ->whereDate('interview_date', $date)
            ->get()
            ->each(function (Interview $interview) {
                NotificationService::companyInterviewReminder($interview);
            });
    }

    private function sendDeadlineReminders($date, bool $isToday): void
    {
        JobPost::with('company.user')
            ->where('status', 'Open')
            ->whereDate('deadline', $date)
            ->get()
            ->each(function (JobPost $job) use ($isToday) {
                NotificationService::companyJobDeadlineReminder($job, $isToday);
            });
    }

    private function sendPendingReplyReminders(): void
    {
        Application::with(['student.user', 'jobPost.company.user'])
            ->whereIn('status', ['Applied', 'Screening', 'Under Review'])
            ->where('applied_at', '<=', now()->subDays(3))
            ->get()
            ->each(function (Application $application) {
                NotificationService::pendingCompanyReply($application);
            });
    }

    private function sendApplicationSummaries(string $period): void
    {
        $period = $period === 'weekly' ? 'weekly' : 'daily';
        $from = $period === 'weekly'
            ? now()->subWeek()
            : now()->subDay();
        $to = now();

        Company::with('user')
            ->get()
            ->each(function (Company $company) use ($period, $from, $to) {
                NotificationService::companyApplicationSummary($company, $period, $from, $to);
            });
    }

    private function sendWaitingReviewNudges(string $period): void
    {
        if ($period !== 'weekly') {
            return;
        }

        Company::with('user')
            ->get()
            ->each(function (Company $company) {
                NotificationService::companyWaitingReviewNudge($company);
            });
    }
}
