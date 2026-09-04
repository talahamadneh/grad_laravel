<?php

namespace App\Http\Controllers;

use App\Models\JobCategory;

class JobCategoryController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => JobCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'description']),
        ]);
    }
}
