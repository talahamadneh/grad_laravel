<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\JobPost;

class StudentJobController extends Controller
{
    public function recommended(Request $request)
    {
        $jobs = JobPost::with('company')
            ->where('status', 'Open')
            ->latest()
            ->take(6)
            ->get();

        return response()->json($jobs);
    }
}