<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\JobPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function overview()
    {
        $companyId = auth()->user()->company->id;

        $totalJobs = JobPost::where('company_id', $companyId)->count();

        $applications = Application::whereHas('jobPost', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })->count();

        $interviews = Interview::whereHas('application.jobPost', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })->count();

        $hiredCandidates = InterviewFeedback::where('final_decision', 'Accepted')
            ->whereHas('interview.application.jobPost', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })
            ->count();

        return response()->json([
            'total_jobs' => $totalJobs,
            'applications' => $applications,
            'interviews' => $interviews,
            'hired_candidates' => $hiredCandidates,
        ]);
    }

    public function jobs()
    {
        $companyId = auth()->user()->company->id;

        $jobs = JobPost::where('company_id', $companyId)
            ->withCount('applications')
            ->get()
            ->map(function ($job) {
                return [
                    'job_title' => $job->title,
                    'applications' => $job->applications_count,
                ];
            });

        return response()->json($jobs);
    }

    public function pipeline()
    {
        $companyId = auth()->user()->company->id;

        $statuses = Application::whereHas('jobPost', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $accepted = InterviewFeedback::where('final_decision', 'Accepted')
            ->whereHas('interview.application.jobPost', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })
            ->count();

        return response()->json([
            'Applied' => $statuses['Applied'] ?? 0,
            'Screening' => $statuses['Screening'] ?? 0,
            'Shortlisted' => $statuses['Shortlisted'] ?? 0,
            'Interview' => $statuses['Interview'] ?? 0,
            'Offer' => $statuses['Offer'] ?? 0,
            'Accepted' => $accepted,
            'Rejected' => $statuses['Rejected'] ?? 0,
        ]);
    }

    public function monthlyApplications()
    {
        $companyId = auth()->user()->company->id;

        $months = Application::whereHas('jobPost', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
            ->selectRaw('MONTH(created_at) as month, COUNT(*) as applications')
            ->groupByRaw('MONTH(created_at)')
            ->orderByRaw('MONTH(created_at)')
            ->get();

        $monthNames = [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'May',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Aug',
            9 => 'Sep',
            10 => 'Oct',
            11 => 'Nov',
            12 => 'Dec',
        ];

        $data = [];

        foreach ($months as $month) {
            $data[] = [
                'month' => $monthNames[$month->month],
                'applications' => $month->applications,
            ];
        }

        return response()->json($data);
    }
}