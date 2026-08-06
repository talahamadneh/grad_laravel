<?php

namespace App\Http\Controllers;

use App\Models\ApplicationStatusHistory;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class InterviewFeedbackController extends Controller
{
    public function store(Request $request, Interview $interview)
    {
        $companyId = auth()->user()->company->id;

        if ($interview->application->jobPost->company_id != $companyId) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        if ($interview->status !== 'Completed') {
            return response()->json([
                'message' => 'Feedback can only be submitted after the interview is completed.'
            ], 400);
        }

        if ($interview->feedback) {
            return response()->json([
                'message' => 'Feedback already exists.'
            ], 400);
        }

        $validated = $request->validate([
            'technical_score' => 'required|integer|min:0|max:100',
            'communication_score' => 'required|integer|min:0|max:100',
            'notes' => 'nullable|string',
            'final_decision' => 'required|in:Accepted,Rejected',
        ]);

        $feedback = InterviewFeedback::create([
            'interview_id' => $interview->id,
            'technical_score' => $validated['technical_score'],
            'communication_score' => $validated['communication_score'],
            'notes' => $validated['notes'] ?? null,
            'final_decision' => $validated['final_decision'],
        ]);

        $this->applyFinalDecision(
            $interview,
            $validated['final_decision']
        );

        return response()->json([
            'message' => 'Feedback submitted successfully.',
            'feedback' => $feedback
        ], 201);
    }


    public function show(Interview $interview)
    {
        $companyId = auth()->user()->company->id;

        if ($interview->application->jobPost->company_id != $companyId) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        if (!$interview->feedback) {
            return response()->json([
                'message' => 'Feedback not found.'
            ], 404);
        }

        return response()->json($interview->feedback);
    }


    public function update(Request $request, Interview $interview)
    {
        $companyId = auth()->user()->company->id;

        if ($interview->application->jobPost->company_id != $companyId) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        if (!$interview->feedback) {
            return response()->json([
                'message' => 'Feedback not found.'
            ], 404);
        }

        $validated = $request->validate([
            'technical_score' => 'required|integer|min:0|max:100',
            'communication_score' => 'required|integer|min:0|max:100',
            'notes' => 'nullable|string',
            'final_decision' => 'required|in:Accepted,Rejected',
        ]);

        $previousDecision = $interview->feedback->final_decision;

        $interview->feedback->update($validated);

        if ($previousDecision !== $validated['final_decision']) {
            $this->applyFinalDecision(
                $interview,
                $validated['final_decision']
            );
        }

        return response()->json([
            'message' => 'Feedback updated successfully.',
            'feedback' => $interview->feedback->fresh()
        ]);
    }


    public function destroy(Interview $interview)
    {
        $companyId = auth()->user()->company->id;

        if ($interview->application->jobPost->company_id != $companyId) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        if (!$interview->feedback) {
            return response()->json([
                'message' => 'Feedback not found.'
            ], 404);
        }

        $interview->feedback->delete();

        return response()->json([
            'message' => 'Feedback deleted successfully.'
        ]);
    }


    private function applyFinalDecision(Interview $interview, string $decision): void
    {
        $application = $interview->application;

        if ($application->status === $decision) {
            return;
        }

        $application->update([
            'status' => $decision,
        ]);

        ApplicationStatusHistory::create([
            'application_id' => $application->id,
            'status' => $decision,
            'changed_at' => now(),
        ]);

        NotificationService::applicationStatusChanged(
            $application->fresh([
                'student.user',
                'jobPost.company'
            ]),
            $decision
        );
    }
}