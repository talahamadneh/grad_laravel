<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\JobPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\JobMatchingService;
use App\Models\SavedJob;
use App\Models\Application;
use App\Models\Resume;
use App\Models\PrivacySetting;
use App\Services\AIJobMatchService;
use App\Services\NotificationService;


class JobController extends Controller
{
    public function index(Request $request)
    {
        $jobs = JobPost::with([
            'company',
            'skills'
        ])
            ->withCount('applications')
            ->where('status', 'Open')
            ->paginate(10);

        return response()->json($jobs);
    }

    public function show($id)
    {
        $job = JobPost::with([
            'company',
            'skills'
        ])
            ->withCount('applications')
            ->where('status', 'Open')
            ->findOrFail($id);

        return response()->json($job);
    }

    public function saveJob($id)
    {
        $student = Auth::user()->student;


        $saved = SavedJob::where('student_id', $student->id)
            ->where('job_post_id', $id)
            ->first();


        if ($saved) {

            return response()->json([
                'message' => 'Job already saved'
            ], 409);

        }


        SavedJob::create([
            'student_id' => $student->id,
            'job_post_id' => $id
        ]);


        return response()->json([
            'message' => 'Job saved successfully'
        ]);
    }

    public function removeSaveJob($id)
    {
        $student = Auth::user()->student;


        SavedJob::where('student_id', $student->id)
            ->where('job_post_id', $id)
            ->delete();


        return response()->json([
            'message' => 'Job removed from saved'
        ]);
    }

    public function checkSaved($id)
    {
        $student = Auth::user()->student;


        $saved = SavedJob::where('student_id', $student->id)
            ->where('job_post_id', $id)
            ->exists();


        return response()->json([
            'saved' => $saved
        ]);
    }

    public function savedJobs()
    {
        $student = Auth::user()->student;


        $jobs = SavedJob::with([
            'jobPost.company'
        ])
            ->where('student_id', $student->id)
            ->get();


        return response()->json($jobs);
    }


    public function applyJob(Request $request, $id, AIJobMatchService $aiJobMatch)
    {
        $student = Auth::user()->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        $job = JobPost::with('company')
            ->where('status', 'Open')
            ->find($id);

        if (!$job) {
            return response()->json([
                'message' => 'Job not found or closed'
            ], 404);
        }

        $already = Application::where('student_id', $student->id)
            ->where('job_post_id', $id)
            ->first();

        if ($already) {
            return response()->json([
                'message' => 'You already applied to this job'
            ], 409);
        }

        $request->validate([
            'resume_id' => 'nullable|integer|exists:resumes,id'
        ]);

        if ($request->filled('resume_id')) {
            $resume = Resume::where('id', $request->resume_id)
                ->where('student_id', $student->id)
                ->first();

            if (!$resume) {
                return response()->json([
                    'message' => 'Invalid resume selected'
                ], 422);
            }
        } else {
            $resume = Resume::where('student_id', $student->id)->latest()->first();
        }

        if (!$resume) {
            return response()->json([
                'message' => 'Please create a resume before applying'
            ], 422);
        }

        $application = Application::create([
            'student_id' => $student->id,
            'job_post_id' => $id,
            'resume_id' => $resume->id,
            'status' => 'Applied',
            'applied_at' => now(),
        ]);

        $application->load([
            'student.skills',
            'student.user',
            'jobPost.skills',
            'jobPost.company.user',
            'resume'
        ]);

        // Privacy settings of the company
        $privacy = PrivacySetting::firstOrCreate(
            [
                'user_id' => $job->company->user_id
            ],
            [
                'profile_visibility' => true,
                'contact_visibility' => false,
                'ai_resume_analysis' => true,
                'ai_candidate_matching' => true,
            ]
        );

        if ($privacy->ai_candidate_matching) {

            $match = $aiJobMatch->analyze($application);

        } else {

            $match = [
                'score' => null,
                'analysis' => null,
                'source' => 'disabled',
            ];

        }

        $application->update([
            'match_score' => $match['score'],
            'match_analysis' => $match['analysis'],
            'match_source' => $match['source'],
        ]);

        $application->refresh();

        NotificationService::applicationSubmitted($application);
        NotificationService::newApplicationForCompany($application);
        NotificationService::matchingCandidateForCompany($application);

        return response()->json([
            'message' => 'Application submitted successfully',
            'application' => $application
        ], 201);
    }

    public function checkApplied($id)
    {
        $student = Auth::user()->student;

        $applied = Application::where('student_id', $student->id)
            ->where('job_post_id', $id)
            ->exists();

        return response()->json([
            'applied' => $applied
        ]);
    }

    public function withdrawApplication($id)
    {
        $student = Auth::user()->student;

        $application = Application::where('student_id', $student->id)
            ->where('job_post_id', $id)
            ->first();

        if (!$application) {
            return response()->json([
                'message' => 'Application not found'
            ], 404);
        }

        $application->delete();

        return response()->json([
            'message' => 'Application withdrawn successfully'
        ]);
    }

    public function myApplications()
    {
        $student = Auth::user()->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        $applications = Application::with(['jobPost.company'])
            ->where('student_id', $student->id)
            ->orderByDesc('applied_at')
            ->get();

        $statusMap = [
            'Applied' => 'Applied',
            'Screening' => 'Screening',
            'Under Review' => 'Applied',
            'Shortlisted' => 'Shortlisted',
            'Interview' => 'Interview',
            'Offer' => 'Offer',
            'Accepted' => 'Accepted',
            'Hired' => 'Accepted',
            'Rejected' => 'Rejected',
        ];

        $mapped = $applications->map(function ($app) use ($statusMap) {
            return [
                'id' => $app->id,
                'job_post_id' => $app->job_post_id,
                'title' => $app->jobPost->title ?? 'N/A',
                'company' => $app->jobPost->company->company_name ?? 'N/A',
                'logo' => $app->jobPost->company->logo ?? null,
                'status' => $statusMap[$app->status] ?? $app->status,
                'date' => $app->applied_at?->format('M d, Y'),
            ];
        });

        $total = $applications->count();
        $active = $applications->whereNotIn('status', ['Accepted', 'Rejected'])->count();
        $interviews = $applications->where('status', 'Interview')->count();
        $offers = $applications->where('status', 'Offer')->count();

        return response()->json([
            'stats' => [
                'total' => $total,
                'active' => $active,
                'interviews' => $interviews,
                'offers' => $offers,
            ],
            'applications' => $mapped,
        ]);
    }


    public function recommendedJobs(JobMatchingService $service)
    {

        $student = Auth::user()->student;


        if (!$student) {
            return response()->json([
                "message" => "Student profile not found"
            ], 404);
        }


        $jobs = $service->getRecommendedJobs($student);


        return response()->json($jobs);

    }


}
