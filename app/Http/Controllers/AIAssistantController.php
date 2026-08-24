<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GroqService;
use App\Services\LocalCVAnalyzerService;
use App\Services\InterviewQuestionGenerationService;
use App\Services\InterviewQuizAttemptService;
use App\Models\InterviewQuizAttempt;
use App\Models\Resume;
use App\Models\SavedJob;
use Illuminate\Support\Facades\Auth;
use App\Services\JobMatchingService;

class AIAssistantController extends Controller
{
   public function reviewCV(Request $request, LocalCVAnalyzerService $cvAnalyzer)
    {
        $student = Auth::user()->student;

        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        $resume = Resume::where('student_id', $student->id)->latest()->first();

        if (!$resume) {
            return response()->json(['message' => 'Please create a resume first'], 422);
        }

        try {
            return response()->json($cvAnalyzer->reviewAndStore($resume));

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 503);
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

    public function generateInterviewQuestions(Request $request, InterviewQuizAttemptService $attemptService)
    {
        $request->validate([
            'job_id' => 'required|integer|exists:job_posts,id'
        ]);

        $job = \App\Models\JobPost::with('skills')->findOrFail($request->job_id);
        $student = Auth::user()?->student;

        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        try {
            $result = $attemptService->start($job, $student);

            return response()->json([
                'attempt_id' => $result['attempt_id'],
                'job_id' => $job->id,
                'job_title' => $job->title,
                'questions' => $result['questions'],
                'status' => $result['status'],
                'started_at' => $result['started_at'],
                'metadata' => $result['metadata'],
            ]);

        } catch (\Exception $e) {
            $status = $e->getMessage() === 'Please save this job before starting the interview quiz.' ? 403 : 503;

            return response()->json([
                'message' => $e->getMessage()
            ], $status);
        }
    }

    public function retakeInterviewQuiz(Request $request, InterviewQuizAttemptService $attemptService)
    {
        $request->validate([
            'job_id' => 'required|integer|exists:job_posts,id'
        ]);

        $job = \App\Models\JobPost::with('skills')->findOrFail($request->job_id);
        $student = Auth::user()?->student;

        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        try {
            $result = $attemptService->retake($job, $student);

            return response()->json([
                'attempt_id' => $result['attempt_id'],
                'job_id' => $job->id,
                'job_title' => $job->title,
                'questions' => $result['questions'],
                'status' => $result['status'],
                'started_at' => $result['started_at'],
                'metadata' => $result['metadata'],
            ]);
        } catch (\Exception $e) {
            $status = $e->getMessage() === 'Please save this job before starting the interview quiz.' ? 403 : 503;

            return response()->json([
                'message' => $e->getMessage()
            ], $status);
        }
    }

    public function submitInterviewAnswers(Request $request, InterviewQuizAttemptService $attemptService)
    {
        $request->validate([
            'attempt_id' => 'required|integer|exists:interview_quiz_attempts,id',
            'answers' => 'required|array',
        ]);

        $student = Auth::user()?->student;

        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        try {
            $attempt = InterviewQuizAttempt::findOrFail($request->attempt_id);

            return response()->json($attemptService->submit($attempt, $student, $request->answers));
        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'not allowed') ? 403 : 422;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    public function interviewQuizAttempts(Request $request, InterviewQuizAttemptService $attemptService)
    {
        $request->validate([
            'job_id' => 'required|integer|exists:job_posts,id',
        ]);

        $job = \App\Models\JobPost::findOrFail($request->job_id);
        $student = Auth::user()?->student;

        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        try {
            return response()->json([
                'job_id' => $job->id,
                'attempts' => $attemptService->history($job, $student),
            ]);
        } catch (\Exception $e) {
            $status = $e->getMessage() === 'Please save this job before starting the interview quiz.' ? 403 : 422;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        }
    }
}
