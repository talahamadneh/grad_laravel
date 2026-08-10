<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Admin Dashboard
     */
    public function index(Request $request)
    {
        // Make sure the logged-in user is an Admin
        if (strtolower($request->user()->role) !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Main Statistics
        |--------------------------------------------------------------------------
        */

        $totalStudents = DB::table('users')
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->count();

        $totalCompanies = DB::table('companies')->count();

        $activeJobs = DB::table('job_posts')
            ->where('status', 'Open')
            ->count();

        $totalHires = DB::table('applications')
            ->where('status', 'Accepted')
            ->count();


        /*
        |--------------------------------------------------------------------------
        | Items Need Review
        |--------------------------------------------------------------------------
        */

        // Companies waiting for admin approval
        $companiesNeedReview = DB::table('companies')
            ->where('approval_status', 'Pending')
            ->count();

        // Jobs waiting for moderation
        $jobsNeedReview = DB::table('job_posts')
            ->whereIn('status', [
                'Under Review',
                'Pending',
            ])
            ->count();

        // Reports that still need admin action
        $reportsNeedReview = DB::table('message_reports')
            ->whereIn('status', [
                'Pending',
                'Reviewed',
            ])
            ->count();

        $totalNeedReview =
            $companiesNeedReview +
            $jobsNeedReview +
            $reportsNeedReview;


        /*
        |--------------------------------------------------------------------------
        | Admin Attention
        |--------------------------------------------------------------------------
        */

        $adminAttention = [
            [
                'type' => 'Company Verification',
                'count' => $companiesNeedReview,
            ],
            [
                'type' => 'Job Moderation',
                'count' => $jobsNeedReview,
            ],
            [
                'type' => 'Reports',
                'count' => $reportsNeedReview,
            ],
        ];


        /*
        |--------------------------------------------------------------------------
        | System Activity / Automation
        |--------------------------------------------------------------------------
        */

        $activities = AdminActivityLog::query()
            ->latest()
            ->limit(10)
            ->get([
                'id',
                'action',
                'target_type',
                'target_id',
                'description',
                'created_at',
            ]);


        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'statistics' => [
                'total_students' => $totalStudents,
                'total_companies' => $totalCompanies,
                'active_jobs' => $activeJobs,
                'total_hires' => $totalHires,
            ],

            'needs_review' => [
                'companies' => $companiesNeedReview,
                'jobs' => $jobsNeedReview,
                'reports' => $reportsNeedReview,
                'total' => $totalNeedReview,
            ],

            'admin_attention' => $adminAttention,

            'system_activity' => $activities,
        ]);
    }
}