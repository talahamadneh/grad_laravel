<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Student;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Exception;

class AuthController extends Controller
{
    // ================= REGISTER =================
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
            'role' => 'required|in:student,company'
        ]);

        try {

            DB::transaction(function () use ($data, &$user) {

                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
                    'role' => $data['role'],
                ]);

                if ($data['role'] === 'student') {

                    Student::create([
                        'user_id' => $user->id
                    ]);

                } else {

                    Company::create([
                        'user_id' => $user->id,
                        'company_name' => $data['name']
                    ]);

                }

            });

            return response()->json([
                'message' => 'User registered successfully',
                'user' => $user
            ], 201);

        } catch (Exception $e) {

            return response()->json([
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);

        }
    }

   // ================= LOGIN =================
    public function login(Request $request)
    {
        // 1. تفعيل التحقق من الـ role القادم من الـ Frontend
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'role' => 'required|in:student,company,admin' 
        ]);

        $user = User::where('email', $data['email'])->first();

        // 2. التحقق من البريد الإلكتروني وكلمة المرور
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        // 3. 💡 الفحص الحاسم: منع تسجيل دخول المستخدم إذا كان الدور المحدد في الواجهة مختلفاً عن دوره الفعلي
        if (strtolower($user->role) !== strtolower($data['role'])) {
            return response()->json([
                'message' => 'This account is not registered as a ' . $data['role']
            ], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'role' => $user->role
        ]);
    }

    // ================= FORGOT PASSWORD =================
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {

            return response()->json([
                'message' => __($status)
            ], 200);

        }

        return response()->json([
            'message' => __($status)
        ], 400);
    }

  
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }
}