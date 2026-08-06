<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Interview;
use App\Models\ApplicationStatusHistory;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;



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

        NotificationService::interviewRescheduled($interview->fresh(['application.student', 'application.jobPost.company']));

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

        NotificationService::interviewCancelled($interview->fresh(['application.student', 'application.jobPost.company']));

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



        public function bulkSchedule(Request $request)
    {
        $request->validate([
            'application_ids' => 'required|array|min:1',
            'application_ids.*' => 'exists:applications,id',
            'interview_date' => 'required|date',
            'start_time' => 'required',
            'duration' => 'required|integer|min:5',
            'type' => 'required|in:Online,Onsite',
            'meeting_link' => 'nullable|string',
            'location' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {

            $currentTime = Carbon::parse(
                $request->interview_date . ' ' . $request->start_time
            );

            $count = 0;

            foreach ($request->application_ids as $applicationId) {

                $application = \App\Models\Application::findOrFail($applicationId);

                if ($application->jobPost->company_id != auth()->user()->company->id) {

                    DB::rollBack();

                    return response()->json([
                        'message' => 'Unauthorized.'
                    ], 403);
                }

                if (Interview::where('application_id', $application->id)->exists()) {

                    DB::rollBack();

                    return response()->json([
                        'message' => 'One or more selected candidates already have an interview scheduled.'
                    ], 422);
                }

                $interview = Interview::create([
                    'application_id' => $application->id,
                    'interview_date' => $currentTime->format('Y-m-d H:i:s'),
                    'type' => $request->type,
                    'meeting_link' => $request->meeting_link,
                    'location' => $request->location,
                    'status' => 'Scheduled',
                ]);

                $application->update([
                    'status' => 'Interview'
                ]);

                ApplicationStatusHistory::create([
                    'application_id' => $application->id,
                    'status' => 'Interview',
                    'changed_at' => now(),
                ]);
\Log::info('Before interview notification', [
    'interview_id' => $interview->id,
    'application_id' => $application->id,
    'student_user_id' => $application->student->user_id ?? null,
]);
                NotificationService::interviewScheduled(
                    $interview->fresh([
                        'application.student',
                        'application.jobPost.company'
                    ])
                );

                $count++;

                $currentTime->addMinutes($request->duration);
            }

            DB::commit();

            return response()->json([
                'message' => 'Interviews scheduled successfully.',
                'scheduled_count' => $count
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Scheduling failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
