<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AdminActivityLogService;
use App\Services\AutomaticVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCompanyController extends Controller
{
    /**
     * Get all companies.
     */
    public function index(
        Request $request,
        AutomaticVerificationService $verificationService
    ) {
        $this->authorizeAdmin($request);

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
        | Add verification score + risk level
        |--------------------------------------------------------------------------
        */

        $companies->transform(function ($company) use ($verificationService) {

            $scoreData =
                $verificationService->calculateCompanyVerificationScore(
                    $company
                );

            $company->verification_score =
                $scoreData['verification_score'];

            $company->risk_level =
                $scoreData['risk_level'];

            $company->recommendation =
                $scoreData['recommendation'];

            $company->reports_count =
                $scoreData['reports_count'];

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
    public function pending(
        Request $request,
        AutomaticVerificationService $verificationService
    ) {
        $this->authorizeAdmin($request);

        $companies = Company::with('user')
            ->withCount('jobPosts')
            ->where('approval_status', 'Pending')
            ->latest()
            ->get();

        $companies->transform(function ($company) use ($verificationService) {

            $scoreData =
                $verificationService->calculateCompanyVerificationScore(
                    $company
                );

            $company->verification_score =
                $scoreData['verification_score'];

            $company->risk_level =
                $scoreData['risk_level'];

            $company->recommendation =
                $scoreData['recommendation'];

            $company->reports_count =
                $scoreData['reports_count'];

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
    public function show(
        Request $request,
        $id,
        AutomaticVerificationService $verificationService
    ) {
        $this->authorizeAdmin($request);

        $company = Company::with('user')
            ->withCount('jobPosts')
            ->find($id);

        if (!$company) {
            return response()->json([
                'message' => 'Company not found.'
            ], 404);
        }

        $scoreData =
            $verificationService->calculateCompanyVerificationScore(
                $company
            );

        $company->verification_score =
            $scoreData['verification_score'];

        $company->risk_level =
            $scoreData['risk_level'];

        $company->recommendation =
            $scoreData['recommendation'];

        $company->reports_count =
            $scoreData['reports_count'];

        return response()->json([
            'company' => $company,
        ]);
    }


    /**
     * Approve company.
     */
    public function approve(Request $request, $id)
    {
        $this->authorizeAdmin($request);

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
            $company->company_name,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Company approved successfully.',
            'company' => $company->fresh(),
        ]);
    }


    /**
     * Reject company.
     */
    public function reject(Request $request, $id)
    {
        $this->authorizeAdmin($request);

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
            "{$company->company_name} was rejected by admin.",
            'Admin',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Company rejected successfully.',
            'company' => $company->fresh(),
        ]);
    }


    /**
     * Suspend company.
     */
    public function suspend(Request $request, $id)
    {
        $this->authorizeAdmin($request);

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
            $company->company_name,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Company suspended successfully.',
            'company' => $company->fresh(),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_if(strtolower($request->user()?->role ?? '') !== 'admin', 403, 'Unauthorized. Admin access required.');
    }
}
