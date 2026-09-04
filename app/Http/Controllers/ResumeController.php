<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use App\Services\AIResumeParserService;
use App\Models\Resume;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Barryvdh\DomPDF\Facade\Pdf;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\IOFactory;
use App\Services\ExperienceDurationCalculator;
use App\Services\StudentExperienceService;
use Illuminate\Support\Facades\DB;

class ResumeController extends Controller
{
    public function index(Request $request)
    {
        $student = $request->user()->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        $resume = Resume::where('student_id', $student->id)->first();
        $totalExperience = app(ExperienceDurationCalculator::class)->forStudent($student);

        $profileData = [
            'avatar' => $student->avatar,
            'email' => $request->user()->email,
            'phone' => $student->phone,
            'location' => $student->location,
            'linkedin' => $student->linkedin,
            'github' => $student->github,
            'portfolio' => $student->portfolio,
            'gpa' => $student->gpa,
            'headline' => $student->headline,
            'university' => $student->university,
            'major' => $student->major,
            'graduation_year' => $student->graduation_year,
        ];

        if (!$resume) {
            return response()->json([
                'id' => null,
                'full_name' => $request->user()->name,
                'professional_title' => $student->headline,
                'summary' => $student->bio,
                ...$profileData,
                'skills' => $student->skills->pluck('name')->toArray(),
                'experience' => app(StudentExperienceService::class)->forResume($student),
                'total_years_of_experience' => $totalExperience,
                'total_years_experience' => $totalExperience,
                'education' => $student->education,
                'projects' => [],
                'languages' => [],
                'certificates' => [],
                'activities' => [],
                'achievements' => [],
                'include_profile_photo' => true,
                'template' => 'executive',
                'title' => 'My Resume',
                'file_path' => null,
                'file_url' => null,
                'file_name' => null,
                'is_public' => false,
            ]);
        }

        return response()->json([
            ...$resume->toArray(),
            ...$profileData,
            'skills' => $resume->skills ?? $student->skills->pluck('name')->toArray(),
            'experience' => app(StudentExperienceService::class)->forResume($student),
            'total_years_of_experience' => $totalExperience,
            'total_years_experience' => $totalExperience,
            'education' => $resume->education ?? $student->education,
            'projects' => $resume->projects ?? [],
            'languages' => $resume->languages ?? [],
            'certificates' => $resume->certificates ?? [],
            'file_path' => $resume->file_path,
            'file_url' => $resume->file_path
                ? Storage::disk('public')->url($resume->file_path)
                : null,
            'file_name' => $resume->file_path
                ? basename($resume->file_path)
                : null,
        ]);
    }

