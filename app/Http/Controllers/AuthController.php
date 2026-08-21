<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Student;
use App\Models\Company;
use App\Models\PasswordResetCode;
use App\Services\NotificationService;
use App\Services\AutomaticVerificationService;
use App\Models\EmailVerification;
use App\Mail\EmailVerificationCode;
use App\Mail\PasswordResetCodeMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Exception;

class AuthController extends Controller
{
    public function register(Request $request, AutomaticVerificationService $verificationService)
    {
        $company = null;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6|confirmed',
            'password_confirmation' => 'required',
            'role' => 'required|in:student,company',

            // Student
            'university' => 'required_if:role,student|string|max:255',

            // Company
            'industry' => 'required_if:role,company|string|max:255',
        ]);

        try {
            DB::transaction(function () use ($data, &$user, &$company, $verificationService) {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'role' => ucfirst($data['role']),
                ]);

                if ($data['role'] === 'student') {

                    $student = Student::create([
                        'user_id' => $user->id,
                        'university' => $data['university'],
                        'verification_status' => 'Pending',
                    ]);

                    $code = random_int(100000, 999999);

                    EmailVerification::create([
                        'user_id' => $user->id,
                        'code' => $code,
                        'expires_at' => now()->addMinutes(10),
                    ]);

                    Mail::to($user->email)->send(
                        new EmailVerificationCode($code)
                    );
                    // Automatic verification
                    $verificationService->verifyStudent($student);

                } else {

                    $company = Company::create([
                        'user_id' => $user->id,
                        'company_name' => $data['name'],
                        'industry' => $data['industry'],
                        'approval_status' => 'Pending',
                        'is_verified' => false,
                    ]);

                    $code = random_int(100000, 999999);

                    EmailVerification::create([
                        'user_id' => $user->id,
                        'code' => $code,
                        'expires_at' => now()->addMinutes(10),
                    ]);

                    Mail::to($user->email)->send(
                        new EmailVerificationCode($code)
                    );

                    // Automatic company verification
                    //  $verificationService->verifyCompany($company);
                }
            });

            if ($data['role'] === 'student') {
                NotificationService::studentRegistered($user);
            } elseif ($company && $company->approval_status === 'Pending') {
                NotificationService::companyNeedsReview($company);
            }

            return response()->json([
                'message' => 'Registration successful. A verification code has been sent to your email.',
                'user_id' => $user->id,
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'role' => 'required|in:student,company,admin',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (strtolower($user->role) !== strtolower($data['role'])) {
            return response()->json([
                'message' => 'This account is not registered as a ' . $data['role'],
            ], 401);
        }

        // Check account status
        if ($user->status === 'Suspended') {
            return response()->json([
                'message' => 'Your account has been suspended. Please contact the administrator.',
            ], 403);
        }

        if ($user->status === 'Inactive') {
            return response()->json([
                'message' => 'Your account is inactive. Please contact the administrator.',
            ], 403);
        }

        if (
            in_array(strtolower($user->role), ['student', 'company']) &&
            !$user->email_verified_at
        ) {
            return response()->json([
                'message' => 'Please verify your email before logging in.',
            ], 403);
        }

        if (strtolower($user->role) === 'company') {
            $company = $user->company;

            if ($company && $company->approval_status !== 'Approved') {
                return response()->json([
                    'message' => 'Your company account is pending approval. Please wait for admin confirmation.',
                ], 403);
            }
        }

        $token = $user->createToken('api-token')->plainTextToken;

        $user->load('student', 'company');

        return response()->json([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->student?->phone,
                'location' => $user->student?->location,
                'student' => $user->student,
                'company' => $user->company,
            ],
            'token' => $token,
        ]);
    }


    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'If this email exists, a password reset code has been sent.',
            ]);
        }

        $latestCode = PasswordResetCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (
            $latestCode &&
            $latestCode->locked_until &&
            $latestCode->locked_until->isFuture()
        ) {
            return response()->json([
                'message' => 'Too many incorrect attempts. Please try again later.',
                'locked_until' => $latestCode->locked_until,
            ], 429);
        }

        PasswordResetCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = random_int(100000, 999999);

        PasswordResetCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($user->email)->send(
            new PasswordResetCodeMail($code)
        );

        return response()->json([
            'message' => 'If this email exists, a password reset code has been sent.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|digits:6',
            'password' => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        $user = User::where('email', $data['email'])->first();

        $resetCode = PasswordResetCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (!$resetCode) {
            return response()->json([
                'message' => 'No active password reset code found.',
            ], 422);
        }

        if (
            $resetCode->locked_until &&
            $resetCode->locked_until->isFuture()
        ) {
            return response()->json([
                'message' => 'Too many incorrect attempts. Please try again later.',
                'locked_until' => $resetCode->locked_until,
            ], 429);
        }

        if ($resetCode->expires_at->isPast()) {
            return response()->json([
                'message' => 'Password reset code has expired.',
            ], 422);
        }

        if ((string) $resetCode->code !== (string) $data['code']) {
            $resetCode->increment('attempts');
            $resetCode->refresh();

            if ($resetCode->attempts >= 3) {
                $resetCode->update([
                    'locked_until' => now()->addMinutes(10),
                ]);

                return response()->json([
                    'message' => 'Too many incorrect attempts. Password reset is temporarily locked for 10 minutes.',
                    'locked_until' => $resetCode->locked_until,
                ], 429);
            }

            return response()->json([
                'message' => 'Invalid password reset code.',
                'attempts_remaining' => 3 - $resetCode->attempts,
            ], 422);
        }

        DB::transaction(function () use ($user, $resetCode, $data) {
            $user->update([
                'password' => Hash::make($data['password']),
            ]);

            $resetCode->update([
                'used_at' => now(),
            ]);

            $user->tokens()->delete();
        });

        return response()->json([
            'message' => 'Password reset successfully. Please login with your new password.',
        ]);
    }


    public function user(Request $request)
    {
        return response()->json($request->user());
    }


    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }


    public function verifyEmail(Request $request, AutomaticVerificationService $verificationService)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'code' => 'required|digits:6',
        ]);

        $verification = EmailVerification::where('user_id', $data['user_id'])
            ->whereNull('verified_at')
            ->latest()
            ->first();

        if (!$verification) {
            return response()->json([
                'message' => 'No active verification code found.',
            ], 422);
        }


        // Check temporary lock
        if (
            $verification->locked_until &&
            $verification->locked_until->isFuture()
        ) {
            return response()->json([
                'message' => 'Too many incorrect attempts. Please try again later.',
                'locked_until' => $verification->locked_until,
            ], 429);
        }

        if ($verification->expires_at->isPast()) {
            return response()->json([
                'message' => 'Verification code has expired.',
            ], 422);
        }

        if ((string) $verification->code !== (string) $data['code']) {

            $verification->increment('attempts');

            $verification->refresh();

            if ($verification->attempts >= 3) {

                $verification->update([
                    'locked_until' => now()->addMinutes(10),
                ]);

                return response()->json([
                    'message' => 'Too many incorrect attempts. Verification is temporarily locked for 10 minutes.',
                    'locked_until' => $verification->locked_until,
                ], 429);
            }

            return response()->json([
                'message' => 'Invalid verification code.',
                'attempts_remaining' => 3 - $verification->attempts,
            ], 422);
        }


        $user = User::find($data['user_id']);

        $verification->update([
            'verified_at' => now(),
        ]);

        $user->update([
            'email_verified_at' => now(),
        ]);

        // Automatic verification AFTER email verification
        if (strtolower($user->role) === 'student') {

            $verificationService->verifyStudent(
                $user->student
            );

        } elseif (strtolower($user->role) === 'company') {

            $status = $verificationService->verifyCompany(
                $user->company
            );

            if ($status === 'Pending' && $user->company) {
                NotificationService::companyNeedsReview($user->company);
            }
        }

        return response()->json([
            'message' => 'Email verified successfully.',
            'user' => $user->fresh()->load('student', 'company'),
        ]);
    }

    public function resendVerification(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::find($data['user_id']);

        // Already verified
        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified.',
            ], 400);
        }

        // Get latest verification record
        $verification = EmailVerification::where('user_id', $user->id)
            ->whereNull('verified_at')
            ->latest()
            ->first();

        // Still locked
        if (
            $verification &&
            $verification->locked_until &&
            $verification->locked_until->isFuture()
        ) {
            return response()->json([
                'message' => 'Too many incorrect attempts. Please wait until the lock expires before requesting a new code.',
                'locked_until' => $verification->locked_until,
            ], 429);
        }

        // Generate new code
        $code = random_int(100000, 999999);

        // Create new verification record
        EmailVerification::create([
            'user_id' => $user->id,
            'code' => $code,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ]);

        // Send new code
        Mail::to($user->email)->send(
            new EmailVerificationCode($code)
        );

        return response()->json([
            'message' => 'A new verification code has been sent to your email.',
        ]);
    }
}
