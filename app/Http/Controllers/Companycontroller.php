<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\JobPost;
use App\Models\Skill;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Interview;
use Illuminate\Support\Facades\Validator;
use App\Services\GeminiService;
use App\Services\JobMatchingService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    public function jobs(Request $request)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $jobs = JobPost::withCount('applications')
            ->where('company_id', $company->id)
            ->latest()
            ->get()
            ->map(function ($job) use ($company) {
                return [
                    'id' => $job->id,
                    'title' => $job->title,
                    'dept' => $job->department,
                    'type' => $job->employment_type,
                    'mode' => $job->work_mode,
                    'location' => $job->location,
                    'status' => $job->status,
                    'applicants' => $job->applications_count,
                    'company' => $company->company_name,
                    'views' => 0,
                    'posted' => optional($job->created_at)->diffForHumans(),
                    'benefits' => $job->benefits ?? [],

                ];
            });

        return response()->json($jobs);
    }

    public function profile(Request $request)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        return response()->json($this->formatCompany($company));
    }

    public function update(Request $request)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'company_name' => 'sometimes|string|max:255',
            'industry' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'website' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'location' => 'nullable|string|max:255',
            'company_size' => 'nullable|string|max:255',
            'stage' => 'nullable|string|max:255',
            'founded_year' => 'nullable|digits:4',
            'logo' => 'nullable|string',
            'cover_image' => 'nullable|string',
            'values' => 'nullable|array',
            'values.*' => 'string|max:255',
            'benefits' => 'nullable|array',
            'benefits.*' => 'string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        $company->company_name = $validated['company_name'] ?? $company->company_name;
        $company->industry = $validated['industry'] ?? $company->industry;
        $company->description = $validated['description'] ?? $company->description;
        $company->website = $validated['website'] ?? $company->website;
        $company->phone = $validated['phone'] ?? $company->phone;
        $company->location = $validated['location'] ?? $company->location;
        $company->company_size = $validated['company_size'] ?? $company->company_size;
        $company->stage = $validated['stage'] ?? $company->stage;
        $company->founded_year = $validated['founded_year'] ?? $company->founded_year;
        $company->logo = $validated['logo'] ?? $company->logo;
        $company->cover_image = $validated['cover_image'] ?? $company->cover_image;
        $company->values = $validated['values'] ?? $company->values;
        $company->benefits = $validated['benefits'] ?? $company->benefits;

        $company->save();

        return response()->json([
            'message' => 'Company profile updated successfully',
            'company' => $this->formatCompany($company),
        ]);
    }

    private function formatCompany(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->company_name,
            'industry' => $company->industry,
            'about' => $company->description,
            'logo' => $company->logo,
            'cover' => $company->cover_image,
            'website' => $company->website,
            'phone' => $company->phone,
            'location' => $company->location,
            'size' => $company->company_size,
            'stage' => $company->stage,
            'founded' => $company->founded_year,
            'values' => $company->values ?? [],
            'benefits' => $company->benefits ?? [],
            'is_verified' => (bool) $company->is_verified,
            'approval_status' => $company->approval_status,
        ];
    }

    public function storeJob(Request $request)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json(['message' => 'Company profile not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'department' => 'nullable|string|max:255',
            'employment_type' => 'required|in:Full-Time,Part-Time,Internship,Contract',
            'level' => 'nullable|string|max:255',
            'work_mode' => 'required|in:Remote,Hybrid,On-site',
            'location' => 'nullable|string|max:255',
            'salary' => 'nullable|numeric',
            'description' => 'nullable|string',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:255',
            'benefits' => 'nullable|array',
            'benefits.*' => 'string|max:255',
            'deadline' => 'nullable|date',
            'vacancies' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $job = JobPost::create([
            'company_id' => $company->id,
            'title' => $validated['title'],
            'department' => $validated['department'] ?? null,
            'employment_type' => $validated['employment_type'],
            'level' => $validated['level'] ?? null,
            'work_mode' => $validated['work_mode'],
            'location' => $validated['location'] ?? null,
            'salary' => $validated['salary'] ?? null,
            'description' => $validated['description'] ?? null,
            'benefits' => $validated['benefits'] ?? [],
            'deadline' => $validated['deadline'] ?? null,
            'vacancies' => $validated['vacancies'] ?? 1,
            'status' => 'Open',
        ]);

        if (!empty($validated['skills'])) {
            $skillIds = [];
            foreach ($validated['skills'] as $skillName) {
                $skill = Skill::firstOrCreate(['name' => $skillName]);
                $skillIds[] = $skill->id;
            }
            $job->skills()->sync($skillIds);
        }

        return response()->json([
            'message' => 'Job posted successfully',
            'job' => $job->load('skills'),
        ], 201);
    }

    public function generateJobDescription(Request $request, GeminiService $gemini)
    {
        $request->validate([
            'title' => 'required|string',
            'department' => 'nullable|string',
            'level' => 'nullable|string',
            'work_mode' => 'nullable|string',
            'skills' => 'nullable|array',
        ]);

        $skillsText = !empty($request->skills) ? implode(', ', $request->skills) : 'general relevant skills';

        $prompt = "Write a professional and engaging job description for the following role:
Title: {$request->title}
Department: " . ($request->department ?? 'Not specified') . "
Level: " . ($request->level ?? 'Not specified') . "
Work Mode: " . ($request->work_mode ?? 'Not specified') . "
Required Skills: {$skillsText}

Write 2-3 paragraphs describing the role, responsibilities, and what success looks like. Do not include the job title as a heading, just the description text itself. Respond with plain text only, no markdown.";

        try {
            $description = $gemini->generate($prompt);

            return response()->json([
                'description' => trim($description)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'AI service error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function applicants(Request $request, JobMatchingService $matchingService)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $applications = Application::with([
            'student.user',
            'student.skills',
            'jobPost.skills'
        ])
            ->whereHas('jobPost', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->latest()
            ->get();

        $applicants = $applications->map(function ($application) use ($matchingService) {

            $student = $application->student;

            $match = $matchingService->calculateMatch(
                $student,
                $application->jobPost
            );

            return [
                'id' => (int) $application->id,
                'application_id' => (int) $application->id,
                'name' => $student->user->name ?? 'N/A',
                'title' => $student->headline ?? '',
                'university' => $student->university ?? '',
                'location' => $student->location ?? '',
                'avatar' => $student->avatar,
                'job_id' => $application->jobPost->id,
                'job' => $application->jobPost->title ?? '',
                'status' => $application->status,
               'match' => $match['match'],
'matching_skills' => $match['matching_skills'],
'missing_skills' => $match['missing_skills'],

'match_source' => $application->match_source,
'match_recommendation' => $application->match_analysis['recommendation'] ?? null,

'skills' => $student->skills
                    ->pluck('name')
                    ->values(),
                'email' => $student->user->email ?? '',
                'applied_at' => optional($application->applied_at)
                    ->format('Y-m-d'),
            ];

        });

        return response()->json($applicants);
    }

    public function applicantDetails(Request $request, $id, JobMatchingService $matchingService)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $application = Application::with([
            'student.user',
            'student.skills',
            'student.education',
            'student.experience',
            'student.projects',
            'student.certificates',
            'resume',
            'notes',
            'timeline',
            'jobPost.skills'
        ])
            ->where('id', $id)
            ->whereHas('jobPost', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->first();

        if (!$application) {
            return response()->json([
                'message' => 'Applicant not found'
            ], 404);
        }

        $student = $application->student;
        return response()->json([
            'application_id' => $application->id,
            'status' => $application->status,
            'student' => [
                'name' => $student->user->name,
                'email' => $student->user->email,
                'phone' => $student->phone,
                'avatar' => $student->avatar,
                'headline' => $student->headline,
                'university' => $student->university,
                'major' => $student->major,
                'gpa' => $student->gpa,
                'location' => $student->location,
                'portfolio' => $student->portfolio,
                'linkedin' => $student->linkedin,
                'github' => $student->github,
                'bio' => $student->bio,
            ],
            'skills' => $student->skills
                ->pluck('name')
                ->values(),
            'education' => $student->education,
            'experience' => $student->experience,
            'projects' => $student->projects,
            'certificates' => $student->certificates,
            'resume' => $application->resume ? [
                'id' => $application->resume->id,
                'title' => $application->resume->title,
                'template' => $application->resume->template,
                'file_path' => $application->resume->file_path,
                'updated_at' => $application->resume->updated_at,
            ] : null,
'match' => [
    'percentage' => $this->getApplicantMatch($application, $matchingService)['percentage'],
    'matching_skills' => $this->getApplicantMatch($application, $matchingService)['matching_skills'],
    'missing_skills' => $this->getApplicantMatch($application, $matchingService)['missing_skills'],
    'source' => $application->match_source,
    'analysis' => $application->match_analysis,
    'reasons' => $this->generateMatchReasons(
        $application,
        $this->getApplicantMatch($application, $matchingService)
    )
],
            'notes' => $application->notes,
            'timeline' => $application->timeline,
            'ai_summary' => null,
        ]);
    }

    private function generateMatchReasons($application, $match)
    {
        if (!empty($application->match_analysis['matching_points'])) {
            return $application->match_analysis['matching_points'];
        }

        $reasons = [];

        if (count($match['matching_skills']) > 0) {
            $reasons[] = "Matched skills: " . implode(", ", $match['matching_skills']);
        }

        if (
            $application->student->major &&
            $application->jobPost->required_major &&
            strtolower(trim($application->student->major)) === strtolower(trim($application->jobPost->required_major))
        ) {
            $reasons[] = "Major matches job requirements";
        }

        if (
            $application->student->location &&
            $application->jobPost->location &&
            strtolower(trim($application->student->location)) === strtolower(trim($application->jobPost->location))
        ) {
            $reasons[] = "Same location";
        }

        if (
            $application->student->preferred_employment_type &&
            $application->jobPost->employment_type &&
            strtolower(trim($application->student->preferred_employment_type)) === strtolower(trim($application->jobPost->employment_type))
        ) {
            $reasons[] = "Employment type matches";
        }

        if (empty($reasons)) {
            $reasons[] = "Candidate profile matches job requirements";
        }

        return $reasons;
    }

    public function aiCandidateSummary(Request $request, $id, GeminiService $gemini)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $application = Application::with([
            'student.user',
            'student.skills',
            'student.education',
            'student.experience',
            'student.projects',
            'jobPost'
        ])
            ->where('id', $id)
            ->whereHas('jobPost', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->first();

        if (!$application) {
            return response()->json([
                'message' => 'Applicant not found'
            ], 404);
        }

        $student = $application->student;
        $skills = $student->skills
            ->pluck('name')
            ->implode(', ');

        $experience = $student->experience
            ->map(function ($exp) {
                return $exp->position .
                    " at " .
                    $exp->company;
            })
            ->implode(', ');

        $projects = $student->projects
            ->pluck('title')
            ->implode(', ');

        $prompt = "
You are an AI recruitment assistant.

Analyze this candidate for a job application.

Candidate Name:
{$student->user->name}

Headline:
{$student->headline}

Major:
{$student->major}

GPA:
{$student->gpa}

Bio:
{$student->bio}

Skills:
{$skills}

Experience:
{$experience}

Projects:
{$projects}

Applied Job:
{$application->jobPost->title}

Write a professional candidate evaluation.

Include:
- Main strengths
- Technical suitability
- Possible concerns
- Recommendation for interview

Keep it concise (one paragraph).
Do not use markdown.
Return a short professional hiring summary.

Return plain text only.
Do not use markdown symbols.";

        try {
            $summary = $gemini->generate($prompt);
            return response()->json([
                'candidate' => $student->user->name,
                'summary' => $summary
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'AI service error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function fullApplicantDetails(Request $request, $id, GeminiService $gemini, JobMatchingService $matchingService)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $application = Application::with([
            'student.user',
            'student.skills',
            'student.education',
            'student.experience',
            'student.projects',
            'student.certificates',
            'resume',
            'notes',
            'timeline',
            'jobPost.skills'
        ])
            ->where('id', $id)
            ->whereHas('jobPost', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->first();

        if (!$application) {
            return response()->json([
                'message' => 'Applicant not found'
            ], 404);
        }

        $student = $application->student;

        $skills = $student->skills
            ->pluck('name')
            ->implode(', ');

        $projects = $student->projects
            ->pluck('title')
            ->implode(', ');

        $prompt = "
Analyze this job candidate briefly.

Name:
{$student->user->name}

Headline:
{$student->headline}

Major:
{$student->major}

GPA:
{$student->gpa}

Skills:
{$skills}

Projects:
{$projects}

Applied Job:
{$application->jobPost->title}

Provide a short professional hiring summary.
";

        try {
            $aiSummary = $gemini->generate($prompt);
        } catch (\Exception $e) {
            $aiSummary = "AI summary unavailable";
        }

        return response()->json([
            'application_id' => $application->id,

            'student' => [
                'name' => $student->user->name,
                'email' => $student->user->email,
                'phone' => $student->phone,
                'avatar' => $student->avatar,
                'headline' => $student->headline,
                'university' => $student->university,
                'major' => $student->major,
                'gpa' => $student->gpa,
                'location' => $student->location,
                'bio' => $student->bio,
                'portfolio' => $student->portfolio,
                'linkedin' => $student->linkedin,
                'github' => $student->github,
            ],

            'skills' => $student->skills
                ->pluck('name')
                ->values(),

            'resume' => $application->resume,

'match' => [
    'percentage' => $this->getApplicantMatch($application, $matchingService)['percentage'],
    'matching_skills' => $this->getApplicantMatch($application, $matchingService)['matching_skills'],
    'missing_skills' => $this->getApplicantMatch($application, $matchingService)['missing_skills'],
    'source' => $application->match_source,
    'analysis' => $application->match_analysis,
    'reasons' => $this->generateMatchReasons(
        $application,
        $this->getApplicantMatch($application, $matchingService)
    )
],
            'notes' => $application->notes,

            'timeline' => $application->timeline,

            'ai_summary' => $aiSummary,

            'job' => [
                'id' => $application->jobPost->id,
                'title' => $application->jobPost->title
            ]
        ]);
    }

    private function getApplicantMatch($application, JobMatchingService $matchingService)
    {
        $match = $matchingService->calculateMatch(
            $application->student,
            $application->jobPost
        );

        return [
            'percentage' => $match['match'],
            'matching_skills' => $match['matching_skills'],
            'missing_skills' => $match['missing_skills'],
            'reasons' => $this->generateMatchReasons($application, $match)
        ];
    }

    public function jobDetails(Request $request, $id, JobMatchingService $matchingService)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $job = JobPost::with([
            'skills',
            'applications.student.user',
            'applications.student.skills'
        ])
            ->where('company_id', $company->id)
            ->find($id);

        if (!$job) {
            return response()->json([
                'message' => 'Job not found'
            ], 404);
        }

        $applications = $job->applications;

        return response()->json([

            'id' => $job->id,
            'title' => $job->title,
            'department' => $job->department,
            'description' => $job->description,
            'employment_type' => $job->employment_type,
            'work_mode' => $job->work_mode,
            'location' => $job->location,
            'salary' => $job->salary,
            'deadline' => $job->deadline,
            'vacancies' => $job->vacancies,
            'required_major' => $job->required_major,
            'status' => $job->status,
            'created_at' => $job->created_at,

            'skills' => $job->skills
                ->pluck('name')
                ->values(),

            'stats' => [
                'applicants' => $applications->count(),

                'interview' => $applications
                    ->where('status', 'Interview')
                    ->count(),

                'shortlisted' => $applications
                    ->where('status', 'Shortlisted')
                    ->count(),

                'hired' => $applications
                    ->whereIn('status', ['Accepted', 'Hired'])
                    ->count(),
            ],

            'recent_applicants' => $applications
                ->sortByDesc('applied_at')
                ->take(5)
                ->values()
                ->map(function ($application) use ($job, $matchingService) {

                    $matchData = $matchingService->calculateMatch(
                        $application->student,
                        $job
                    );

                    return [
                        'id' => $application->id,
                        'application_id' => $application->id,
'name' => $application->student->user->name ?? 'Applicant',
'headline' => $application->student->headline ?? '',
'avatar' => $application->student->avatar ?? null,

'match' => (int) ($matchData['match'] ?? $application->match_score ?? 0),

'match_source' => $application->match_source,
'match_recommendation' => $application->match_analysis['recommendation'] ?? null,
                        'status' => $application->status,
                    ];

                }),

        ]);
    }

    public function editJob(Request $request, $id)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $job = JobPost::with('skills')
            ->where('company_id', $company->id)
            ->find($id);

        if (!$job) {
            return response()->json([
                'message' => 'Job not found'
            ], 404);
        }

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'department' => $job->department,
            'description' => $job->description,
            'employment_type' => $job->employment_type,
            'level' => $job->level,
            'work_mode' => $job->work_mode,
            'location' => $job->location,
            'salary' => $job->salary,
            'benefits' => $job->benefits ?? [],
            'deadline' => $job->deadline,
            'vacancies' => $job->vacancies,
            'required_major' => $job->required_major,
            'status' => $job->status,
            'skills' => $job->skills
                ->pluck('name')
                ->values(),
        ]);
    }

    public function updateJob(Request $request, $id)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $job = JobPost::with('skills')
            ->where('company_id', $company->id)
            ->find($id);

        if (!$job) {
            return response()->json([
                'message' => 'Job not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'department' => 'nullable|string|max:255',
            'employment_type' => 'required|in:Full-Time,Part-Time,Internship,Contract',
            'level' => 'nullable|string|max:255',
            'work_mode' => 'required|in:Remote,Hybrid,On-site',
            'location' => 'nullable|string|max:255',
            'salary' => 'nullable|numeric',
            'description' => 'nullable|string',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:255',
            'benefits' => 'nullable|array',
            'benefits.*' => 'string|max:255',
            'deadline' => 'nullable|date',
            'vacancies' => 'nullable|integer|min:1',
            'required_major' => 'nullable|string|max:255',
            'status' => 'nullable|in:Open,Closed,Draft',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        $job->update([
            'title' => $validated['title'],
            'department' => $validated['department'] ?? null,
            'employment_type' => $validated['employment_type'],
            'level' => $validated['level'] ?? null,
            'work_mode' => $validated['work_mode'],
            'location' => $validated['location'] ?? null,
            'salary' => $validated['salary'] ?? null,
            'description' => $validated['description'] ?? null,
            'benefits' => $validated['benefits'] ?? [],
            'deadline' => $validated['deadline'] ?? null,
            'vacancies' => $validated['vacancies'] ?? 1,
            'required_major' => $validated['required_major'] ?? null,
            'status' => $validated['status'] ?? $job->status,
        ]);

        if (isset($validated['skills'])) {

            $skillIds = [];

            foreach ($validated['skills'] as $skillName) {

                $skill = Skill::firstOrCreate([
                    'name' => $skillName
                ]);

                $skillIds[] = $skill->id;
            }

            $job->skills()->sync($skillIds);
        }

        return response()->json([
            'message' => 'Job updated successfully',
            'job' => $job->load('skills')
        ]);
    }

    public function destroyJob(Request $request, $id)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $job = JobPost::where('id', $id)
            ->where('company_id', $company->id)
            ->first();

        if (!$job) {
            return response()->json([
                'message' => 'Job not found'
            ], 404);
        }

        if ($job->applications()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a job that has applicants.'
            ], 422);
        }

        $job->delete();

        return response()->json([
            'message' => 'Job deleted successfully'
        ]);
    }

    public function shortlist(Request $request, Application $application)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company || $application->jobPost->company_id != $company->id) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        if ($application->status !== 'Shortlisted') {
            $application->update([
                'status' => 'Shortlisted'
            ]);

            NotificationService::applicationStatusChanged(
                $application->fresh(['student.user', 'jobPost.company']),
                'Shortlisted'
            );

            ApplicationStatusHistory::create([
                'application_id' => $application->id,
                'status' => 'Shortlisted',
                'changed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Candidate shortlisted successfully.',
            'application' => $application
        ]);
    }

    public function getShortlisted(Request $request, JobPost $job)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company || $job->company_id != $company->id) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $applications = $job->applications()
            ->where('status', 'Shortlisted')
            ->with([
                'student.user',
                'student.skills',
                'student.education',
                'resume'
            ])
            ->get();

        return response()->json(
            $applications->map(function ($application) {

                $student = $application->student;

                return [
                    'id' => $application->id,
                    'status' => $application->status,

                    'student' => [
                        'name' => $student->user->name ?? '',
                        'email' => $student->user->email ?? '',
                        'phone' => $student->phone,
                        'avatar' => $student->avatar,
                        'headline' => $student->headline,
                        'university' => $student->university,
                        'major' => $student->major,
                        'gpa' => $student->gpa,
                        'location' => $student->location,
                        'bio' => $student->bio,
                        'portfolio' => $student->portfolio,
                        'linkedin' => $student->linkedin,
                        'github' => $student->github,
                    ],

                    'skills' => $student->skills
                        ->pluck('name')
                        ->values(),

                    'education' => $student->education,

                    'resume' => $application->resume ? [
                        'id' => $application->resume->id,
                        'title' => $application->resume->title,
                        'file_path' => $application->resume->file_path,
                    ] : null,

                    'shortlisted_at' => $application->updated_at,
                ];

            })
        );
    }

    public function scheduleInterview(Request $request)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $request->validate([
            'application_id' => 'required|exists:applications,id',
            'interview_date' => 'required|date|after:now',
            'type' => 'required|in:Online,Onsite',
            'meeting_link' => 'nullable|string',
            'location' => 'nullable|string',
        ]);

        $application = Application::findOrFail($request->application_id);

        if ($application->jobPost->company_id != $company->id) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $interview = $this->createInterview(
            $application,
            $request->interview_date,
            $request->type,
            $request->meeting_link,
            $request->location
        );

        return response()->json([
            'message' => 'Interview scheduled successfully.',
            'interview' => $interview
        ], 201);
    }

    private function createInterview(
        Application $application,
        string $interviewDate,
        string $type,
        ?string $meetingLink,
        ?string $location
    ) {
        $interview = Interview::create([
            'application_id' => $application->id,
            'interview_date' => $interviewDate,
            'type' => $type,
            'meeting_link' => $meetingLink,
            'location' => $location,
            'status' => 'Scheduled',
        ]);

        $application->update([
            'status' => 'Interview'
        ]);

        ApplicationStatusHistory::create([
            'application_id' => $application->id,
            'status' => 'Interview',
            'changed_at' => now(),
        ]);

        NotificationService::interviewScheduled(
            $interview->fresh([
                'application.student',
                'application.jobPost.company'
            ])
        );

        return $interview;
    }

    public function interviews(Request $request)
    {
        $company = $request->user()->company;

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $interviews = Interview::with([
            'application.student.user',
            'application.student'
        ])
            ->whereHas('application.jobPost', function ($q) use ($company) {
                $q->where('company_id', $company->id);
            })
            ->latest()
            ->get()
            ->map(function ($interview) {

                $student = $interview->application->student;

                return [
                    'id' => $interview->id,
                    'name' => $student->user->name,
                    'avatar' => $student->avatar,
                    'role' => $student->headline,
                    'type' => $interview->type,
                    'date' => $interview->interview_date,
                    'time' => null,
                    'duration' => '30 min',
                    'status' => $interview->status,
                ];
            });

        return response()->json($interviews);
    }

    public function bulkSchedule(Request $request)
    {
        $request->validate([
            'application_ids' => 'required|array|min:1',
            'application_ids.*' => 'exists:applications,id',

            'interview_date' => 'required|date',

            'start_time' => 'required',

            'duration' => 'required|integer|min:5',

            'type' => 'required|in:Online,Onsite',

            'meeting_link' => 'nullable|string',

            'location' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {

            $currentTime = Carbon::parse(
                $request->interview_date . ' ' . $request->start_time
            );

            $count = 0;

            foreach ($request->application_ids as $applicationId) {

                $application = Application::findOrFail($applicationId);

                if ($application->jobPost->company_id != auth()->user()->company->id) {

                    DB::rollBack();

                    return response()->json([
                        'message' => 'Unauthorized.'
                    ], 403);

                }

                if (Interview::where('application_id', $application->id)->exists()) {

                    DB::rollBack();

                    return response()->json([
                        'message' => 'One or more selected candidates already have an interview scheduled.'
                    ], 422);

                }

                $this->createInterview(

                    $application,

                    $currentTime->format('Y-m-d H:i:s'),

                    $request->type,

                    $request->meeting_link,

                    $request->location

                );

                $count++;

                $currentTime->addMinutes($request->duration);

            }

            DB::commit();

            return response()->json([

                'message' => 'Interviews scheduled successfully.',

                'scheduled_count' => $count

            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([

                'message' => 'Scheduling failed.',

                'error' => $e->getMessage()

            ], 500);

        }
    }
}