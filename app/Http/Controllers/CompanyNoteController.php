<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\Application;
use App\Models\CompanyNote;

class CompanyNoteController extends Controller
{
    // Get notes for specific applicant
    public function index(Request $request, $applicationId)
    {
        $company = Company::where('user_id', $request->user()->id)->first();
        if (!$company) {
            return response()->json([
                'message' => 'Company profile not found'
            ], 404);
        }
        $application = Application::where('id', $applicationId)
            ->whereHas('jobPost', function($query) use ($company){
                $query->where('company_id', $company->id);
            })
            ->first();

        if(!$application){
            return response()->json([
                'message'=>'Applicant not found'
            ],404);
        }
        $notes = CompanyNote::where('application_id',$applicationId)
            ->latest()
            ->get();
        return response()->json($notes);
    }

    // Add note    
    public function store(Request $request, $applicationId)
    {
        $company = Company::where('user_id', $request->user()->id)->first();
        if (!$company) {
            return response()->json([
                'message'=>'Company profile not found'
            ],404);
        }

        $request->validate([
            'note'=>'required|string'
        ]);

        $application = Application::where('id',$applicationId)
            ->whereHas('jobPost',function($query) use ($company){
                $query->where('company_id',$company->id);
            })
            ->first();

        if(!$application){
            return response()->json([
                'message'=>'Applicant not found'
            ],404);
        }

        $note = CompanyNote::create([
            'application_id'=>$application->id,
            'company_id'=>$company->id,
            'note'=>$request->note
        ]);

        return response()->json([
            'message'=>'Note added successfully',
            'note'=>$note
        ],201);
    }

    // Update note
    public function update(Request $request,$id)
    {
        $request->validate([
            'note'=>'required|string'
        ]);

        $note = CompanyNote::find($id);

        if(!$note){
            return response()->json([
                'message'=>'Note not found'
            ],404);
        }

        $note->update([
            'note'=>$request->note
        ]);

        return response()->json([
            'message'=>'Note updated successfully',
            'note'=>$note
        ]);

    }

    // Delete note
    public function destroy($id)
    {
        $note = CompanyNote::find($id);
        if(!$note){
            return response()->json([
                'message'=>'Note not found'
            ],404);
        }
        $note->delete();
        return response()->json([
            'message'=>'Note deleted successfully'
        ]);

    }

}