    public function store(Request $request)
    {
        $student = $request->user()->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'template' => 'required|in:executive,modern,minimal',
            'full_name' => 'required|string|max:255',
            'professional_title' => 'required|string|max:255',
            'summary' => 'nullable|string',
            'experience' => 'nullable|array',
            'experience.*.title' => 'nullable|string|max:255',
            'experience.*.company' => 'nullable|string|max:255',
            'experience.*.start_date' => 'nullable|string|max:50',
            'experience.*.end_date' => 'nullable|string|max:50',
            'experience.*.description' => 'nullable|string',
            'education' => 'nullable|array',
            'skills' => 'nullable|array',
            'projects' => 'nullable|array',
            'languages' => 'nullable|array',
            'certificates' => 'nullable|array',
            'activities' => 'nullable|array',
            'achievements' => 'nullable|array',
            'include_profile_photo' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $resume = DB::transaction(function () use ($request, $student) {
            app(StudentExperienceService::class)->sync(
                $student,
                $request->input('experience', [])
            );

            return Resume::create([
                'student_id' => $student->id,
                'title' => $request->input('title', 'My Resume'),
                'template' => $request->template,
                'full_name' => $request->full_name,
                'professional_title' => $request->professional_title,
                'summary' => $request->summary,
                'experience' => app(StudentExperienceService::class)->forResume($student),
                'education' => $request->education,
                'skills' => $request->skills,
                'projects' => $request->projects,
                'languages' => $request->languages,
                'certificates' => $request->certificates,
                'activities' => $request->activities,
                'achievements' => $request->achievements,
                'include_profile_photo' => $request->boolean('include_profile_photo', true),
                'is_public' => $request->input('is_public', false),
            ]);
        });

        return response()->json([
            'message' => 'Resume created successfully',
            'resume' => $this->resumeWithCalculatedExperience($resume, $student)
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $student = $request->user()->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        $resume = Resume::where('id', $id)
            ->where('student_id', $student->id)
            ->first();

        if (!$resume) {
            return response()->json([
                'message' => 'Resume not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'template' => 'sometimes|in:executive,modern,minimal',
            'full_name' => 'sometimes|string|max:255',
            'professional_title' => 'sometimes|string|max:255',
            'summary' => 'nullable|string',
            'experience' => 'nullable|array',
            'experience.*.title' => 'nullable|string|max:255',
            'experience.*.company' => 'nullable|string|max:255',
            'experience.*.start_date' => 'nullable|string|max:50',
            'experience.*.end_date' => 'nullable|string|max:50',
            'experience.*.description' => 'nullable|string',
            'education' => 'nullable|array',
            'skills' => 'nullable|array',
            'projects' => 'nullable|array',
            'languages' => 'nullable|array',
            'certificates' => 'nullable|array',
            'activities' => 'nullable|array',
            'achievements' => 'nullable|array',
            'include_profile_photo' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        DB::transaction(function () use ($request, $student, $resume) {
            if ($request->has('experience')) {
                app(StudentExperienceService::class)->sync(
                    $student,
                    $request->input('experience', [])
                );
            }

            $data = $request->only([
                'title', 'template', 'full_name', 'professional_title', 'summary',
                'education', 'skills', 'projects', 'languages', 'certificates',
                'activities', 'achievements', 'include_profile_photo', 'is_public',
            ]);

            $data['experience'] = app(StudentExperienceService::class)->forResume($student);
            $resume->update($data);
        });

        return response()->json([
            'message' => 'Resume updated successfully',
            'resume' => $this->resumeWithCalculatedExperience($resume->fresh(), $student)
        ]);
    }

    private function resumeWithCalculatedExperience(Resume $resume, $student): array
    {
        $years = app(ExperienceDurationCalculator::class)->forStudent($student);

        return [
            ...$resume->toArray(),
            'experience' => app(StudentExperienceService::class)->forResume($student),
            'total_years_of_experience' => $years,
            // Kept as a read-only compatibility alias for existing clients and matcher payloads.
            'total_years_experience' => $years,
        ];
    }

    public function destroy(Request $request, $id)
    {
        $student = $request->user()->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        $resume = Resume::where('id', $id)
            ->where('student_id', $student->id)
            ->first();

        if (!$resume) {
            return response()->json([
                'message' => 'Resume not found'
            ], 404);
        }

        if (
            $resume->file_path &&
            Storage::disk('public')->exists($resume->file_path)
        ) {
            Storage::disk('public')->delete($resume->file_path);
        }

        $resume->delete();

        return response()->json([
            'message' => 'Resume deleted successfully'
        ]);
    }

    public function aiImprove(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'text' => 'required|string|min:10|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $text = trim($request->text);

            $prompt = <<<PROMPT
Improve the following professional resume summary.

Requirements:
- Keep the original meaning and facts.
- Do not invent experience, skills, education, companies, achievements, or numbers.
- Make it professional and suitable for a modern resume.
- Make it concise and impactful.
- Use strong professional language.
- Focus on the candidate's value, skills, and career direction.
- Return ONLY the improved summary.
- Do not add quotation marks.
- Do not add explanations.
- Do not use bullet points.

Original summary:
{$text}
PROMPT;

            $response = Http::withToken(config('services.groq.keys.0'))
                ->acceptJson()
                ->timeout(60)
                ->post(
                    'https://api.groq.com/openai/v1/chat/completions',
                    [
                        'model' => config(
                            'services.groq.model',
                            'llama-3.3-70b-versatile'
                        ),
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'You are a professional resume writing assistant. Improve resume summaries while preserving the candidate\'s original facts. Never invent information.'
                            ],
                            [
                                'role' => 'user',
                                'content' => $prompt
                            ]
                        ],
                        'temperature' => 0.4,
                        'stream' => false,
                    ]
                );

            if (!$response->successful()) {
                \Log::error('Groq AI Error', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return response()->json([
                    'message' => 'Groq AI request failed.',
                    'details' => $response->json(),
                ], 500);
            }

            $improvedText = data_get(
                $response->json(),
                'choices.0.message.content'
            );

            if (!$improvedText) {
                \Log::error('Groq returned empty response', [
                    'response' => $response->json(),
                ]);

                return response()->json([
                    'message' => 'Groq returned an empty response.'
                ], 500);
            }

            $improvedText = trim($improvedText);
            $improvedText = trim($improvedText, "\"'");

            return response()->json([
                'improved_text' => $improvedText
            ]);

        } catch (\Throwable $e) {
            \Log::error('Groq AI Improve Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to improve resume summary.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function extractTextFromElement($element): string
    {
        $text = '';

        if (method_exists($element, 'getText')) {
            $value = $element->getText();

            if (is_string($value)) {
                $text .= $value . ' ';
            }
        }

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractTextFromElement($child);
            }
        }

        if (method_exists($element, 'getRows')) {
            foreach ($element->getRows() as $row) {
                if (method_exists($row, 'getCells')) {
                    foreach ($row->getCells() as $cell) {
                        $text .= $this->extractTextFromElement($cell);
                    }
                }
            }
        }

        return $text;
    }

    private function extractDocxText(string $path): string
    {
        $phpWord = IOFactory::load($path);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            $text .= $this->extractTextFromElement($section) . "\n";
        }

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    public function uploadFile(Request $request, AIResumeParserService $resumeParser)
    {
        $student = $request->user()->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:pdf,docx|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            $originalName = $file->getClientOriginalName();

            $safeFileName = preg_replace(
                '/[^\pL\pN\.\-_ ]/u',
                '',
                $originalName
            );

            $safeFileName = trim($safeFileName);

            if (!$safeFileName) {
                $safeFileName = 'resume.' . $extension;
            }

            $directory = 'resumes/' . $student->id;
            $filePath = $directory . '/' . $safeFileName;

            if (Storage::disk('public')->exists($filePath)) {
                Storage::disk('public')->delete($filePath);
            }

            $file->storeAs(
                $directory,
                $safeFileName,
                'public'
            );

            if ($extension === 'pdf') {
                $parser = new Parser();

                $pdf = $parser->parseFile(
                    $file->getRealPath()
                );

                $text = trim($pdf->getText());
            } else {
                $text = $this->extractDocxText(
                    $file->getRealPath()
                );
            }

            if (!$text) {
                Storage::disk('public')->delete($filePath);

                return response()->json([
                    'message' => 'Could not extract text from this file.'
                ], 422);
            }

            try {
                $parsedData = $resumeParser->parse($text);
            } catch (\UnexpectedValueException $exception) {
                Storage::disk('public')->delete($filePath);

                \Log::warning('Resume upload AI parsing returned malformed JSON', [
                    'characters' => mb_strlen($text),
                ]);

                return response()->json([
                    'message' => 'Could not understand the uploaded CV.'
                ], 422);
            } catch (\RuntimeException $exception) {
                Storage::disk('public')->delete($filePath);

                \Log::warning('Resume upload AI parsing unavailable', [
                    'characters' => mb_strlen($text),
                ]);

                return response()->json([
                    'message' => 'Resume parsing service unavailable'
                ], 503);
            }

            $resume = Resume::where(
                'student_id',
                $student->id
            )->first();

            app(StudentExperienceService::class)->sync(
                $student,
                $parsedData['experience'] ?? []
            );

            $resumeData = [
                'student_id' => $student->id,
                'title' => 'My Resume',
                'template' => 'executive',

                'full_name' => $parsedData['full_name']
                    ?? $request->user()->name,

                'professional_title' =>
                    $parsedData['professional_title']
                    ?? $student->headline,

                'summary' =>
                    $parsedData['summary']
                    ?? $student->bio,

                'education' =>
                    $parsedData['education'] ?? [],

                'skills' =>
                    $parsedData['skills'] ?? [],

                'experience' =>
                    app(StudentExperienceService::class)->forResume($student),

                'total_years_experience' => null,

                'projects' =>
                    $parsedData['projects'] ?? [],

                'certificates' =>
                    $parsedData['certificates'] ?? [],

                'languages' =>
                    $parsedData['languages'] ?? [],

                'is_public' => false,

                'file_path' => $filePath,
            ];

            if ($resume) {
                $oldFilePath = $resume->file_path;

                $resume->update($resumeData);

                if (
                    $oldFilePath &&
                    $oldFilePath !== $filePath &&
                    Storage::disk('public')->exists($oldFilePath)
                ) {
                    Storage::disk('public')->delete($oldFilePath);
                }
            } else {
                $resume = Resume::create($resumeData);
            }

            $studentUpdates = [];

            if (empty($student->phone) && !empty($parsedData['phone'])) {
                $studentUpdates['phone'] = $parsedData['phone'];
            }

            if (empty($student->location) && !empty($parsedData['location'])) {
                $studentUpdates['location'] = $parsedData['location'];
            }

            if (empty($student->linkedin) && !empty($parsedData['linkedin'])) {
                $studentUpdates['linkedin'] = $parsedData['linkedin'];
            }

            if (empty($student->github) && !empty($parsedData['github'])) {
                $studentUpdates['github'] = $parsedData['github'];
            }

            if (empty($student->portfolio) && !empty($parsedData['portfolio'])) {
                $studentUpdates['portfolio'] = $parsedData['portfolio'];
            }

            if (
                empty($student->headline) &&
                !empty($parsedData['professional_title'])
            ) {
                $studentUpdates['headline'] =
                    $parsedData['professional_title'];
            }

            if (
                empty($student->bio) &&
                !empty($parsedData['summary'])
            ) {
                $studentUpdates['bio'] =
                    $parsedData['summary'];
            }

            if (!empty($studentUpdates)) {
                $student->update($studentUpdates);
                $student->refresh();
            }

            return response()->json([
                'message' => 'CV uploaded and analyzed successfully',
                'resume' => $this->resumeWithCalculatedExperience($resume, $student),
                'file_path' => $filePath,
                'file_name' => $safeFileName,
                'file_url' => Storage::disk('public')->url(
                    $filePath
                ),
            ]);

        } catch (\Throwable $e) {
            \Log::error('Resume Upload Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to process the uploaded CV.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function generatePdf(Request $request, $id)
    {
        $student = $request->user()->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        $resume = Resume::where('id', $id)
            ->where('student_id', $student->id)
            ->first();

        if (!$resume) {
            return response()->json([
                'message' => 'Resume not found'
            ], 404);
        }

        $skills = $resume->skills ?? [];
        $education = $resume->education ?? [];
        $experience = $resume->experience ?? [];
        if ($experience === []) {
            $experience = app(StudentExperienceService::class)->forResume($student);
        }
        $projects = $resume->projects ?? [];
        $languages = $resume->languages ?? [];
        $certificates = $resume->certificates ?? [];

        $calculatedExperience = app(ExperienceDurationCalculator::class)->fromExperiences($experience);
        $resume->setAttribute('total_years_of_experience', $calculatedExperience);
        $totalExperience = $resume->total_years_of_experience
            ?? $resume->total_years_experience
            ?? 0;
        $includeProfilePhoto = (bool) $resume->include_profile_photo;

        $data = [
            'resume' => $resume,
            'student' => $student,
            'user' => $request->user(),
            'avatar' => $this->pdfAvatar($student->avatar, $includeProfilePhoto),
            'includeProfilePhoto' => $includeProfilePhoto,
            'email' => $request->user()->email,
            'phone' => $student->phone,
            'location' => $student->location,
            'linkedin' => $student->linkedin,
            'github' => $student->github,
            'portfolio' => $student->portfolio,
            'gpa' => $student->gpa,
            'skills' => $skills,
            'skillGroups' => $this->groupSkills($skills),
            'education' => $education,
            'experience' => $experience,
            'projects' => $projects,
            'languages' => $languages,
            'certificates' => $certificates,
            'activities' => $resume->activities ?? [],
            'achievements' => $resume->achievements ?? [],
            'totalExperience' => $totalExperience,
        ];

        $pdf = Pdf::loadView('resume.pdf', $data)
            ->setOption('isRemoteEnabled', true)
            ->setPaper('a4', 'portrait');

        $downloadName = trim((string) ($resume->full_name ?: $request->user()->name));
        $downloadName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $downloadName) ?: 'resume';

        return $pdf->download($downloadName . '_resume.pdf');
    }

    private function groupSkills(array $skills): array
    {
        $groups = [];

        foreach ($skills as $skill) {
            $name = trim((string) (is_array($skill) ? ($skill['name'] ?? '') : $skill));
            if ($name === '') {
                continue;
            }

            $category = is_array($skill)
                ? trim((string) ($skill['category'] ?? $skill['category_name'] ?? ''))
                : '';

            if ($category === '') {
                $category = $this->inferSkillCategory($name);
            }

            $groups[$category] ??= [];
            if (!in_array($name, $groups[$category], true)) {
                $groups[$category][] = $name;
            }
        }

        return $groups;
    }

    private function inferSkillCategory(string $skill): string
    {
        $normalized = strtolower(trim($skill));

        $programming = ['java', 'javascript', 'typescript', 'python', 'php', 'c', 'c++', 'c#', 'sql', 'html', 'css', 'dart', 'kotlin', 'swift'];
        $frameworks = ['react', 'angular', 'vue', 'laravel', 'django', 'fastapi', 'node.js', 'node', 'flutter', 'spring', 'bootstrap', 'tailwind'];
        $tools = ['git', 'github', 'gitlab', 'docker', 'postman', 'figma', 'jira', 'linux', 'aws', 'azure', 'kubernetes'];

        if (in_array($normalized, $programming, true)) {
            return 'Programming Languages';
        }
        if (in_array($normalized, $frameworks, true)) {
            return 'Frameworks & Libraries';
        }
        if (in_array($normalized, $tools, true)) {
            return 'Tools';
        }

        return 'Other Skills';
    }

    private function pdfAvatar(?string $avatar, bool $include): ?string
    {
        if (!$include || !$avatar) {
            return null;
        }

        if (str_starts_with($avatar, 'data:') || filter_var($avatar, FILTER_VALIDATE_URL)) {
            return $avatar;
        }

        $relative = ltrim(preg_replace('#^/?storage/#', '', $avatar), '/');
        $path = Storage::disk('public')->path($relative);

        if (!is_file($path)) {
            $path = public_path(ltrim($avatar, '/'));
        }

        if (!is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/jpeg';

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }
}
