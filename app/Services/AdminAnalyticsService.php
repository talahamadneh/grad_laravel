<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsService
{
    private const PENDING_APPLICATION_STATUSES = ['Applied', 'Under Review'];
    private const ACCEPTED_APPLICATION_STATUSES = ['Accepted'];
    private const PENDING_JOB_STATUSES = ['Pending Review', 'Changes Requested'];

    public function getAnalytics(string $period = 'month'): array
    {
        [$periodStart, $periodEnd] = $this->periodRange($period);

        $applicationStatusCounts = DB::table('applications')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'period' => [
                'name' => $period,
                'start' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString(),
            ],
            'students' => $this->studentsAnalytics($periodStart, $periodEnd),
            'companies' => $this->companiesAnalytics($periodStart, $periodEnd),
            'jobs' => $this->jobsAnalytics($periodStart, $periodEnd),
            'jobs_over_time' => $this->jobsOverTime(),
            'hiring' => $this->hiringAnalytics($applicationStatusCounts),
            'application_status_distribution' => $this->applicationStatusDistribution(),
            'trends' => [
                'monthly' => $this->monthlyTrends(),
            ],
        ];
    }

    private function studentsAnalytics(Carbon $periodStart, Carbon $periodEnd): array
    {
        return [
            'total_students' => DB::table('students')->count(),
            'active_students' => DB::table('students')
                ->join('users', 'users.id', '=', 'students.user_id')
                ->where('users.status', 'Active')
                ->count(),
            'new_students' => DB::table('students')
                ->whereBetween('created_at', [$periodStart, $periodEnd])
                ->count(),
            'top_majors' => DB::table('students')
                ->select('major', DB::raw('COUNT(*) as count'))
                ->whereNotNull('major')
                ->where('major', '<>', '')
                ->groupBy('major')
                ->orderByDesc('count')
                ->limit(5)
                ->get(),
            'graduation_year_distribution' => DB::table('students')
                ->select('graduation_year', DB::raw('COUNT(*) as count'))
                ->whereNotNull('graduation_year')
                ->groupBy('graduation_year')
                ->orderBy('graduation_year')
                ->get(),
        ];
    }

    private function companiesAnalytics(Carbon $periodStart, Carbon $periodEnd): array
    {
        return [
            'total_companies' => DB::table('companies')->count(),
            'active_companies' => DB::table('companies')
                ->join('users', 'users.id', '=', 'companies.user_id')
                ->where('companies.approval_status', 'Approved')
                ->where('users.status', 'Active')
                ->count(),
            'new_companies' => DB::table('companies')
                ->whereBetween('companies.created_at', [$periodStart, $periodEnd])
                ->count(),
            'most_active_companies' => DB::table('companies')
                ->leftJoin('job_posts', 'job_posts.company_id', '=', 'companies.id')
                ->leftJoin('applications', 'applications.job_post_id', '=', 'job_posts.id')
                ->select(
                    'companies.id',
                    'companies.company_name',
                    DB::raw('COUNT(DISTINCT job_posts.id) as job_posts_count'),
                    DB::raw('COUNT(applications.id) as applications_count')
                )
                ->groupBy('companies.id', 'companies.company_name')
                ->orderByDesc('job_posts_count')
                ->orderByDesc('applications_count')
                ->limit(5)
                ->get(),
            'top_industries' => DB::table('companies')
                ->select('industry', DB::raw('COUNT(*) as count'))
                ->whereNotNull('industry')
                ->where('industry', '<>', '')
                ->groupBy('industry')
                ->orderByDesc('count')
                ->limit(5)
                ->get(),
        ];
    }

    private function jobsAnalytics(Carbon $periodStart, Carbon $periodEnd): array
    {
        return [
            'total_jobs' => DB::table('job_posts')->count(),
            'open_jobs' => DB::table('job_posts')->where('status', 'Open')->count(),
            'closed_jobs' => DB::table('job_posts')->where('status', 'Closed')->count(),
            'pending_review_jobs' => DB::table('job_posts')
                ->whereIn('status', self::PENDING_JOB_STATUSES)
                ->count(),
            'jobs_created' => DB::table('job_posts')
                ->whereBetween('created_at', [$periodStart, $periodEnd])
                ->count(),
            'most_demanded_skills' => DB::table('skills')
                ->join('job_skills', 'job_skills.skill_id', '=', 'skills.id')
                ->select('skills.id', 'skills.name', DB::raw('COUNT(job_skills.job_post_id) as count'))
                ->groupBy('skills.id', 'skills.name')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
            'most_requested_majors' => DB::table('job_posts')
                ->select('required_major', DB::raw('COUNT(*) as count'))
                ->whereNotNull('required_major')
                ->where('required_major', '<>', '')
                ->groupBy('required_major')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
            'jobs_by_work_mode' => DB::table('job_posts')
                ->select('work_mode', DB::raw('COUNT(*) as count'))
                ->whereNotNull('work_mode')
                ->groupBy('work_mode')
                ->orderByDesc('count')
                ->get(),
            'jobs_by_employment_type' => DB::table('job_posts')
                ->select('employment_type', DB::raw('COUNT(*) as count'))
                ->whereNotNull('employment_type')
                ->groupBy('employment_type')
                ->orderByDesc('count')
                ->get(),
        ];
    }

    public function jobsOverTime(): array
    {
        $driver = DB::connection()->getDriverName();
        $monthExpression = $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        return DB::table('job_posts')
            ->whereNotNull('created_at')
            ->selectRaw("{$monthExpression} as period, COUNT(id) as count")
            ->groupByRaw($monthExpression)
            ->orderByRaw($monthExpression)
            ->get()
            ->map(function ($row) {
                $date = Carbon::createFromFormat('Y-m', $row->period);

                return [
                    'month' => $date->format('M'),
                    'year' => (int) $date->format('Y'),
                    'period' => $row->period,
                    'count' => (int) $row->count,
                ];
            })
            ->values()
            ->all();
    }

    private function hiringAnalytics(Collection $applicationStatusCounts): array
    {
        $totalApplications = (int) $applicationStatusCounts->sum();
        $acceptedApplications = $this->sumStatuses(
            $applicationStatusCounts,
            self::ACCEPTED_APPLICATION_STATUSES
        );

        return [
            'total_applications' => $totalApplications,
            'pending_applications' => $this->sumStatuses(
                $applicationStatusCounts,
                self::PENDING_APPLICATION_STATUSES
            ),
            'shortlisted_applications' => (int) ($applicationStatusCounts['Shortlisted'] ?? 0),
            'interview_applications' => (int) ($applicationStatusCounts['Interview'] ?? 0),
            'accepted_applications' => $acceptedApplications,
            'rejected_applications' => (int) ($applicationStatusCounts['Rejected'] ?? 0),
            'total_hires' => $acceptedApplications,
            'hire_rate' => $totalApplications > 0
                ? round(($acceptedApplications / $totalApplications) * 100, 2)
                : 0,
        ];
    }

    private function applicationStatusDistribution()
    {
        return DB::table('applications')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();
    }

    private function monthlyTrends(): array
    {
        $months = collect(range(11, 0))
            ->map(fn (int $monthsAgo) => now()->startOfMonth()->subMonths($monthsAgo));

        return $months->map(function (Carbon $month) {
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            return [
                'month' => $month->format('Y-m'),
                'students' => DB::table('students')->whereBetween('created_at', [$start, $end])->count(),
                'companies' => DB::table('companies')->whereBetween('created_at', [$start, $end])->count(),
                'jobs' => DB::table('job_posts')->whereBetween('created_at', [$start, $end])->count(),
                'applications' => DB::table('applications')->whereBetween('created_at', [$start, $end])->count(),
                'interviews' => DB::table('interviews')->whereBetween('created_at', [$start, $end])->count(),
                'hires' => DB::table('applications')
                    ->whereIn('status', self::ACCEPTED_APPLICATION_STATUSES)
                    ->whereBetween('updated_at', [$start, $end])
                    ->count(),
            ];
        })->values()->all();
    }

    private function periodRange(string $period): array
    {
        return match ($period) {
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    private function sumStatuses(Collection $statusCounts, array $statuses): int
    {
        return (int) collect($statuses)->sum(
            fn (string $status) => (int) ($statusCounts[$status] ?? 0)
        );
    }
}
