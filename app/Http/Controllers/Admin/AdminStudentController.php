<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\AutomaticVerificationService;
use Illuminate\Http\Request;

class AdminStudentController extends Controller
{
    public function index(Request $request, AutomaticVerificationService $verificationService)
    {
        $query = Student::with('user');

        // Search
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

        // Filter by verification status
        if ($request->filled('status')) {
            $query->where('verification_status', $request->status);
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

                'university' => $student->university,
                'major' => $student->major,
                'graduation_year' => $student->graduation_year,
                'phone' => $student->phone,

                'profile_completion' => $student->profile_completion,

                'verification_status' => $student->verification_status,

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
        Student $student,
        AutomaticVerificationService $verificationService
    ) {
        $student->load('user');

        $score = $verificationService
            ->calculateStudentVerificationScore($student);

        return response()->json([
            'student' => [
                'id' => $student->id,
                'user_id' => $student->user_id,

                'name' => $student->user?->name,
                'email' => $student->user?->email,

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

                'verification_score' => $score['verification_score'],
                'recommendation' => $score['recommendation'],

                'email_verified' => !is_null(
                    $student->user?->email_verified_at
                ),

                'joined' => $student->created_at?->format('Y-m-d'),
            ],
        ]);
    }

    public function approve(Student $student)
    {
        $student->update([
            'verification_status' => 'Approved',
        ]);

        if ($student->user) {
            $student->user->update([
                'status' => 'Active',
            ]);
        }

        return response()->json([
            'message' => 'Student approved successfully.',
            'student' => $student->fresh()->load('user'),
        ]);
    }


    public function reject(Student $student)
    {
        $student->update([
            'verification_status' => 'Rejected',
        ]);

        return response()->json([
            'message' => 'Student rejected successfully.',
            'student' => $student->fresh()->load('user'),
        ]);
    }


    public function suspend(Student $student)
    {
        if ($student->user) {
            $student->user->update([
                'status' => 'Suspended',
            ]);
        }

        return response()->json([
            'message' => 'Student suspended successfully.',
            'student' => $student->fresh()->load('user'),
        ]);
    }


    public function restore(Student $student)
    {
        if ($student->user) {
            $student->user->update([
                'status' => 'Active',
            ]);
        }

        $student->update([
            'verification_status' => 'Approved',
        ]);

        return response()->json([
            'message' => 'Student restored successfully.',
            'student' => $student->fresh()->load('user'),
        ]);
    }

    public function activate($id)
    {
        $student = \App\Models\Student::find($id);

        if (!$student) {
            return response()->json([
                'message' => 'Student not found.'
            ], 404);
        }

        $student->load('user');

        if (!$student->user) {
            return response()->json([
                'message' => 'Student user account not found.'
            ], 404);
        }

        $student->user->status = 'Active';
        $student->user->save();

        return response()->json([
            'message' => 'Student activated successfully.',
            'student' => $student->load('user'),
        ]);
    }
}