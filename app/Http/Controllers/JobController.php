<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\JobPost;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request)
    {
        $jobs = JobPost::with('company')
            ->where('status', 'Open')
            ->paginate(10);

        return response()->json($jobs);
    }

    public function show($id)
    {
        $job = JobPost::with('company')
            ->where('status', 'Open')
            ->findOrFail($id);

        return response()->json($job);
    }
}