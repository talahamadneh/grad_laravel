<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\JobPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\SavedJob;

class JobController extends Controller
{
    public function index(Request $request)
    {
        $jobs = JobPost::with([
            'company',
            'skills'
        ])
            ->withCount('applications')
            ->where('status', 'Open')
            ->paginate(10);

        return response()->json($jobs);
    }

    public function show($id)
    {
        $job = JobPost::with([
            'company',
            'skills'
        ])
            ->withCount('applications')
            ->where('status', 'Open')
            ->findOrFail($id);

        return response()->json($job);
    }

    public function saveJob($id)
    {
        $student = Auth::user()->student;


        $saved = SavedJob::where('student_id', $student->id)
            ->where('job_post_id', $id)
            ->first();


        if ($saved) {

            return response()->json([
                'message' => 'Job already saved'
            ], 409);

        }


        SavedJob::create([
            'student_id' => $student->id,
            'job_post_id' => $id
        ]);


        return response()->json([
            'message' => 'Job saved successfully'
        ]);
    }

    public function removeSaveJob($id)
    {
        $student = Auth::user()->student;


        SavedJob::where('student_id', $student->id)
            ->where('job_post_id', $id)
            ->delete();


        return response()->json([
            'message' => 'Job removed from saved'
        ]);
    }

    public function checkSaved($id)
    {
        $student = Auth::user()->student;


        $saved = SavedJob::where('student_id', $student->id)
            ->where('job_post_id', $id)
            ->exists();


        return response()->json([
            'saved' => $saved
        ]);
    }

    public function savedJobs()
    {
        $student = Auth::user()->student;


        $jobs = SavedJob::with([
            'jobPost.company'
        ])
            ->where('student_id', $student->id)
            ->get();


        return response()->json($jobs);
    }
}