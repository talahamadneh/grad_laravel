<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Interview;

class InterviewController extends Controller
{
    public function index()
    {
        $companyId = auth()->user()->company->id;

        $interviews = Interview::with([
            'application.student.user',
            'application.jobPost'
        ])
        ->whereHas('application.jobPost', function ($query) use ($companyId) {
            $query->where('company_id', $companyId);
        })
        ->orderBy('interview_date')
        ->get();

        $data = $interviews->map(function ($interview) {
            return [
                'id' => $interview->id,
                'candidate_name' => $interview->application->student->user->name,
                'headline' => $interview->application->student->headline,
                'avatar' => $interview->application->student->avatar,
                'job_title' => $interview->application->jobPost->title,
                'interview_date' => $interview->interview_date,
                'type' => $interview->type,
                'meeting_link' => $interview->meeting_link,
                'location' => $interview->location,
                'status' => $interview->status,
                'duration' => '30 min',
            ];
        });

        return response()->json($data);
    }


    public function show(Interview $interview)
    {
        $companyId = auth()->user()->company->id;

        if ($interview->application->jobPost->company_id != $companyId) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $interview->load([
            'application.student.user',
            'application.jobPost'
        ]);

        return response()->json([
            'id' => $interview->id,
            'candidate_name' => $interview->application->student->user->name,
            'headline' => $interview->application->student->headline,
            'avatar' => $interview->application->student->avatar,
            'job_title' => $interview->application->jobPost->title,
            'interview_date' => $interview->interview_date,
            'type' => $interview->type,
            'meeting_link' => $interview->meeting_link,
            'location' => $interview->location,
            'status' => $interview->status,
        ]);
    }


    public function update(Request $request, Interview $interview)
    {
        $companyId = auth()->user()->company->id;

        if ($interview->application->jobPost->company_id != $companyId) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $request->validate([
            'interview_date' => 'required|date|after:now',
            'type' => 'required|in:Online,Onsite',
            'meeting_link' => 'nullable|string',
            'location' => 'nullable|string',
        ]);

        $interview->update([
            'interview_date' => $request->interview_date,
            'type' => $request->type,
            'meeting_link' => $request->meeting_link,
            'location' => $request->location,
            'status' => 'Scheduled'
        ]);

        return response()->json([
            'message' => 'Interview updated successfully.',
            'interview' => $interview
        ]);
    }


    public function cancel(Interview $interview)
    {
        $companyId = auth()->user()->company->id;

        if ($interview->application->jobPost->company_id != $companyId) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $interview->update([
            'status' => 'Cancelled'
        ]);

        return response()->json([
            'message' => 'Interview cancelled successfully.',
            'status' => $interview->status
        ]);
    }


    public function complete(Interview $interview)
    {
        $companyId = auth()->user()->company->id;

        if ($interview->application->jobPost->company_id != $companyId) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        if ($interview->status === 'Cancelled') {
            return response()->json([
                'message' => 'Cancelled interviews cannot be completed.'
            ], 400);
        }

        $interview->update([
            'status' => 'Completed'
        ]);

        return response()->json([
            'message' => 'Interview completed successfully.',
            'status' => $interview->status
        ]);
    }


    public function stats(Request $request)
    {
        $company = $request->user()->company;

        $interviews = Interview::whereHas('application.jobPost', function ($q) use ($company) {
            $q->where('company_id', $company->id);
        });

        return response()->json([
            'scheduled' => (clone $interviews)
                ->where('status', 'Scheduled')
                ->count(),

            'this_week' => (clone $interviews)
                ->whereBetween('interview_date', [
                    now()->startOfDay(),
                    now()->addDays(6)->endOfDay()
                ])
                ->count(),

            'completed' => (clone $interviews)
                ->where('status', 'Completed')
                ->count(),

            'candidates' => (clone $interviews)
                ->distinct('application_id')
                ->count('application_id'),
        ]);
    }


    public function calendar()
    {
        $companyId = auth()->user()->company->id;

        $interviews = Interview::whereHas('application.jobPost', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
        ->whereBetween('interview_date', [
            now()->startOfDay(),
            now()->addDays(6)->endOfDay()
        ])
        ->pluck('interview_date');

        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $date = now()->addDays($i);

            $days[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('D'),
                'number' => $date->format('j'),
                'has_interviews' => $interviews->contains(function ($item) use ($date) {
                    return \Carbon\Carbon::parse($item)->isSameDay($date);
                })
            ];
        }

        return response()->json($days);
    }
}