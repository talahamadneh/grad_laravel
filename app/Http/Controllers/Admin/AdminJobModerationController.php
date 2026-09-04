<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobPost;
use App\Models\Application;
use App\Services\AdminActivityLogService;
use Illuminate\Http\Request;

class AdminJobModerationController extends Controller
{
    public function applicants(Request $request, JobPost $job)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $job->applications()
            ->with(['student.user', 'resume'])
            ->orderByDesc('applied_at')
            ->orderByDesc('id');

        $applicantsCount = (clone $query)->count();
        $usePagination = $request->hasAny(['page', 'per_page']);

        if ($usePagination) {
            $applicants = $query
                ->paginate((int) ($validated['per_page'] ?? 20))
                ->through(fn (Application $application) => $this->formatApplicant($application, $job));
        } else {
            $applicants = $query
                ->get()
                ->map(fn (Application $application) => $this->formatApplicant($application, $job))
                ->values();
        }

        return response()->json([
            'job' => [
                'id' => $job->id,
                'title' => $job->title,
            ],
            'applicants_count' => $applicantsCount,
            'applicants' => $applicants,
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'status' => 'nullable|in:Open,Pending Review,Changes Requested,Suspended,Rejected,Closed',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = JobPost::with(['company', 'skills', 'category'])
            ->withCount('applications');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $jobs = $query
            ->orderBy('quality_score')
            ->latest()
            ->paginate((int) $request->input('per_page', 15));

        $jobs->getCollection()->transform(function (JobPost $job) {
            return $this->formatJob($job);
        });

        return response()->json($jobs);
    }

    public function show(Request $request, JobPost $job)
    {
        $this->authorizeAdmin($request);

        $job->load(['company', 'skills', 'category'])
            ->loadCount('applications');

        return response()->json([
            'job' => $this->formatJob($job),
            'latest_validation' => [
                'quality_score' => $job->quality_score,
                'status' => $job->status,
                'issues' => $job->moderation_issues ?? [],
                'recommendation' => $job->moderation_recommendation,
                'moderated_at' => $job->moderated_at,
            ],
        ]);
    }

    public function approve(Request $request, JobPost $job)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'note' => 'nullable|string|max:2000',
        ]);

        $job->update([
            'status' => 'Open',
            'moderation_recommendation' => 'Approved',
            'moderation_note' => $validated['note'] ?? null,
            'reviewed_at' => now(),
        ]);

        AdminActivityLogService::jobApprovedByAdmin(
            $job->id,
            $job->title,
            $request->user()?->id,
            $validated['note'] ?? null
        );

        return response()->json([
            'message' => 'Job approved and published successfully.',
            'job' => $this->formatJob($job->fresh(['company', 'skills', 'category'])),
        ]);
    }

    public function reject(Request $request, JobPost $job)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'note' => 'required|string|min:10|max:2000',
        ]);

        $job->update([
            'status' => 'Rejected',
            'moderation_recommendation' => 'Reject',
            'moderation_note' => $validated['note'],
            'reviewed_at' => now(),
        ]);

        AdminActivityLogService::jobRejectedByAdmin(
            $job->id,
            $job->title,
            $request->user()?->id,
            $validated['note']
        );

        return response()->json([
            'message' => 'Job rejected successfully.',
            'job' => $this->formatJob($job->fresh(['company', 'skills', 'category'])),
        ]);
    }

    public function requestChanges(Request $request, JobPost $job)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'note' => 'nullable|string|max:2000',
        ]);

        $job->update([
            'status' => 'Changes Requested',
            'moderation_recommendation' => 'Request Changes',
            'moderation_note' => $validated['note'] ?? null,
            'reviewed_at' => now(),
        ]);

        AdminActivityLogService::jobChangesRequestedByAdmin(
            $job->id,
            $job->title,
            $request->user()?->id,
            $validated['note'] ?? null
        );

        return response()->json([
            'message' => 'Changes requested successfully.',
            'job' => $this->formatJob($job->fresh(['company', 'skills', 'category'])),
        ]);
    }

    public function suspend(Request $request, JobPost $job)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'note' => 'nullable|string|max:2000',
        ]);

        $job->update([
            'status' => 'Suspended',
            'moderation_recommendation' => 'Suspend Job',
            'moderation_note' => $validated['note'] ?? null,
            'reviewed_at' => now(),
        ]);

        AdminActivityLogService::jobSuspendedByAdmin(
            $job->id,
            $job->title,
            $request->user()?->id,
            $validated['note'] ?? null
        );

        return response()->json([
            'message' => 'Job suspended successfully.',
            'job' => $this->formatJob($job->fresh(['company', 'skills', 'category'])),
        ]);
    }

    public function restoreToReview(Request $request, JobPost $job)
    {
        $this->authorizeAdmin($request);

        if ($job->status !== 'Rejected') {
            return response()->json([
                'message' => 'Only rejected jobs can be restored to review.',
            ], 422);
        }

        $job->update([
            'status' => 'Pending Review',
            'moderation_recommendation' => 'Manual Review',
            'moderation_note' => null,
            'reviewed_at' => null,
        ]);

        $job->load(['company', 'skills', 'category'])
            ->loadCount('applications');

        return response()->json([
            'message' => 'Job restored to pending review successfully.',
            'job' => $this->formatJob($job),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_if(strtolower($request->user()?->role ?? '') !== 'admin', 403, 'Unauthorized. Admin access required.');
    }

    private function formatApplicant(Application $application, JobPost $job): array
    {
        $student = $application->student;
        $resume = $application->resume;

        return [
            'application_id' => $application->id,
            'job_id' => $job->id,
            'student_id' => $application->student_id,
            'name' => $resume?->full_name ?? $student?->user?->name ?? 'Unknown Student',
            'email' => $student?->user?->email,
            'avatar' => $student?->avatar,
            'professional_title' => $resume?->professional_title ?? $student?->headline,
            'status' => $application->status,
            'match_percentage' => $application->match_score === null
                ? null
                : (float) $application->match_score,
            'applied_at' => $application->applied_at?->toISOString(),
        ];
    }

    private function formatJob(JobPost $job): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'company' => $job->company?->company_name,
            'department' => $job->department,
            'category_id' => $job->category_id,
            'category' => $job->category ? [
                'id' => $job->category->id,
                'name' => $job->category->name,
                'slug' => $job->category->slug,
            ] : null,
            'description' => $job->description,
            'requirements' => $job->requirements,
            'employment_type' => $job->employment_type,
            'level' => $job->level,
            'min_experience_years' => $job->min_experience_years,
            'max_experience_years' => $job->max_experience_years,
            'work_mode' => $job->work_mode,
            'location' => $job->location,
            'salary' => $job->salary,
            'deadline' => $job->deadline,
            'required_major' => $job->required_major,
            'status' => $job->status,
            'applications_count' => $job->applications_count
                ?? $job->applications()->count(),
            'quality_score' => $job->quality_score,
            'moderation_issues' => $job->moderation_issues ?? [],
            'moderation_recommendation' => $job->moderation_recommendation,
            'moderation_note' => $job->moderation_note,
            'skills' => $job->skills->pluck('name')->values(),
            'created_at' => $job->created_at,
            'moderated_at' => $job->moderated_at,
            'reviewed_at' => $job->reviewed_at,
        ];
    }
}
