<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\JobPost;
use App\Models\Skill;
use App\Models\Application;
use Illuminate\Support\Facades\Validator;
use App\Services\GeminiService;

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

    public function applicants(Request $request)
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
            'jobPost'
        ])
            ->whereHas('jobPost', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->latest()
            ->get();

        $applicants = $applications->map(function ($application) {

            return [

                'id' => $application->id,

                'name' => $application->student->user->name,

                'title' => $application->student->headline,

                'university' => $application->student->university,

                'location' => $application->student->location,

                'avatar' => $application->student->avatar,

                'job_id' => $application->jobPost->id,

                'job' => $application->jobPost->title,

                'status' => $application->status,

                'match' => (int) $application->match_score,

                'skills' => $application->student->skills
                    ->pluck('name')
                    ->values(),

                'email' => $application->student->user->email,

                'applied_at' => optional($application->applied_at)
                    ->format('Y-m-d'),

            ];
        });

        return response()->json($applicants);
    }

    public function applicantDetails(Request $request, $id)
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
            'timeline'
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
                'percentage' => $application->match_score,
                'reasons' => $this->generateMatchReasons($application)
            ],
            'notes' => $application->notes,
            'timeline' => $application->timeline,
        ]);
    }

    private function generateMatchReasons($application)
    {
        $reasons = [];
        if ($application->match_score >= 80) {
            $reasons[] = "Strong skills match";
        }

        if (
            $application->student->major ==
            $application->jobPost->required_major
        ) {
            $reasons[] = "Major matches job requirements";
        }
        if (
            $application->student->location ==
            $application->jobPost->location
        ) {
            $reasons[] = "Same location";
        }
        if (
            $application->student->preferred_employment_type ==
            $application->jobPost->employment_type
        ) {
            $reasons[] = "Employment type matches";
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

    public function fullApplicantDetails(Request $request, $id, GeminiService $gemini)
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

                'percentage' => $application->match_score,

                'reasons' => $this->generateMatchReasons($application)

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

    public function jobDetails(Request $request, $id)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }

        $job = JobPost::with([
            'skills',
            'applications.student.user'
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
                    ->where('status', 'Hired')
                    ->count(),
            ],

            'recent_applicants' => $applications
                ->sortByDesc('applied_at')
                ->take(5)
                ->values()
                ->map(function ($application) {

                    return [
                        'application_id' => $application->id,
                        'name' => $application->student->user->name,
                        'headline' => $application->student->headline,
                        'avatar' => $application->student->avatar,
                        'match' => (int) $application->match_score,
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
}