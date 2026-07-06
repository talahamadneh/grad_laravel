<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Student;
use Illuminate\Support\Facades\Validator;

class StudentController extends Controller
{
    /**
     * جلب بيانات الطالب
     */
    public function profile(Request $request)
    {
        $student = Student::where('user_id', $request->user()->id)
            ->with([
                'education',
                'experience',
                'projects',
                'certificates',
                'skills'
            ])
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        return response()->json([
            'id' => $student->id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
            'avatar' => $student->avatar,
            'headline' => $student->headline,
            'title' => $student->headline,
            'bio' => $student->bio,
            'location' => $student->location,
            'portfolio' => $student->portfolio,
            'completion' => $student->profile_completion ?? 0,
            'univ' => $student->university,
            'major' => $student->major,
            'gpa' => $student->gpa,
            'graduation' => $student->graduation_year,
            'phone' => $student->phone,
            'linkedin' => $student->linkedin,
            'github' => $student->github,
            'education' => $student->education,
            'experiences' => $student->experience,
            'projects' => $student->projects,
            'certificates' => $student->certificates,
            'skills' => $student->skills,
        ]);
    }

    /**
     * تحديث بيانات الطالب
     */
    public function update(Request $request)
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255',
            'headline' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'univ' => 'nullable|string|max:255',
            'major' => 'nullable|string|max:255',
            'graduation' => 'nullable|string|max:4',
            'gpa' => 'nullable|numeric|min:0|max:4',
            'location' => 'nullable|string|max:255',
            'portfolio' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'linkedin' => 'nullable|string|max:255',
            'github' => 'nullable|string|max:255',
            'avatar' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // تحديث الـ User
        $user = $request->user();
        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (isset($validated['email'])) {
            $user->email = $validated['email'];
        }
        $user->save();

        // تحديث الـ Student
        $student->headline = $validated['headline'] ?? $student->headline;
        $student->bio = $validated['bio'] ?? $student->bio;
        $student->university = $validated['univ'] ?? $student->university;
        $student->major = $validated['major'] ?? $student->major;
        $student->graduation_year = $validated['graduation'] ?? $student->graduation_year;
        $student->gpa = $validated['gpa'] ?? $student->gpa;
        $student->location = $validated['location'] ?? $student->location;
        $student->portfolio = $validated['portfolio'] ?? $student->portfolio;
        $student->phone = $validated['phone'] ?? $student->phone;
        $student->linkedin = $validated['linkedin'] ?? $student->linkedin;
        $student->github = $validated['github'] ?? $student->github;
        $student->avatar = $validated['avatar'] ?? $student->avatar;

        // حساب نسبة الإكمال
        $student->profile_completion = $this->calculateCompletion($student);
        $student->save();

        // جلب البيانات المحدثة مع العلاقات
        $student->load(['education', 'experience', 'projects', 'certificates', 'skills']);

        return response()->json([
            'id' => $student->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $student->avatar,
            'headline' => $student->headline,
            'title' => $student->headline,
            'bio' => $student->bio,
            'location' => $student->location,
            'portfolio' => $student->portfolio,
            'completion' => $student->profile_completion ?? 0,
            'univ' => $student->university,
            'major' => $student->major,
            'gpa' => $student->gpa,
            'graduation' => $student->graduation_year,
            'phone' => $student->phone,
            'linkedin' => $student->linkedin,
            'github' => $student->github,
            'education' => $student->education,
            'experiences' => $student->experience,
            'projects' => $student->projects,
            'certificates' => $student->certificates,
            'skills' => $student->skills,
        ]);
    }

    /**
     * حساب نسبة إكمال الملف الشخصي
     */
    private function calculateCompletion($student)
    {
        $fields = [
            'headline', 'bio', 'university', 'major', 
            'graduation_year', 'gpa', 'location', 'portfolio',
            'phone', 'linkedin', 'github'
        ];
        
        $filled = 0;
        foreach ($fields as $field) {
            if (!empty($student->$field)) {
                $filled++;
            }
        }
        
        return round(($filled / count($fields)) * 100);
    }
}