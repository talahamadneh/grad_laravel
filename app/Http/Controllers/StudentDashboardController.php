<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\Application;
use App\Models\SavedJob;
use App\Models\ProfileView;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class StudentDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $student = Student::where('user_id', $user->id)->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student not found'
            ], 404);
        }

        $applicationsQuery = Application::where('student_id', $student->id);
        $profileViewsQuery = ProfileView::where('user_id', $user->id);

        $applicationDates = (clone $applicationsQuery)
            ->whereNotNull('applied_at')
            ->orderBy('applied_at')
            ->pluck('applied_at');

        $applicationActivity = $this->applicationActivity($applicationDates);
        $periods = $this->comparisonPeriods();

        return response()->json([
            'stats' => [
                'applications' => (clone $applicationsQuery)->count(),
                'interviews' => (clone $applicationsQuery)->where('status', 'Interview')->count(),
                'saved' => SavedJob::where('student_id', $student->id)->count(),
                'views' => (clone $profileViewsQuery)->count(),
            ],
            'application_activity' => $applicationActivity,
            'trends' => [
                'applications' => $this->trend(
                    (clone $applicationsQuery)->whereBetween('applied_at', $periods['current'])->count(),
                    (clone $applicationsQuery)->whereBetween('applied_at', $periods['previous'])->count(),
                ),
                'interviews' => $this->trend(
                    (clone $applicationsQuery)->where('status', 'Interview')->whereBetween('updated_at', $periods['current'])->count(),
                    (clone $applicationsQuery)->where('status', 'Interview')->whereBetween('updated_at', $periods['previous'])->count(),
                ),
                'profile_views' => $this->trend(
                    (clone $profileViewsQuery)->whereBetween('created_at', $periods['current'])->count(),
                    (clone $profileViewsQuery)->whereBetween('created_at', $periods['previous'])->count(),
                ),
            ],
        ]);
    }

    private function applicationActivity(Collection $dates): array
    {
        return $dates
            ->map(fn ($date) => CarbonImmutable::parse($date))
            ->groupBy(fn (CarbonImmutable $date) => $date->format('Y-m'))
            ->sortKeys()
            ->map(fn (Collection $monthDates) => [
                'month' => $monthDates->first()->format('M'),
                'applications' => $monthDates->count(),
            ])
            ->values()
            ->all();
    }

    private function comparisonPeriods(): array
    {
        $currentStart = CarbonImmutable::now()->startOfMonth();

        return [
            'current' => [$currentStart, $currentStart->endOfMonth()],
            'previous' => [$currentStart->subMonth()->startOfMonth(), $currentStart->subMonth()->endOfMonth()],
        ];
    }

    private function trend(int $current, int $previous): int
    {
        if ($previous === 0) {
            return $current === 0 ? 0 : 100;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }
}
