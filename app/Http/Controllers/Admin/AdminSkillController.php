<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use App\Services\AdminActivityLogService;
use Illuminate\Http\Request;

class AdminSkillController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Skill::query()
            ->withCount(['students', 'jobPosts']);

        if ($request->filled('search')) {
            $search = $this->normalizeName($request->input('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        $skills = $query
            ->orderBy('name')
            ->paginate((int) $request->input('per_page', 20));

        $skills->getCollection()->transform(fn (Skill $skill) => $this->formatSkill($skill));

        return response()->json($skills);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $name = $this->normalizeName($validated['name']);

        if ($name === '') {
            return response()->json([
                'message' => 'The name field is required.',
            ], 422);
        }

        if ($this->nameExists($name)) {
            return response()->json([
                'message' => 'Skill already exists.',
            ], 422);
        }

        $skill = Skill::create([
            'name' => $name,
        ]);

        AdminActivityLogService::log(
            'Skill Created',
            'Skill',
            $skill->id,
            "{$skill->name} skill was created by admin.",
            'Admin',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Skill created successfully.',
            'skill' => $this->formatSkill($skill->loadCount(['students', 'jobPosts'])),
        ], 201);
    }

    public function show(Request $request, Skill $skill)
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'skill' => $this->formatSkill(
                $skill->loadCount(['students', 'jobPosts'])
            ),
            'usage' => [
                'students_count' => $skill->students_count,
                'jobs_count' => $skill->job_posts_count,
            ],
        ]);
    }

    public function update(Request $request, Skill $skill)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $name = $this->normalizeName($validated['name']);

        if ($name === '') {
            return response()->json([
                'message' => 'The name field is required.',
            ], 422);
        }

        if ($this->nameExists($name, $skill->id)) {
            return response()->json([
                'message' => 'Skill already exists.',
            ], 422);
        }

        $oldName = $skill->name;

        $skill->update([
            'name' => $name,
        ]);

        AdminActivityLogService::log(
            'Skill Updated',
            'Skill',
            $skill->id,
            "{$oldName} skill was renamed to {$skill->name} by admin.",
            'Admin',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Skill updated successfully.',
            'skill' => $this->formatSkill($skill->fresh()->loadCount(['students', 'jobPosts'])),
        ]);
    }

    public function destroy(Request $request, Skill $skill)
    {
        $this->authorizeAdmin($request);

        $skill->loadCount(['students', 'jobPosts']);

        if ($skill->students_count > 0 || $skill->job_posts_count > 0) {
            return response()->json([
                'message' => 'Skill cannot be deleted because it is currently used by students or job posts.',
                'usage' => [
                    'students_count' => $skill->students_count,
                    'jobs_count' => $skill->job_posts_count,
                ],
            ], 422);
        }

        $skillName = $skill->name;
        $skillId = $skill->id;

        $skill->delete();

        AdminActivityLogService::log(
            'Skill Deleted',
            'Skill',
            $skillId,
            "{$skillName} skill was deleted by admin.",
            'Admin',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Skill deleted successfully.',
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_if(strtolower($request->user()?->role ?? '') !== 'admin', 403, 'Unauthorized. Admin access required.');
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/\s+/', ' ', trim($name)) ?? '';
    }

    private function nameExists(string $name, ?int $exceptId = null): bool
    {
        return Skill::query()
            ->when($exceptId, fn ($query) => $query->where('id', '<>', $exceptId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    private function formatSkill(Skill $skill): array
    {
        return [
            'id' => $skill->id,
            'name' => $skill->name,
            'students_count' => (int) ($skill->students_count ?? 0),
            'jobs_count' => (int) ($skill->job_posts_count ?? 0),
            'created_at' => $skill->created_at,
            'updated_at' => $skill->updated_at,
        ];
    }
}
