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
//use App\Services\GeminiService;
use App\Services\AutomaticJobValidationService;
use App\Services\JobMatchingService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

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
                    'quality_score' => $job->quality_score,
                    'moderation_recommendation' => $job->moderation_recommendation,
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

    public function storeJob(Request $request, AutomaticJobValidationService $validationService)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
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
            'requirements' => 'nullable|string',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:255',
            'benefits' => 'nullable|array',
            'benefits.*' => 'string|max:255',
            'deadline' => 'nullable|date',
            'vacancies' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
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
            'requirements' => $validated['requirements'] ?? null,
            'benefits' => $validated['benefits'] ?? [],
            'deadline' => $validated['deadline'] ?? null,
            'vacancies' => $validated['vacancies'] ?? 1,
            'status' => 'Pending Review',
        ]);

        if (!empty($validated['skills'])) {
            $skillIds = [];

            foreach ($validated['skills'] as $skillName) {
                $skill = Skill::firstOrCreate([
                    'name' => $skillName
                ]);

                $skillIds[] = $skill->id;
            }

            $job->skills()->sync($skillIds);
        }

        $validation = $validationService->apply($job->fresh('skills'));

        return response()->json([
            'message' => $validation['status'] === 'Open'
                ? 'Job passed automatic validation and was published successfully.'
                : 'Job was created and sent to admin moderation.',
            'quality_score' => $validation['quality_score'],
            'moderation_issues' => $validation['issues'],
            'moderation_recommendation' => $validation['recommendation'],
            'job' => $job->fresh('skills'),
        ], 201);
    }

    public function generateJobDescription(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'department' => 'nullable|string|max:255',
            'level' => 'nullable|string|max:100',
            'work_mode' => 'nullable|string|max:100',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:100',
        ]);

        try {
            $apiKey = config('services.groq.keys.0');

            if (!$apiKey) {
                Log::error('Groq API key not configured.');

                return response()->json([
                    'message' => 'Groq API key is not configured.'
                ], 500);
            }

            $skillsText = !empty($request->skills)
                ? implode(', ', $request->skills)
                : 'General relevant skills';

            $department = $request->department ?? 'Not specified';
            $level = $request->level ?? 'Not specified';
            $workMode = $request->work_mode ?? 'Not specified';

            $prompt = <<<PROMPT
Write a professional and engaging job description for the following role.

Job Title: {$request->title}
Department: {$department}
Level: {$level}
Work Mode: {$workMode}
Required Skills: {$skillsText}

Requirements:
- Write 2-3 professional paragraphs.
- Describe the role and its main responsibilities.
- Explain what success in the role looks like.
- Make it attractive to qualified candidates.
- Keep the information consistent with the provided details.
- Do not invent company-specific facts.
- Do not include salary information.
- Do not include benefits.
- Do not add the job title as a heading.
- Return plain text only.
- Do not use markdown.
- Do not use bullet points.
PROMPT;

            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(60)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => config(
                        'services.groq.model',
                        'llama-3.3-70b-versatile'
                    ),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a professional HR and job description writing assistant. Write clear, realistic, engaging job descriptions without inventing company-specific information.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ],
                    ],
                    'temperature' => 0.5,
                    'max_tokens' => 700,
                    'stream' => false,
                ]);

            if (!$response->successful()) {
                Log::error('Groq Job Description Error', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return response()->json([
                    'message' => 'Groq AI request failed.',
                    'details' => $response->json(),
                ], 500);
            }

            $description = data_get(
                $response->json(),
                'choices.0.message.content'
            );

            if (!$description) {
                Log::error('Groq returned empty job description', [
                    'response' => $response->json(),
                ]);

                return response()->json([
                    'message' => 'Groq returned an empty response.'
                ], 500);
            }

            return response()->json([
                'description' => trim($description)
            ]);
        } catch (\Throwable $e) {
            Log::error('Groq Job Description Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to generate job description.',
                'error' => $e->getMessage(),
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
            'resume',
            'jobPost.skills'
        ])
            ->whereHas('jobPost', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->latest()
            ->get();

        $applicants = $applications->map(function ($application) use ($matchingService) {
            $student = $application->student;
            $resume = $application->resume;

            $match = $matchingService->calculateMatch(
                $student,
                $application->jobPost
            );

            return [
                'id' => (int) $application->id,
                'application_id' => (int) $application->id,
                'name' => $resume?->full_name ?? $student->user->name ?? 'N/A',
                'title' => $resume?->professional_title ?? $student->headline ?? '',
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
                'skills' => $resume?->skills ?? $student->skills->pluck('name')->values(),
                'email' => $student->user->email ?? '',
                'applied_at' => optional($application->applied_at)->format('Y-m-d'),
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
        $resume = $application->resume;
        $match = $this->getApplicantMatch($application, $matchingService);

        return response()->json([
            'application_id' => $application->id,
            'status' => $application->status,

            'student' => [
                'name' => $resume?->full_name ?? $student->user->name,
                'email' => $student->user->email,
                'phone' => $student->phone,
                'avatar' => $student->avatar,
                'headline' => $resume?->professional_title ?? $student->headline,
                'university' => $student->university,
                'major' => $student->major,
                'gpa' => $student->gpa,
                'location' => $student->location,
                'portfolio' => $student->portfolio,
                'linkedin' => $student->linkedin,
                'github' => $student->github,
                'bio' => $resume?->summary ?? $student->bio,
            ],

            'skills' => $resume?->skills ?? $student->skills->pluck('name')->values(),

            'education' => $resume?->education ?? [],

            'experience' => $resume?->experience ?? [],

            'projects' => $resume?->projects ?? [],

            'certificates' => $resume?->certificates ?? [],

            'languages' => $resume?->languages ?? [],

            'resume' => $resume ? [
                'id' => $resume->id,
                'title' => $resume->title,
                'template' => $resume->template,
                'full_name' => $resume->full_name,
                'professional_title' => $resume->professional_title,
                'summary' => $resume->summary,
                'experience' => $resume->experience ?? [],
                'education' => $resume->education ?? [],
                'skills' => $resume->skills ?? [],
                'projects' => $resume->projects ?? [],
                'certificates' => $resume->certificates ?? [],
                'languages' => $resume->languages ?? [],
                'file_path' => $resume->file_path,
                'is_public' => $resume->is_public,
                'updated_at' => $resume->updated_at,
            ] : null,

            'match' => [
                'percentage' => $match['percentage'],
                'matching_skills' => $match['matching_skills'],
                'missing_skills' => $match['missing_skills'],
                'source' => $application->match_source,
                'analysis' => $application->match_analysis,
                'reasons' => $this->generateMatchReasons(
                    $application,
                    $match
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
            $reasons[] = 'Matched skills: ' . implode(', ', $match['matching_skills']);
        }

        if (
            $application->student->major &&
            $application->jobPost->required_major &&
            strtolower(trim($application->student->major)) === strtolower(trim($application->jobPost->required_major))
        ) {
            $reasons[] = 'Major matches job requirements';
        }

        if (
            $application->student->location &&
            $application->jobPost->location &&
            strtolower(trim($application->student->location)) === strtolower(trim($application->jobPost->location))
        ) {
            $reasons[] = 'Same location';
        }

        if (
            $application->student->preferred_employment_type &&
            $application->jobPost->employment_type &&
            strtolower(trim($application->student->preferred_employment_type)) === strtolower(trim($application->jobPost->employment_type))
        ) {
            $reasons[] = 'Employment type matches';
        }

        if (empty($reasons)) {
            $reasons[] = 'Candidate profile matches job requirements';
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
            'resume',
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
        $resume = $application->resume;

        $skills = $resume?->skills
            ? implode(', ', $resume->skills)
            : $student->skills->pluck('name')->implode(', ');

        $experience = collect($resume?->experience ?? [])
            ->map(function ($exp) {
                if (is_array($exp)) {
                    $position = $exp['position'] ?? $exp['title'] ?? '';
                    $company = $exp['company'] ?? '';
                    return trim($position . ($company ? ' at ' . $company : ''));
                }

                return (string) $exp;
            })
            ->filter()
            ->implode(', ');

        $projects = collect($resume?->projects ?? [])
            ->map(function ($project) {
                if (is_array($project)) {
                    return $project['title'] ?? $project['name'] ?? '';
                }

                return (string) $project;
            })
            ->filter()
            ->implode(', ');

        $prompt = "
You are an AI recruitment assistant.

Analyze this candidate for a job application.

Candidate Name:
" . ($resume?->full_name ?? $student->user->name) . "

Professional Title:
" . ($resume?->professional_title ?? $student->headline) . "

Major:
{$student->major}

GPA:
{$student->gpa}

Summary:
" . ($resume?->summary ?? $student->bio) . "

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
";

        try {
            $summary = $gemini->generate($prompt);

            return response()->json([
                'candidate' => $resume?->full_name ?? $student->user->name,
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
        $resume = $application->resume;

        $skills = $resume?->skills
            ? implode(', ', $resume->skills)
            : $student->skills->pluck('name')->implode(', ');

        $projects = collect($resume?->projects ?? [])
            ->map(function ($project) {
                if (is_array($project)) {
                    return $project['title'] ?? $project['name'] ?? '';
                }

                return (string) $project;
            })
            ->filter()
            ->implode(', ');

        $prompt = "
Analyze this job candidate briefly.

Name:
" . ($resume?->full_name ?? $student->user->name) . "

Professional Title:
" . ($resume?->professional_title ?? $student->headline) . "

Major:
{$student->major}

GPA:
{$student->gpa}

Summary:
" . ($resume?->summary ?? $student->bio) . "

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
            $aiSummary = 'AI summary unavailable';
        }

        $match = $this->getApplicantMatch($application, $matchingService);

        return response()->json([
            'application_id' => $application->id,

            'student' => [
                'name' => $resume?->full_name ?? $student->user->name,
                'email' => $student->user->email,
                'phone' => $student->phone,
                'avatar' => $student->avatar,
                'headline' => $resume?->professional_title ?? $student->headline,
                'university' => $student->university,
                'major' => $student->major,
                'gpa' => $student->gpa,
                'location' => $student->location,
                'bio' => $resume?->summary ?? $student->bio,
                'portfolio' => $student->portfolio,
                'linkedin' => $student->linkedin,
                'github' => $student->github,
            ],

            'skills' => $resume?->skills ?? $student->skills->pluck('name')->values(),

            'education' => $resume?->education ?? [],

            'experience' => $resume?->experience ?? [],

            'projects' => $resume?->projects ?? [],

            'certificates' => $resume?->certificates ?? [],

            'languages' => $resume?->languages ?? [],

            'resume' => $resume ? [
                'id' => $resume->id,
                'title' => $resume->title,
                'template' => $resume->template,
                'full_name' => $resume->full_name,
                'professional_title' => $resume->professional_title,
                'summary' => $resume->summary,
                'experience' => $resume->experience ?? [],
                'education' => $resume->education ?? [],
                'skills' => $resume->skills ?? [],
                'projects' => $resume->projects ?? [],
                'certificates' => $resume->certificates ?? [],
                'languages' => $resume->languages ?? [],
                'file_path' => $resume->file_path,
                'is_public' => $resume->is_public,
                'updated_at' => $resume->updated_at,
            ] : null,

            'match' => [
                'percentage' => $match['percentage'],
                'matching_skills' => $match['matching_skills'],
                'missing_skills' => $match['missing_skills'],
                'source' => $application->match_source,
                'analysis' => $application->match_analysis,
                'reasons' => $this->generateMatchReasons(
                    $application,
                    $match
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
            'requirements' => $job->requirements,
            'employment_type' => $job->employment_type,
            'work_mode' => $job->work_mode,
            'location' => $job->location,
            'salary' => $job->salary,
            'deadline' => $job->deadline,
            'vacancies' => $job->vacancies,
            'required_major' => $job->required_major,
            'status' => $job->status,
            'quality_score' => $job->quality_score,
            'moderation_issues' => $job->moderation_issues ?? [],
            'moderation_recommendation' => $job->moderation_recommendation,
            'moderation_note' => $job->moderation_note,
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
                        'match' => (int) ($matchData['match'] ?? 0),
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
            'quality_score' => $job->quality_score,
            'moderation_issues' => $job->moderation_issues ?? [],
            'moderation_recommendation' => $job->moderation_recommendation,
            'moderation_note' => $job->moderation_note,
            'skills' => $job->skills
                ->pluck('name')
                ->values(),
        ]);
    }

    public function updateJob(Request $request, $id, AutomaticJobValidationService $validationService)
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
            'requirements' => 'nullable|string',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:255',
            'benefits' => 'nullable|array',
            'benefits.*' => 'string|max:255',
            'deadline' => 'nullable|date',
            'vacancies' => 'nullable|integer|min:1',
            'required_major' => 'nullable|string|max:255',
            'status' => 'nullable|in:Open,Closed,Draft,Pending Review,Rejected,Changes Requested,Suspended',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        $requestedStatus = $validated['status'] ?? null;
        $shouldSkipValidation = in_array($requestedStatus, ['Closed', 'Draft'], true);

        $job->update([
            'title' => $validated['title'],
            'department' => $validated['department'] ?? null,
            'employment_type' => $validated['employment_type'],
            'level' => $validated['level'] ?? null,
            'work_mode' => $validated['work_mode'],
            'location' => $validated['location'] ?? null,
            'salary' => $validated['salary'] ?? null,
            'description' => $validated['description'] ?? null,
            'requirements' => $validated['requirements'] ?? null,
            'benefits' => $validated['benefits'] ?? [],
            'deadline' => $validated['deadline'] ?? null,
            'vacancies' => $validated['vacancies'] ?? 1,
            'required_major' => $validated['required_major'] ?? null,
            'status' => $shouldSkipValidation ? $requestedStatus : 'Pending Review',
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

        if ($shouldSkipValidation) {
            return response()->json([
                'message' => 'Job updated successfully',
                'job' => $job->fresh('skills')
            ]);
        }

        $validation = $validationService->apply($job->fresh('skills'));

        return response()->json([
            'message' => $validation['status'] === 'Open'
                ? 'Job updated, passed automatic validation, and was published successfully.'
                : 'Job updated and sent to admin moderation.',
            'quality_score' => $validation['quality_score'],
            'moderation_issues' => $validation['issues'],
            'moderation_recommendation' => $validation['recommendation'],
            'job' => $job->fresh('skills')
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
                $resume = $application->resume;

                return [
                    'id' => $application->id,
                    'status' => $application->status,

                    'student' => [
                        'name' => $resume?->full_name ?? $student->user->name ?? '',
                        'email' => $student->user->email ?? '',
                        'phone' => $student->phone,
                        'avatar' => $student->avatar,
                        'headline' => $resume?->professional_title ?? $student->headline,
                        'university' => $student->university,
                        'major' => $student->major,
                        'gpa' => $student->gpa,
                        'location' => $student->location,
                        'bio' => $resume?->summary ?? $student->bio,
                        'portfolio' => $student->portfolio,
                        'linkedin' => $student->linkedin,
                        'github' => $student->github,
                    ],

                    'skills' => $resume?->skills ?? $student->skills->pluck('name')->values(),

                    'education' => $resume?->education ?? [],

                    'experience' => $resume?->experience ?? [],

                    'projects' => $resume?->projects ?? [],

                    'certificates' => $resume?->certificates ?? [],

                    'languages' => $resume?->languages ?? [],

                    'resume' => $resume ? [
                        'id' => $resume->id,
                        'title' => $resume->title,
                        'template' => $resume->template,
                        'full_name' => $resume->full_name,
                        'professional_title' => $resume->professional_title,
                        'summary' => $resume->summary,
                        'experience' => $resume->experience ?? [],
                        'education' => $resume->education ?? [],
                        'skills' => $resume->skills ?? [],
                        'projects' => $resume->projects ?? [],
                        'certificates' => $resume->certificates ?? [],
                        'languages' => $resume->languages ?? [],
                        'file_path' => $resume->file_path,
                        'is_public' => $resume->is_public,
                        'updated_at' => $resume->updated_at,
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
