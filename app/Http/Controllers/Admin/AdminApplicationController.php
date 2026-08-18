<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminApplicationController extends Controller
{
    private const PENDING_STATUSES = ['Applied', 'Under Review'];
    private const SHORTLISTED_STATUSES = ['Shortlisted'];
    private const INTERVIEW_STATUSES = ['Interview'];
    private const ACCEPTED_STATUSES = ['Accepted', 'Hired'];
    private const REJECTED_STATUSES = ['Rejected'];

    public function index(Request $request)
    {
        if (strtolower($request->user()?->role ?? '') !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        $request->validate([
            'limit' => 'nullable|integer|min:1|max:25',
        ]);

        $statusCounts = Application::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $recentApplications = Application::with([
            'student.user',
            'jobPost.company',
        ])
            ->latest()
            ->limit((int) $request->input('limit', 10))
            ->get()
            ->map(function (Application $application) {
                return [
                    'id' => $application->id,
                    'candidate' => $application->student?->user?->name ?? 'Unknown Student',
                    'job' => $application->jobPost?->title ?? 'Unknown Job',
                    'company' => $application->jobPost?->company?->company_name ?? 'Unknown Company',
                    'status' => $application->status,
                    'date' => optional($application->created_at)->toDateString(),
                    'created_at' => $application->created_at,
                ];
            });

        return response()->json([
            'statistics' => [
                'total_applications' => (int) $statusCounts->sum(),
                'pending' => $this->sumStatuses($statusCounts, self::PENDING_STATUSES),
                'shortlisted' => $this->sumStatuses($statusCounts, self::SHORTLISTED_STATUSES),
                'interviews' => $this->sumStatuses($statusCounts, self::INTERVIEW_STATUSES),
                'accepted' => $this->sumStatuses($statusCounts, self::ACCEPTED_STATUSES),
                'rejected' => $this->sumStatuses($statusCounts, self::REJECTED_STATUSES),
            ],
            'recent_applications' => $recentApplications,
        ]);
    }

    private function sumStatuses($statusCounts, array $statuses): int
    {
        return (int) collect($statuses)->sum(
            fn (string $status) => (int) ($statusCounts[$status] ?? 0)
        );
    }
}
