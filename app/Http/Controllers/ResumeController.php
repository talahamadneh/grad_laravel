<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Resume;
use Illuminate\Support\Facades\Validator;

class ResumeController extends Controller
{
    /**
     * جلب السيرة الذاتية للطالب
     */
    public function index(Request $request)
    {
        $student = $request->user()->student;
        
        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        $resume = Resume::where('student_id', $student->id)->first();

        if (!$resume) {
            // إرجاع بيانات فارغة مع البيانات الأساسية من الـ Profile
            return response()->json([
                'id' => null,
                'full_name' => $request->user()->name,
                'professional_title' => $student->headline,
                'summary' => $student->bio,
                'skills' => $student->skills->pluck('name')->toArray(),
                'experience' => $student->experience,
                'education' => $student->education,
                'template' => 'executive',
                'title' => 'My Resume',
                'is_public' => false,
            ]);
        }

        return response()->json($resume);
    }

    /**
     * إنشاء سيرة ذاتية جديدة
     */
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
            'is_public' => $request->input('is_public', false),
        ]);

        return response()->json([
            'message' => 'Resume created successfully',
            'resume' => $resume
        ], 201);
    }

    /**
     * تحديث السيرة الذاتية
     */
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

    /**
     * حذف السيرة الذاتية
     */
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

    /**
     * AI Improve - تحسين النص
     */
    public function aiImprove(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'text' => 'required|string|min:10'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $text = $request->text;

        // TODO: ربط مع OpenAI أو Gemini API
        // حالياً نرجع نفس النص مع إضافة مؤقتة
        $improvedText = $this->simulateAI($text);

        return response()->json([
            'improved_text' => $improvedText
        ]);
    }

    /**
     * محاكاة تحسين النص (بدون AI حقيقي)
     */
    private function simulateAI($text)
    {
        // إضافة بعض الكلمات لتحسين النص
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

        // إزالة الكلمات المكررة
        $text = preg_replace('/^(Results-driven |Passionate |Experienced |Dedicated |Innovative )/', '', $text);
        $text = preg_replace('/ with a proven track record of success\.| committed to delivering excellence\.| passionate about creating impact\.| with expertise in modern technologies\.| dedicated to continuous improvement\.$/', '', $text);

        return $prefix . trim($text) . $suffix;
    }

    /**
     * توليد PDF (سنعملها لاحقاً)
     */
    public function generatePdf(Request $request, $id)
    {
        // TODO: استخدام dompdf
        return response()->json([
            'message' => 'PDF generation coming soon'
        ]);
    }
}