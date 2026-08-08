<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GroqService;
use App\Models\Resume;
use App\Models\ResumeAnalysis;
use Illuminate\Support\Facades\Auth;
use App\Services\JobMatchingService;

class AIAssistantController extends Controller
{
   public function reviewCV(Request $request, GroqService $groq)
    {
        $student = Auth::user()->student;

        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        $resume = Resume::where('student_id', $student->id)->latest()->first();

        if (!$resume) {
            return response()->json(['message' => 'Please create a resume first'], 422);
        }

        $prompt = "You are an expert career coach and resume reviewer. Review the following resume and provide feedback in this EXACT JSON format (no markdown, no extra text):
{
  \"overall_score\": <number 0-100>,
  \"strengths\": [\"point 1\", \"point 2\", ...],
  \"weaknesses\": [\"point 1\", \"point 2\", ...],
  \"suggestions\": [\"specific actionable suggestion 1\", \"suggestion 2\", ...]
}

Resume Data:
Full Name: {$resume->full_name}
Professional Title: {$resume->professional_title}
Summary: {$resume->summary}
Skills: " . json_encode($resume->skills) . "
Experience: " . json_encode($resume->experience) . "
Education: " . json_encode($resume->education) . "
Do not penalize students heavily for lacking professional experience. Evaluate based on the quality and completeness of a student resume rather than years of work experience.
"
;

        try {
            $result = $groq->generate($prompt);

            $cleaned = preg_replace('/```json|```/', '', $result);
            $parsed = json_decode(trim($cleaned), true);

            if (!$parsed) {
                return response()->json([
                    'message' => 'Could not parse AI response',
                    'raw' => $result
                ], 500);
            }

            ResumeAnalysis::updateOrCreate(
                [
                    'resume_id' => $resume->id
                ],
                [
                    'cv_score' => $parsed['overall_score'] ?? null,
                    'strengths' => $parsed['strengths'] ?? [],
                    'weaknesses' => $parsed['weaknesses'] ?? [],
                    'recommendations' => $parsed['suggestions'] ?? [],
                ]
            );

            return response()->json($parsed);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'AI service error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function aiJobRecommendations(Request $request, JobMatchingService $matchingService, GroqService $groq)
    {
        $student = Auth::user()->student;

        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        $topJobs = $matchingService->getRecommendedJobs($student)->take(5)->values();

        if ($topJobs->isEmpty()) {
            return response()->json([
                'message' => 'No open jobs available for matching right now'
            ], 404);
        }

        
        $studentSkills = $student->skills->pluck('name')->implode(', ');

        $jobsSummary = $topJobs->map(function ($job) {
            return "Job ID: {$job['job_id']}, Title: {$job['title']}, Company: {$job['company']}, "
                . "Match: {$job['match']}%, Matching Skills: " . implode(', ', $job['matching_skills'])
                . ", Missing Skills: " . implode(', ', $job['missing_skills']);
        })->implode("\n");

        $prompt = "You are a career advisor AI. A student has the following profile:
Major: {$student->major}
Location: {$student->location}
Skills: {$studentSkills}

Here are their top matching job opportunities (already ranked by a matching algorithm):
{$jobsSummary}

For EACH job, write a short, natural, personalized explanation (2-3 sentences) of why it's a good fit for this student, and ONE specific tip to improve their chances (e.g. a skill to learn).

Respond ONLY in this exact JSON format (no markdown, no extra text):
{
  \"recommendations\": [
    {
      \"job_id\": <number>,
      \"why_it_fits\": \"<personalized explanation>\",
      \"tip\": \"<one specific actionable tip>\"
    }
  ]
}";

        try {
            $result = $groq->generate($prompt);
            $cleaned = preg_replace('/```json|```/', '', $result);
            $parsed = json_decode(trim($cleaned), true);

            if (!$parsed || !isset($parsed['recommendations'])) {
                return response()->json([
                    'message' => 'Could not parse AI response',
                    'raw' => $result
                ], 500);
            }


            $aiMap = collect($parsed['recommendations'])->keyBy('job_id');

            $finalResult = $topJobs->map(function ($job) use ($aiMap) {
                $ai = $aiMap->get($job['job_id']);

                return array_merge($job, [
                    'why_it_fits' => $ai['why_it_fits'] ?? null,
                    'tip' => $ai['tip'] ?? null,
                ]);
            });

            return response()->json($finalResult);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'AI service error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function generateInterviewQuestions(Request $request, GroqService $groq)
    {
        $request->validate([
            'job_id' => 'required|integer|exists:job_posts,id'
        ]);

        $job = \App\Models\JobPost::with('skills')->findOrFail($request->job_id);
        $skills = $job->skills->pluck('name')->implode(', ');

        $prompt = "You are an expert technical interviewer. Generate exactly 20 multiple-choice interview questions for the role of \"{$job->title}\" requiring these skills: {$skills}.

Mix technical and behavioral/situational questions relevant to the role.

Respond ONLY in this EXACT JSON format (no markdown, no extra text):
{
  \"questions\": [
    {
      \"id\": 1,
      \"question\": \"<question text>\",
      \"options\": {
        \"A\": \"<option text>\",
        \"B\": \"<option text>\",
        \"C\": \"<option text>\",
        \"D\": \"<option text>\"
      },
      \"correct_answer\": \"A\"
    }
  ]
}";

        try {
            $result = $groq->generate($prompt);
            $cleaned = preg_replace('/```json|```/', '', $result);
            $parsed = json_decode(trim($cleaned), true);

            if (!$parsed || !isset($parsed['questions'])) {
                return response()->json([
                    'message' => 'Could not parse AI response',
                    'raw' => $result
                ], 500);
            }

            return response()->json([
                'job_id' => $job->id,
                'job_title' => $job->title,
                'questions' => $parsed['questions'],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'AI service error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function submitInterviewAnswers(Request $request, GroqService $groq)
    {
        $request->validate([
            'job_title' => 'required|string',
            'questions' => 'required|array|min:1',
            'questions.*.id' => 'required',
            'questions.*.question' => 'required|string',
            'questions.*.options' => 'required|array',
            'questions.*.correct_answer' => 'required|string',
            'answers' => 'required|array',
        ]);

        $questions = $request->questions;
        $studentAnswers = $request->answers; 

        $correctCount = 0;
        $wrongQuestions = [];
        $results = [];

        foreach ($questions as $q) {
            $qId = (string) $q['id'];
            $studentAnswer = $studentAnswers[$qId] ?? null;
            $isCorrect = $studentAnswer === $q['correct_answer'];

            if ($isCorrect) {
                $correctCount++;
            } else {
                $wrongQuestions[] = $q + ['student_answer' => $studentAnswer];
            }

            $results[] = [
                'id' => $q['id'],
                'question' => $q['question'],
                'student_answer' => $studentAnswer,
                'correct_answer' => $q['correct_answer'],
                'is_correct' => $isCorrect,
            ];
        }

        $total = count($questions);
        $score = round(($correctCount / $total) * 100);

        $explanations = [];

        
        if (!empty($wrongQuestions)) {
            $wrongSummary = collect($wrongQuestions)->map(function ($q) {
                $options = collect($q['options'])->map(fn($v, $k) => "{$k}) {$v}")->implode(', ');
                return "Question: {$q['question']}\nOptions: {$options}\nStudent chose: {$q['student_answer']}\nCorrect answer: {$q['correct_answer']}";
            })->implode("\n\n");

            $prompt = "For each of the following interview questions the student answered incorrectly, explain briefly (1-2 sentences) why the correct answer is right and why the student's choice was wrong.

{$wrongSummary}

Respond ONLY in this EXACT JSON format (no markdown, no extra text):
{
  \"explanations\": [
    {\"question\": \"<question text>\", \"explanation\": \"<why correct answer is right and student's choice was wrong>\"}
  ]
}";

            try {
                $result = $groq->generate($prompt);
                $cleaned = preg_replace('/```json|```/', '', $result);
                $parsed = json_decode(trim($cleaned), true);
                $explanations = $parsed['explanations'] ?? [];
            } catch (\Exception $e) {
      
                $explanations = [];
            }
        }

        return response()->json([
            'score' => $score,
            'correct_count' => $correctCount,
            'total_questions' => $total,
            'results' => $results,
            'explanations' => $explanations,
        ]);
    }
}