<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Company;
use App\Models\Application;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function stats()
    {
        return response()->json([

            "gradution-profiles" => Student::count(),
            "companies" =>Company::count(),
             "successful-hires" => Application::where('status', 'hired')->count(),
             "satisfaction-rate" => 94,
            'message' => 'Welcome to the API Landing Page',
            'status' => 'success'
        ]);
    }
}
