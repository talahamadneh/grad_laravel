<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AdminActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCompanyController extends Controller
{
    /**
     * Get all companies.
     */
    public function index(Request $request)
    {
        $query = Company::with('user')
            ->withCount('jobPosts');

        /*
        |--------------------------------------------------------------------------
        | Filter by approval status
        |--------------------------------------------------------------------------
        */

        if ($request->filled('status')) {
            $query->where(
                'approval_status',
                $request->status
            );
        }

        $companies = $query
            ->latest()
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Add reports count + trust score
        |--------------------------------------------------------------------------
        */

        $companies->transform(function ($company) {

            // Number of reports against this company's user
            $reportsCount = DB::table('message_reports')
                ->where('reported_user_id', $company->user_id)
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Trust Score
            |--------------------------------------------------------------------------
            |
            | Basic automatic score:
            | Start from 100
            | - Pending reports: -10 each
            | - Rejected company: -30
            | - Suspended company: -50
            |
            */

            $trustScore = 100;

            if ($reportsCount > 0) {
                $trustScore -= ($reportsCount * 10);
            }

            if ($company->approval_status === 'Rejected') {
                $trustScore -= 30;
            }

            if ($company->approval_status === 'Suspended') {
                $trustScore -= 50;
            }

            // Keep score between 0 and 100
            $trustScore = max(0, min(100, $trustScore));

            $company->reports_count = $reportsCount;
            $company->trust_score = $trustScore;

            return $company;
        });

        return response()->json([
            'companies' => $companies,
            'count' => $companies->count(),
        ]);
    }


    /**
     * Get companies that need admin review.
     */
    public function pending()
    {
        $companies = Company::with('user')
            ->withCount('jobPosts')
            ->where('approval_status', 'Pending')
            ->latest()
            ->get();

        $companies->transform(function ($company) {

            $reportsCount = DB::table('message_reports')
                ->where('reported_user_id', $company->user_id)
                ->count();

            $trustScore = 100;

            if ($reportsCount > 0) {
                $trustScore -= ($reportsCount * 10);
            }

            $trustScore = max(0, min(100, $trustScore));

            $company->reports_count = $reportsCount;
            $company->trust_score = $trustScore;

            return $company;
        });

        return response()->json([
            'companies' => $companies,
            'count' => $companies->count(),
        ]);
    }


    /**
     * Get company details.
     */
    public function show($id)
    {
        $company = Company::with('user')
            ->withCount('jobPosts')
            ->find($id);

        if (!$company) {
            return response()->json([
                'message' => 'Company not found.'
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Reports
        |--------------------------------------------------------------------------
        */

        $reportsCount = DB::table('message_reports')
            ->where('reported_user_id', $company->user_id)
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Trust Score
        |--------------------------------------------------------------------------
        */

        $trustScore = 100;

        if ($reportsCount > 0) {
            $trustScore -= ($reportsCount * 10);
        }

        if ($company->approval_status === 'Rejected') {
            $trustScore -= 30;
        }

        if ($company->approval_status === 'Suspended') {
            $trustScore -= 50;
        }

        $trustScore = max(0, min(100, $trustScore));

        $company->reports_count = $reportsCount;
        $company->trust_score = $trustScore;

        return response()->json([
            'company' => $company,
        ]);
    }


    /**
     * Approve company.
     */
    public function approve($id)
    {
        $company = Company::find($id);

        if (!$company) {
            return response()->json([
                'message' => 'Company not found.'
            ], 404);
        }

        $company->update([
            'approval_status' => 'Approved',
            'is_verified' => true,
        ]);

        AdminActivityLogService::companyApproved(
            $company->id,
            $company->company_name
        );

        return response()->json([
            'message' => 'Company approved successfully.',
            'company' => $company->fresh(),
        ]);
    }


    /**
     * Reject company.
     */
    public function reject($id)
    {
        $company = Company::find($id);

        if (!$company) {
            return response()->json([
                'message' => 'Company not found.'
            ], 404);
        }

        $company->update([
            'approval_status' => 'Rejected',
            'is_verified' => false,
        ]);

        AdminActivityLogService::log(
            'Company Rejected',
            'Company',
            $company->id,
            "{$company->company_name} was rejected by admin."
        );

        return response()->json([
            'message' => 'Company rejected successfully.',
            'company' => $company->fresh(),
        ]);
    }


    /**
     * Suspend company.
     */
    public function suspend($id)
    {
        $company = Company::find($id);

        if (!$company) {
            return response()->json([
                'message' => 'Company not found.'
            ], 404);
        }

        $company->update([
            'approval_status' => 'Suspended',
            'is_verified' => false,
        ]);

        AdminActivityLogService::companySuspended(
            $company->id,
            $company->company_name
        );

        return response()->json([
            'message' => 'Company suspended successfully.',
            'company' => $company->fresh(),
        ]);
    }
}