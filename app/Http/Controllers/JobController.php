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
use App\Services\NotificationService;

class JobController extends Controller
{
    protected NotificationService $notificationService;
    protected JobMatchingService $jobMatchingService;

    public function __construct(
        NotificationService $notificationService,
        JobMatchingService $jobMatchingService
    ) {
        $this->notificationService = $notificationService;
        $this->jobMatchingService = $jobMatchingService;
    }

    public function index(Request $request)
    {
        $student = Auth::user()?->student;

        $query = JobPost::with(['company', 'skills'])
            ->withCount('applications')
            ->where('status', 'Open');

        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('company', function ($c) use ($search) {
                        $c->where('company_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->has('types') && is_array($request->input('types'))) {
            $types = $request->input('types');

            if (count($types)) {
                $query->whereIn('employment_type', $types);
            }
        }

        if ($request->has('modes') && is_array($request->input('modes'))) {
            $modes = $request->input('modes');

            if (count($modes)) {
                $query->whereIn('work_mode', $modes);
            }
        }

        $jobs = $query->paginate(10);

        if ($student) {
            $savedIds = SavedJob::where('student_id', $student->id)
                ->pluck('job_post_id')
                ->toArray();

            $jobs->getCollection()->transform(function ($job) use ($student, $savedIds) {
                $job->is_saved = in_array($job->id, $savedIds);

                $job->match = $this->jobMatchingService
                    ->calculateMatch($student, $job);

                return $job;
            });
        }

        return response()->json($jobs);
    }

    public function show($id)
    {
        $student = Auth::user()?->student;

        $job = JobPost::with(['company', 'skills'])
            ->withCount('applications')
            ->where('status', 'Open')
            ->findOrFail($id);

        if ($student) {
            $job->is_saved = SavedJob::where('student_id', $student->id)
                ->where('job_post_id', $id)
                ->exists();
        }

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
            'job_post_id' => $id,
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

        return response()->json([
            'saved' => SavedJob::where('student_id', $student->id)
                ->where('job_post_id', $id)
                ->exists()
        ]);
    }

    public function savedJobs()
    {
        $student = Auth::user()->student;

        $jobs = SavedJob::with([
            'jobPost.company',
            'jobPost.skills'
        ])
            ->where('student_id', $student->id)
            ->get()
            ->pluck('jobPost')
            ->filter()
            ->values();

        return response()->json($jobs);
    }

    public function applyJob(Request $request, $id)
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
            $resume = Resume::where('student_id', $student->id)
                ->latest()
                ->first();
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

        $score = NotificationService::calculateApplicationMatchScore($application);

        $match = [
            'score' => $score,
            'analysis' => [
                'level' => 'Pending AI Analysis',
                'matching_points' => [],
                'missing_points' => [],
                'location_assessment' => null,
                'skills_assessment' => null,
                'recommendation' => null,
                'source' => 'fallback',
            ],
            'source' => 'fallback',
        ];

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

        return response()->json([
            'applied' => $student
                ? Application::where('student_id', $student->id)
                    ->where('job_post_id', $id)
                    ->exists()
                : false
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

        $applications = Application::with([
            'jobPost.company'
        ])
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

        return response()->json([
            'stats' => [
                'total' => $applications->count(),
                'active' => $applications->whereNotIn('status', ['Accepted', 'Rejected'])->count(),
                'interviews' => $applications->where('status', 'Interview')->count(),
                'offers' => $applications->where('status', 'Offer')->count(),
            ],
            'applications' => $mapped,
        ]);
    }

    public function recommendedJobs()
    {
        $student = Auth::user()->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        $jobs = $this->jobMatchingService->getRecommendedJobs($student);

        return response()->json($jobs);
    }
}