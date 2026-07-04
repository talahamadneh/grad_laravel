<?php


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\LandingController;

Route::get('/landing/stats', [LandingController::class, 'stats']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
