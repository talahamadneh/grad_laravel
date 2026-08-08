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
                'experiences',
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
            'experiences' => $student->experiences,
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
            'experiences' => 'nullable|array',
            'experiences.*.id' => 'nullable',
            'experiences.*.position' => 'nullable|string|max:255',
            'experiences.*.title' => 'nullable|string|max:255',
            'experiences.*.company' => 'nullable|string|max:255',
            'experiences.*.start_date' => 'nullable|string|max:50',
            'experiences.*.end_date' => 'nullable|string|max:50',
            'experiences.*.description' => 'nullable|string',
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

        if (array_key_exists('headline', $validated)) {
            $student->headline = $validated['headline'];
        }

        if (array_key_exists('bio', $validated)) {
            $student->bio = $validated['bio'];
        }

        if (array_key_exists('univ', $validated)) {
            $student->university = $validated['univ'];
        }

        if (array_key_exists('major', $validated)) {
            $student->major = $validated['major'];
        }

        if (array_key_exists('graduation', $validated)) {
            $student->graduation_year = $validated['graduation'];
        }

        if (array_key_exists('gpa', $validated)) {
            $student->gpa = $validated['gpa'];
        }

        if (array_key_exists('location', $validated)) {
            $student->location = $validated['location'];
        }

        if (array_key_exists('portfolio', $validated)) {
            $student->portfolio = $validated['portfolio'];
        }

        if (array_key_exists('phone', $validated)) {
            $student->phone = $validated['phone'];
        }

        if (array_key_exists('linkedin', $validated)) {
            $student->linkedin = $validated['linkedin'];
        }

        if (array_key_exists('github', $validated)) {
            $student->github = $validated['github'];
        }

        if (array_key_exists('avatar', $validated)) {
            $student->avatar = $validated['avatar'];
        }

        if ($request->has('skills')) {
            $skillIds = [];
            $skillsInput = $request->input('skills', []);

            if (!is_array($skillsInput)) {
                $skillsInput = [];
            }

            foreach ($skillsInput as $skillName) {
                if (is_array($skillName)) {
                    $skillName = $skillName['name'] ?? '';
                }

                if (!is_string($skillName)) {
                    continue;
                }

                $skillName = trim($skillName);

                if ($skillName === '') {
                    continue;
                }

                $skill = Skill::firstOrCreate([
                    'name' => $skillName
                ]);

                $skillIds[] = $skill->id;
            }

            $student->skills()->sync($skillIds);
        }

        if ($request->has('experiences')) {
            $experiencesInput = $request->input('experiences', []);

            if (!is_array($experiencesInput)) {
                $experiencesInput = [];
            }

            $student->experiences()->delete();

            foreach ($experiencesInput as $experienceData) {
                if (!is_array($experienceData)) {
                    continue;
                }

                $position = trim(
                    (string) (
                        $experienceData['position']
                        ?? $experienceData['title']
                        ?? ''
                    )
                );

                $company = trim(
                    (string) ($experienceData['company'] ?? '')
                );

                $startDate = trim(
                    (string) ($experienceData['start_date'] ?? '')
                );

                $endDate = trim(
                    (string) ($experienceData['end_date'] ?? '')
                );

                $description = trim(
                    (string) ($experienceData['description'] ?? '')
                );

                if (
                    $position === '' &&
                    $company === '' &&
                    $description === ''
                ) {
                    continue;
                }

                $student->experiences()->create([
                    'position' => $position,
                    'company' => $company,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'description' => $description,
                ]);
            }
        }

        $student->profile_completion = $this->calculateCompletion($student);
        $student->save();

        $student->load([
            'education',
            'experiences',
            'skills'
        ]);

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
            'experiences' => $student->experiences,
            'skills' => $student->skills,
        ]);
    }

    private function calculateCompletion($student)
    {
        $fields = [
            'headline',
            'bio',
            'university',
            'major',
            'graduation_year',
            'gpa',
            'location',
            'portfolio',
            'phone',
            'linkedin',
            'github'
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