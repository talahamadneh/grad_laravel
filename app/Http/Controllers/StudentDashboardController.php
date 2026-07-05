<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\Application;
use App\Models\SavedJob;
use App\Models\ProfileView;
use App\Models\JobPost;

class StudentDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $student = Student::where('user_id', $user->id)->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student not found'
            ], 404);
        }

     
        $applications = Application::where('student_id', $student->id)->count();

        $interviews = Application::where('student_id', $student->id)
            ->where('status', 'Interview')
            ->count();

        $savedJobs = SavedJob::where('student_id', $student->id)->count();

        $profileViews = ProfileView::where('user_id', $user->id)->count();

        

        $activity = Application::where('student_id', $student->id)
            ->selectRaw('DATE_FORMAT(applied_at, "%Y-%m") as month, COUNT(*) as value')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        

        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];

        

        return response()->json([
            'user' => $userData,

            'student' => $student,

            'stats' => [
                'applications' => $applications,
                'interviews' => $interviews,
                'saved_jobs' => $savedJobs,
                'profile_views' => $profileViews,
            ],

            'activity' => $activity,
        ]);
    }
}