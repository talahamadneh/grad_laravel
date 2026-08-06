<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\Skill;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
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

    public function update(Request $request)
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found'
            ], 404);
        }

        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
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
            'skills' => 'nullable|array',
        ], [
            'email.unique' => 'This email is already in use by another account.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (isset($validated['email'])) {
            $user->email = $validated['email'];
        }
        $user->save();

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

        if ($request->has('skills')) {
            $skillIds = [];
            $skillsInput = $request->skills;

            if (count($skillsInput) === 1 && str_contains($skillsInput[0], '-')) {
                $skillsInput = array_map('trim', explode('-', $skillsInput[0]));
            }

            foreach ($skillsInput as $skillName) {
                if (!empty(trim($skillName))) {
                    $skill = Skill::firstOrCreate([
                        'name' => trim($skillName)
                    ]);
                    $skillIds[] = $skill->id;
                }
            }
            $student->skills()->sync($skillIds);
        }

        $student->profile_completion = $this->calculateCompletion($student);
        $student->save();

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