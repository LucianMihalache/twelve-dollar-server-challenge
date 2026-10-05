<?php

use App\Http\Controllers\FeedController;
use Illuminate\Support\Facades\Route;

// The five endpoints of SPEC.md, in Laravel's "api" middleware group (no prefix: the spec's paths start at /).
Route::get('/health', [FeedController::class, 'health']);
Route::get('/feed', [FeedController::class, 'feed']);
Route::get('/posts/{id}', [FeedController::class, 'show']);

Route::middleware('jwt')->group(function () {
    Route::post('/posts', [FeedController::class, 'store']);
    Route::post('/posts/{id}/like', [FeedController::class, 'like']);
});
