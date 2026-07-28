<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\JobPost;
use App\Models\Skill;
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
            ->map(function ($job) {
                return [
                    'id' => $job->id,
                    'title' => $job->title,
                    'location' => $job->location,
                    'type' => $job->employment_type,
                    'mode' => $job->work_mode,
                    'status' => $job->status,
                    'applicants' => $job->applications_count,
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
}