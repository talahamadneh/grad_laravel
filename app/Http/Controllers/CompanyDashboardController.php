<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\JobPost;
use App\Models\Application;
use Illuminate\Support\Facades\Auth;

class CompanyDashboardController extends Controller
{
    public function index(Request $request)
    {
        $company = Auth::user()->company;

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $jobIds = JobPost::where('company_id', $company->id)->pluck('id');

        $activeJobs = JobPost::where('company_id', $company->id)
            ->where('status', 'Open')
            ->count();

        $totalApplications = Application::whereIn('job_post_id', $jobIds)->count();

        $interviews = Application::whereIn('job_post_id', $jobIds)
            ->where('status', 'Interview')
            ->count();

        $hired = Application::whereIn('job_post_id', $jobIds)
            ->whereIn('status', ['Accepted', 'Hired'])
            ->count();

        $activity = Application::whereIn('job_post_id', $jobIds)
            ->selectRaw('DATE_FORMAT(applied_at, "%Y-%m") as month, COUNT(*) as value')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $pipelineRaw = Application::whereIn('job_post_id', $jobIds)
            ->selectRaw('status, COUNT(*) as value')
            ->groupBy('status')
            ->get();

        $pipelineColors = [
            'Applied' => '#94A3B8',
            'Under Review' => '#60A5FA',
            'Shortlisted' => '#FBBF24',
            'Interview' => '#A78BFA',
            'Accepted' => '#34D399',
            'Rejected' => '#F87171',
        ];

        $pipeline = $pipelineRaw->map(function ($p) use ($pipelineColors) {
            return [
                'name' => $p->status,
                'value' => $p->value,
                'color' => $pipelineColors[$p->status] ?? '#CBD5E1',
            ];
        });

        $recentApplications = Application::with(['student.user', 'jobPost'])
            ->whereIn('job_post_id', $jobIds)
            ->orderByDesc('applied_at')
            ->take(2)
            ->get();

        $recentApplicants = $recentApplications->map(function ($app) {
            $student = $app->student;
            $skills = $student->skills->pluck('name')->toArray();

            return [
                'id' => $app->id,
                'name' => $student->user->name ?? 'N/A',
                'avatar' => $student->avatar
                    ?? 'https://ui-avatars.com/api/?name=' . urlencode($student->user->name ?? 'U'),
                'title' => $student->headline ?? 'Student',
                'univ' => $student->university,
                'location' => $student->location,
                'match' => null, 
                'skills' => $skills,
                'status' => $app->status,
                'job_title' => $app->jobPost->title ?? null,
            ];
        });

        $activeJobsList = JobPost::withCount('applications')
            ->where('company_id', $company->id)
            ->where('status', 'Open')
            ->latest()
            ->take(3)
            ->get()
            ->map(function ($job) {
                return [
                    'id' => $job->id,
                    'title' => $job->title,
                    'applicants' => $job->applications_count,
                    'posted' => $job->created_at->diffForHumans(),
                    'status' => $job->status,
                ];
            });

        return response()->json([
            'company' => [
                'id' => $company->id,
                'name' => $company->company_name,
                'logo' => $company->logo,
            ],

            'stats' => [
                'total_applications' => $totalApplications,
                'active_jobs' => $activeJobs,
                'interviews' => $interviews,
                'hired' => $hired,
            ],

            'activity' => $activity,
            'pipeline' => $pipeline,
            'recent_applicants' => $recentApplicants,
            'active_jobs' => $activeJobsList,
        ]);
    }
}
