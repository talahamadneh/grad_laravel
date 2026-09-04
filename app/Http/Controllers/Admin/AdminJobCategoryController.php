<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminJobCategoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        $categories = JobCategory::query()
            ->withCount('jobs')
            ->orderBy('name')
            ->get()
            ->map(fn (JobCategory $category) => $this->formatCategory($category));

        return response()->json([
            'data' => $categories,
            'meta' => [
                'total_categories' => $categories->count(),
                'categorized_jobs' => JobCategory::query()->withCount('jobs')->get()->sum('jobs_count'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);
        $validated = $this->validateCategory($request);
        $name = $this->normalizeName($validated['name']);
        $this->ensureNormalizedNameIsUnique($name);

        $category = JobCategory::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => $this->formatCategory($category->loadCount('jobs')),
        ], 201);
    }

    public function show(Request $request, JobCategory $category)
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'category' => $this->formatCategory($category->loadCount('jobs')),
        ]);
    }

    public function update(Request $request, JobCategory $category)
    {
        $this->authorizeAdmin($request);
        $validated = $this->validateCategory($request, $category);
        $name = $this->normalizeName($validated['name']);
        $this->ensureNormalizedNameIsUnique($name, $category->id);
        $oldName = $category->name;

        DB::transaction(function () use ($category, $validated, $name, $oldName) {
            $category->update([
                'name' => $name,
                'slug' => $name === $oldName ? $category->slug : $this->uniqueSlug($name, $category->id),
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? $category->is_active,
            ]);

            if ($name !== $oldName) {
                $category->jobs()->update(['department' => $name]);
            }
        });

        return response()->json([
            'message' => 'Category updated successfully.',
            'category' => $this->formatCategory($category->fresh()->loadCount('jobs')),
        ]);
    }

    public function destroy(Request $request, JobCategory $category)
    {
        $this->authorizeAdmin($request);

        if ($category->jobs()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a category that has jobs.',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully.']);
    }

    private function validateCategory(Request $request, ?JobCategory $category = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('job_categories', 'name')->ignore($category?->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function uniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (JobCategory::query()
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    private function ensureNormalizedNameIsUnique(string $name, ?int $exceptId = null): void
    {
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'The name field is required.']);
        }

        $exists = JobCategory::query()
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'The name has already been taken.']);
        }
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/\s+/', ' ', trim($name)) ?? '';
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_if(strtolower($request->user()?->role ?? '') !== 'admin', 403, 'Unauthorized. Admin access required.');
    }

    private function formatCategory(JobCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'is_active' => (bool) $category->is_active,
            'jobs_count' => (int) ($category->jobs_count ?? 0),
        ];
    }
}
