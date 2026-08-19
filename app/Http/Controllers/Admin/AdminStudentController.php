<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\AdminActivityLogService;
use App\Services\AutomaticVerificationService;
use Illuminate\Http\Request;

class AdminStudentController extends Controller
{
    public function index(Request $request, AutomaticVerificationService $verificationService)
    {
        $this->authorizeAdmin($request);

        $query = Student::with('user');

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('university', 'like', "%{$search}%")
                    ->orWhere('major', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            if ($request->status === 'Suspended') {
                $query->whereHas('user', function ($userQuery) {
                    $userQuery->where('status', 'Suspended');
                });
            } else {
                $query->where('verification_status', $request->status);
            }
        }

        $students = $query
            ->latest()
            ->get();

        $data = $students->map(function ($student) use ($verificationService) {
            $score = $verificationService
                ->calculateStudentVerificationScore($student);

            return [
                'id' => $student->id,
                'user_id' => $student->user_id,
                'name' => $student->user?->name,
                'email' => $student->user?->email,
                'avatar' => $student->avatar,
                'university' => $student->university,
                'major' => $student->major,
                'graduation_year' => $student->graduation_year,
                'phone' => $student->phone,
                'profile_completion' => $student->profile_completion,
                'verification_status' => $student->verification_status,
                'account_status' => $student->user?->status,
                'verification_score' => $score['verification_score'],
                'recommendation' => $score['recommendation'],
                'email_verified' => !is_null(
                    $student->user?->email_verified_at
                ),
                'joined' => $student->created_at?->format('Y-m-d'),
            ];
        });

        return response()->json([
            'students' => $data,
            'total' => $data->count(),
        ]);
    }

    public function show(
        Request $request,
        Student $student,
        AutomaticVerificationService $verificationService
    ) {
        $this->authorizeAdmin($request);

        $student->load('user');

        $score = $verificationService
            ->calculateStudentVerificationScore($student);

        return response()->json([
            'student' => [
                'id' => $student->id,
                'user_id' => $student->user_id,
                'name' => $student->user?->name,
                'email' => $student->user?->email,
                'avatar' => $student->avatar,
                'university' => $student->university,
                'major' => $student->major,
                'graduation_year' => $student->graduation_year,
                'gpa' => $student->gpa,
                'phone' => $student->phone,
                'linkedin' => $student->linkedin,
                'github' => $student->github,
                'portfolio' => $student->portfolio,
                'bio' => $student->bio,
                'headline' => $student->headline,
                'location' => $student->location,
                'profile_completion' => $student->profile_completion,
                'verification_status' => $student->verification_status,
                'account_status' => $student->user?->status,
                'verification_score' => $score['verification_score'],
                'recommendation' => $score['recommendation'],
                'email_verified' => !is_null(
                    $student->user?->email_verified_at
                ),
                'joined' => $student->created_at?->format('Y-m-d'),
            ],
        ]);
    }

    public function approve(Request $request, Student $student)
    {
        $this->authorizeAdmin($request);

        $student->update([
            'verification_status' => 'Approved',
        ]);

        if ($student->user) {
            $student->user->update([
                'status' => 'Active',
            ]);
        }

        AdminActivityLogService::log(
            'Student Approved',
            'Student',
            $student->id,
            "{$student->user?->name} was approved by admin.",
            'Admin',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Student approved successfully.',
            'student' => $student->fresh()->load('user'),
        ]);
    }

    public function reject(Request $request, Student $student)
    {
        $this->authorizeAdmin($request);

        $student->update([
            'verification_status' => 'Rejected',
        ]);

        if ($student->user) {
            $student->user->update([
                'status' => 'Inactive',
            ]);
        }

        AdminActivityLogService::log(
            'Student Rejected',
            'Student',
            $student->id,
            "{$student->user?->name} was rejected by admin.",
            'Admin',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Student rejected successfully.',
            'student' => $student->fresh()->load('user'),
        ]);
    }

    public function suspend(Request $request, Student $student)
    {
        $this->authorizeAdmin($request);

        if ($student->user) {
            $student->user->update([
                'status' => 'Suspended',
            ]);
        }

        AdminActivityLogService::log(
            'Student Suspended',
            'Student',
            $student->id,
            "{$student->user?->name} was suspended by admin.",
            'Admin',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Student suspended successfully.',
            'student' => $student->fresh()->load('user'),
        ]);
    }

    public function restore(Request $request, Student $student)
    {
        $this->authorizeAdmin($request);

        if ($student->user) {
            $student->user->update([
                'status' => 'Active',
            ]);
        }

        $student->update([
            'verification_status' => 'Approved',
        ]);

        AdminActivityLogService::log(
            'Student Restored',
            'Student',
            $student->id,
            "{$student->user?->name} was restored by admin.",
            'Admin',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Student restored successfully.',
            'student' => $student->fresh()->load('user'),
        ]);
    }

    public function activate(Request $request, $id)
    {
        $this->authorizeAdmin($request);

        $student = Student::find($id);

        if (!$student) {
            return response()->json([
                'message' => 'Student not found.',
            ], 404);
        }

        $student->load('user');

        if (!$student->user) {
            return response()->json([
                'message' => 'Student user account not found.',
            ], 404);
        }

        $student->user->update([
            'status' => 'Active',
        ]);

        AdminActivityLogService::log(
            'Student Activated',
            'Student',
            $student->id,
            "{$student->user?->name} was activated by admin.",
            'Admin',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Student activated successfully.',
            'student' => $student->load('user'),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_if(
            strtolower($request->user()?->role ?? '') !== 'admin',
            403,
            'Unauthorized. Admin access required.'
        );
    }
}