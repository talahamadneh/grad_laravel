<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use Illuminate\Http\Request;

class AdminSystemLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'action' => 'nullable|string|max:255',
            'actor_type' => 'nullable|string|max:255',
            'actor_id' => 'nullable|integer|min:1',
            'target_type' => 'nullable|string|max:255',
            'target_id' => 'nullable|integer|min:1',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = AdminActivityLog::query();

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        foreach (['action', 'actor_type', 'actor_id', 'target_type', 'target_id'] as $filter) {
            if (($validated[$filter] ?? null) !== null && $validated[$filter] !== '') {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        return response()->json(
            $query
                ->latest()
                ->paginate((int) ($validated['per_page'] ?? 20), [
                    'id',
                    'actor_type',
                    'actor_id',
                    'action',
                    'target_type',
                    'target_id',
                    'description',
                    'created_at',
                ])
        );
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_if(strtolower($request->user()?->role ?? '') !== 'admin', 403, 'Unauthorized. Admin access required.');
    }
}
