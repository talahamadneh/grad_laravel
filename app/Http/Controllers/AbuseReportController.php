<?php

namespace App\Http\Controllers;

use App\Models\AbuseReport;
use App\Services\AbuseReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AbuseReportController extends Controller
{
    public function store(Request $request, AbuseReportService $abuseReportService)
    {
        $validated = $request->validate([
            'reportable_type' => ['required', Rule::in(AbuseReport::REPORTABLE_TYPES)],
            'reportable_id' => 'required|integer|min:1',
            'reason' => 'required|string|max:1000',
            'description' => 'nullable|string|max:5000',
        ]);

        $result = $abuseReportService->create($request->user(), $validated);

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], $result['status']);
        }

        return response()->json([
            'message' => 'Report submitted successfully.',
            'report' => $result['report'],
        ], $result['status']);
    }
}
