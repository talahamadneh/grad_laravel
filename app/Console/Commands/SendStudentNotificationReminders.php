<?php

namespace App\Console\Commands;

use App\Models\Interview;
use App\Models\JobPost;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendStudentNotificationReminders extends Command
{
    protected $signature = 'notifications:send-reminders';

    protected $description = 'Send student interview and job deadline reminders.';

    public function handle(): int
    {
        $this->sendInterviewReminders(today()->copy()->addDay());
        $this->sendInterviewReminders(today());

        $this->sendDeadlineReminders(today()->copy()->addDay(), false);
        $this->sendDeadlineReminders(today(), true);

        $this->info('Student notification reminders sent.');

        return self::SUCCESS;
    }

    private function sendInterviewReminders($date): void
    {
        Interview::with(['application.student', 'application.jobPost.company'])
            ->where('status', 'Scheduled')
            ->whereDate('interview_date', $date)
            ->get()
            ->each(function (Interview $interview) {
                NotificationService::interviewReminder($interview);
            });
    }

    private function sendDeadlineReminders($date, bool $isToday): void
    {
        JobPost::with(['savedByStudents.user', 'applications'])
            ->where('status', 'Open')
            ->whereDate('deadline', $date)
            ->get()
            ->each(function (JobPost $job) use ($isToday) {
                $appliedStudentIds = $job->applications->pluck('student_id');

                $job->savedByStudents
                    ->whereNotIn('id', $appliedStudentIds)
                    ->each(function ($student) use ($job, $isToday) {
                        NotificationService::jobDeadlineReminder($job, $student->user_id, $isToday);
                    });
            });
    }
}
