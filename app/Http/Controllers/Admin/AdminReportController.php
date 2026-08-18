<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminReportService;
use Illuminate\Http\Request;

class AdminReportController extends Controller
{
    public function platform(Request $request, AdminReportService $reportService)
    {
        if ($response = $this->authorizeAdmin($request)) {
            return $response;
        }

        $validated = $request->validate([
            'period' => 'nullable|in:week,month,year',
        ]);

        return response()->json(
            $reportService->platform($validated['period'] ?? 'month')
        );
    }

    public function abuse(Request $request, AdminReportService $reportService)
    {
        if ($response = $this->authorizeAdmin($request)) {
            return $response;
        }

        $validated = $request->validate([
            'status' => 'nullable|string|max:50',
            'entity_type' => 'nullable|string|max:50',
            'risk_level' => 'nullable|string|max:50',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        return response()->json(
            $reportService->abuse($validated)
        );
    }

    public function showAbuse(Request $request, AdminReportService $reportService, int $report)
    {
        if ($response = $this->authorizeAdmin($request)) {
            return $response;
        }

        $details = $reportService->abuseDetails($report);

        if (!$details) {
            return response()->json([
                'message' => 'Report not found.',
            ], 404);
        }

        return response()->json($details);
    }

    public function resolveAbuse(Request $request, AdminReportService $reportService, int $report)
    {
        if ($response = $this->authorizeAdmin($request)) {
            return $response;
        }

        $validated = $request->validate([
            'admin_note' => 'nullable|string|max:5000',
        ]);

        $details = $reportService->resolveAbuseReport(
            $report,
            $request->user(),
            $validated['admin_note'] ?? null
        );

        return $this->abuseActionResponse($details);
    }

    public function dismissAbuse(Request $request, AdminReportService $reportService, int $report)
    {
        if ($response = $this->authorizeAdmin($request)) {
            return $response;
        }

        $validated = $request->validate([
            'admin_note' => 'nullable|string|max:5000',
        ]);

        $details = $reportService->dismissAbuseReport(
            $report,
            $request->user(),
            $validated['admin_note'] ?? null
        );

        return $this->abuseActionResponse($details);
    }

    private function authorizeAdmin(Request $request)
    {
        if (strtolower($request->user()?->role ?? '') !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        return null;
    }

    private function abuseActionResponse(?array $details)
    {
        if (!$details) {
            return response()->json([
                'message' => 'Report not found.',
            ], 404);
        }

        if (isset($details['error'])) {
            return response()->json([
                'message' => $details['error'],
                'report' => $details['report'],
            ], 422);
        }

        return response()->json($details);
    }
}
