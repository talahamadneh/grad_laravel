<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use App\Models\Resume;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Barryvdh\DomPDF\Facade\Pdf;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\IOFactory;

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
                'experience' => $student->experience,
                'education' => $student->education,
                'projects' => [],
                'languages' => [],
                'certificates' => [],

                'template' => 'executive',
                'title' => 'My Resume',
                'file_path' => null,
                'file_url' => null,
                'is_public' => false,
            ]);
        }

        return response()->json([
            ...$resume->toArray(),
            ...$profileData,

            'skills' => $resume->skills ?? $student->skills->pluck('name')->toArray(),
            'experience' => $resume->experience ?? $student->experience,
            'education' => $resume->education ?? $student->education,
            'projects' => $resume->projects ?? [],
            'languages' => $resume->languages ?? [],
            'certificates' => $resume->certificates ?? [],

            'file_path' => $resume->file_path,
            'file_url' => $resume->file_path
                ? Storage::url($resume->file_path)
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
            'education' => 'nullable|array',
            'skills' => 'nullable|array',
            'projects' => 'nullable|array',
            'languages' => 'nullable|array',
            'certificates' => 'nullable|array',
            'is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $resume = Resume::create([
            'student_id' => $student->id,
            'title' => $request->input('title', 'My Resume'),
            'template' => $request->template,
            'full_name' => $request->full_name,
            'professional_title' => $request->professional_title,
            'summary' => $request->summary,
            'experience' => $request->experience,
            'education' => $request->education,
            'skills' => $request->skills,
            'projects' => $request->projects,
            'languages' => $request->languages,
            'certificates' => $request->certificates,
            'is_public' => $request->input('is_public', false),
        ]);

        return response()->json([
            'message' => 'Resume created successfully',
            'resume' => $resume
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
            'education' => 'nullable|array',
            'skills' => 'nullable|array',
            'projects' => 'nullable|array',
            'languages' => 'nullable|array',
            'certificates' => 'nullable|array',
            'is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $resume->update($request->all());

        return response()->json([
            'message' => 'Resume updated successfully',
            'resume' => $resume
        ]);
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
                ->post('https://api.groq.com/openai/v1/chat/completions', [
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
                ]);

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

    public function uploadFile(Request $request)
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

            $filePath = $file->store(
                'resumes',
                'public'
            );

            if ($extension === 'pdf') {
                $parser = new Parser();

                $pdf = $parser->parseFile(
                    $file->getRealPath()
                );

                $text = trim($pdf->getText());
            } else {
                $text = $this->extractDocxText($file->getRealPath());
            }

            if (!$text) {
                Storage::disk('public')->delete($filePath);

                return response()->json([
                    'message' => 'Could not extract text from this file.'
                ], 422);
            }

            $prompt = <<<PROMPT
Extract structured resume information from the following resume text.

Return ONLY valid JSON.

Do not invent information.
If a field is missing, return an empty string or empty array.

Use exactly this structure:

{
    "full_name": "",
    "professional_title": "",
    "summary": "",
    "email": "",
    "phone": "",
    "location": "",
    "linkedin": "",
    "github": "",
    "portfolio": "",
    "education": [],
    "skills": [],
    "experience": [],
    "projects": [],
    "certificates": [],
    "languages": []
}

Education items must use:

{
    "degree": "",
    "university": "",
    "field_of_study": "",
    "start_date": "",
    "end_date": ""
}

Skill items must use:

{
    "name": ""
}

Experience items must use:

{
    "title": "",
    "company": "",
    "start_date": "",
    "end_date": "",
    "description": ""
}

Project items must use:

{
    "name": "",
    "link": "",
    "description": ""
}

Certificate items must use:

{
    "name": "",
    "issuer": "",
    "year": ""
}

Language items must use:

{
    "language": "",
    "level": ""
}

Resume text:

{$text}
PROMPT;

            $response = Http::withToken(
                config('services.groq.keys.0')
            )
            ->acceptJson()
            ->timeout(90)
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
                            'content' => 'You extract resume information into valid JSON. Never invent information.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],

                    'temperature' => 0.1,
                    'stream' => false,
                    'response_format' => ['type' => 'json_object'],
                ]
            );

            if (!$response->successful()) {
                Storage::disk('public')->delete($filePath);

                \Log::error('Groq Resume Parsing Error', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return response()->json([
                    'message' => 'Failed to analyze the uploaded CV.'
                ], 500);
            }

            $content = data_get(
                $response->json(),
                'choices.0.message.content'
            );

            if (!$content) {
                Storage::disk('public')->delete($filePath);

                return response()->json([
                    'message' => 'AI returned an empty response.'
                ], 500);
            }

            $content = trim($content);

            $content = preg_replace(
                '/^```json\s*|\s*```$/i',
                '',
                $content
            );

            \Log::info('Groq Resume Content', [
                'content' => $content
            ]);

            $parsedData = json_decode(
                trim($content),
                true
            );

            if (!is_array($parsedData)) {
                Storage::disk('public')->delete($filePath);

                \Log::error('Invalid Resume JSON', [
                    'content' => $content
                ]);

                return response()->json([
                    'message' => 'Could not understand the uploaded CV.'
                ], 422);
            }

            $resume = Resume::where(
                'student_id',
                $student->id
            )->first();

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
                    $parsedData['experience'] ?? [],

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

            if (empty($student->headline) && !empty($parsedData['professional_title'])) {
                $studentUpdates['headline'] = $parsedData['professional_title'];
            }

            if (empty($student->bio) && !empty($parsedData['summary'])) {
                $studentUpdates['bio'] = $parsedData['summary'];
            }

            if (!empty($studentUpdates)) {
                $student->update($studentUpdates);
                $student->refresh();
            }

            return response()->json([
                'message' => 'CV uploaded and analyzed successfully',
                'resume' => $resume,
                'file_path' => $filePath,
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
        $projects = $resume->projects ?? [];
        $languages = $resume->languages ?? [];
        $certificates = $resume->certificates ?? [];

        $data = [
            'resume' => $resume,
            'student' => $student,
            'user' => $request->user(),
            'avatar' => $student->avatar,
            'email' => $request->user()->email,
            'phone' => $student->phone,
            'location' => $student->location,
            'linkedin' => $student->linkedin,
            'github' => $student->github,
            'portfolio' => $student->portfolio,
            'gpa' => $student->gpa,
            'skills' => $skills,
            'education' => $education,
            'experience' => $experience,
            'projects' => $projects,
            'languages' => $languages,
            'certificates' => $certificates,
        ];

        $pdf = Pdf::loadView('resume.pdf', $data)
            ->setPaper('a4', 'portrait');

        return $pdf->download(
            str_replace(' ', '_', $resume->full_name) . '_resume.pdf'
        );
    }
}