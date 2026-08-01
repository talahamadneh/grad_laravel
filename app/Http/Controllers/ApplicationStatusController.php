<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Company;

class ApplicationStatusController extends Controller
{

    public function update(Request $request, $applicationId)
    {
        $company = Company::where('user_id', $request->user()->id)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }


        $request->validate([
            'status' => 'required|in:Applied,Screening,Interview,Shortlisted,Offer,Hired,Rejected'
        ]);



        $application = Application::where('id', $applicationId)
            ->whereHas('jobPost', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->first();



        if (!$application) {
            return response()->json([
                'message' => 'Application not found'
            ], 404);
        }



        // Update current application status
        $application->update([
            'status' => $request->status
        ]);



        // Get last timeline status
        $lastStatus = ApplicationStatusHistory::where(
                'application_id',
                $application->id
            )
            ->latest('changed_at')
            ->first();



        $history = null;



        // Add timeline record only if status changed
        if (!$lastStatus || $lastStatus->status != $request->status) {

            $history = ApplicationStatusHistory::create([

                'application_id' => $application->id,

                'status' => $request->status

            ]);

        }



        return response()->json([

            'message' => 'Application status updated successfully',

            'application_status' => $application->status,

            'history' => $history

        ]);

    }





    public function timeline(Request $request, $applicationId)
    {

        $company = Company::where('user_id', $request->user()->id)->first();



        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ],404);
        }




        $application = Application::where('id', $applicationId)

            ->whereHas('jobPost', function ($query) use ($company) {

                $query->where('company_id', $company->id);

            })

            ->first();




        if (!$application) {

            return response()->json([
                'message' => 'Application not found'
            ],404);

        }




        return response()->json(

            ApplicationStatusHistory::where(
                'application_id',
                $applicationId
            )
            ->orderBy('changed_at')
            ->get()

        );

    }

}