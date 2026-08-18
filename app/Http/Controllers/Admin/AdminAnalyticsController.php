<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAnalyticsService;
use Illuminate\Http\Request;

class AdminAnalyticsController extends Controller
{
    public function index(Request $request, AdminAnalyticsService $analyticsService)
    {
        if (strtolower($request->user()?->role ?? '') !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        $validated = $request->validate([
            'period' => 'nullable|in:week,month,year',
        ]);

        return response()->json(
            $analyticsService->getAnalytics($validated['period'] ?? 'month')
        );
    }
}
