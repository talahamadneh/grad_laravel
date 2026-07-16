<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Resume;
use Illuminate\Support\Facades\Validator;
use Barryvdh\DomPDF\Facade\Pdf;

class ResumeController extends Controller
{
    public function index(Request $request)
    {
        $student = $request->user()->student;

        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
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
        ]);
    }

    public function store(Request $request)
    {
        $student = $request->user()->student;

        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
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
            return response()->json(['errors' => $validator->errors()], 422);
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
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        $resume = Resume::where('id', $id)
            ->where('student_id', $student->id)
            ->first();

        if (!$resume) {
            return response()->json(['message' => 'Resume not found'], 404);
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
            return response()->json(['errors' => $validator->errors()], 422);
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
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        $resume = Resume::where('id', $id)
            ->where('student_id', $student->id)
            ->first();

        if (!$resume) {
            return response()->json(['message' => 'Resume not found'], 404);
        }

        $resume->delete();

        return response()->json(['message' => 'Resume deleted successfully']);
    }

    public function aiImprove(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'text' => 'required|string|min:10'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $text = $request->text;
        $improvedText = $this->simulateAI($text);

        return response()->json([
            'improved_text' => $improvedText
        ]);
    }

    private function simulateAI($text)
    {
        $prefixes = [
            "Results-driven ",
            "Passionate ",
            "Experienced ",
            "Dedicated ",
            "Innovative "
        ];

        $suffixes = [
            " with a proven track record of success.",
            " committed to delivering excellence.",
            " passionate about creating impact.",
            " with expertise in modern technologies.",
            " dedicated to continuous improvement."
        ];

        $prefix = $prefixes[array_rand($prefixes)];
        $suffix = $suffixes[array_rand($suffixes)];

        $text = preg_replace('/^(Results-driven |Passionate |Experienced |Dedicated |Innovative )/', '', $text);
        $text = preg_replace('/ with a proven track record of success\.| committed to delivering excellence\.| passionate about creating impact\.| with expertise in modern technologies\.| dedicated to continuous improvement\.$/', '', $text);

        return $prefix . trim($text) . $suffix;
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