<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Company;
use App\Models\Application;

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

    public function companies()
    {
        $companies = Company::query()
            ->select([
                'id',
                'company_name',
                'logo',
                'industry',
                'location',
                'description',
                'website',
                'is_verified',
            ])
            ->orderBy('company_name')
            ->get();

        return response()->json([
            'companies' => $companies,
            'status' => 'success',
        ]);
    }
}
