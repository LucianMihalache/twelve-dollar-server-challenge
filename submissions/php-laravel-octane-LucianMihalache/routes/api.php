<?php

use App\Http\Controllers\FeedController;
use Illuminate\Support\Facades\Route;

// The five endpoints of SPEC.md. No middleware group: there are no sessions, cookies or CSRF tokens in this API.
Route::get('/health', [FeedController::class, 'health']);
Route::get('/feed', [FeedController::class, 'feed']);
Route::get('/posts/{id}', [FeedController::class, 'show']);
Route::post('/posts', [FeedController::class, 'store']);
Route::post('/posts/{id}/like', [FeedController::class, 'like']);

Route::fallback([FeedController::class, 'notFound']);
