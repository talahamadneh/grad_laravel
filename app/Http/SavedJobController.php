<?php

namespace App\Http\Controllers;

use App\Models\SavedJob;
use Illuminate\Http\Request;

class SavedJobController extends Controller
{
    public function index(Request $request)
    {
        $studentId = $request->user()->student->id;

        $jobs = SavedJob::with(['jobPost.company', 'jobPost.skills'])
            ->where('student_id', $studentId)
            ->get()
            ->pluck('jobPost')
            ->filter(); // بيشيل أي null لو الجوب اتحذف

        return response()->json($jobs->values());
    }

    public function store(Request $request, $jobId)
    {
        $studentId = $request->user()->student->id;

        SavedJob::firstOrCreate([
            'student_id' => $studentId,
            'job_post_id' => $jobId,
        ]);

        return response()->json(['saved' => true]);
    }

    public function destroy(Request $request, $jobId)
    {
        $studentId = $request->user()->student->id;

        SavedJob::where('student_id', $studentId)
            ->where('job_post_id', $jobId)
            ->delete();

        return response()->json(['saved' => false]);
    }
}