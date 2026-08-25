<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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

    public function aiJobRecommendations(Request $request, JobMatchingService $matchingService)
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

        $finalResult = $topJobs->map(function ($job) {
            return array_merge($job, [
                'why_it_fits' => $this->localWhyItFits($job),
                'tip' => $this->localRecommendationTip($job),
            ]);
        });

        return response()->json($finalResult);
    }

    private function localWhyItFits(array $job): string
    {
        $parts = [];
        $matched = count($job['matching_skills'] ?? []);
        $missing = count($job['missing_skills'] ?? []);
        $total = $matched + $missing;

        if ($total > 0) {
            $parts[] = "You match {$matched} of {$total} required skills";
        }

        foreach (($job['reasons'] ?? []) as $reason) {
            $parts[] = lcfirst(rtrim($reason, '.'));
        }

        if (empty($parts)) {
            return "This role is ranked from your local profile and job compatibility score.";
        }

        return ucfirst(implode(', ', array_slice($parts, 0, 3))) . '.';
    }

    private function localRecommendationTip(array $job): string
    {
        $tips = [];

        if (!empty($job['missing_skills'])) {
            $tips[] = 'strengthen ' . implode(', ', array_slice($job['missing_skills'], 0, 3));
        }

        $warnings = collect($job['warnings'] ?? [])
            ->filter(fn ($warning) => str_contains(strtolower($warning), 'preference'))
            ->values();

        if ($warnings->isNotEmpty()) {
            $tips[] = 'complete your location and employment preferences';
        }

        return $tips
            ? 'Consider ' . implode(' and ', $tips) . '.'
            : 'Keep your resume projects and skills up to date for this role.';
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
