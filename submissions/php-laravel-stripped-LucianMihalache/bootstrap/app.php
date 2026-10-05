<?php

use App\Http\Controllers\FeedController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // The routes are loaded bare: no "web" or "api" middleware group, no prefix.
        using: fn () => Route::group([], base_path('routes/api.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Nothing runs around a request: Laravel's default global middleware (trusted proxies, CORS, maintenance
        // mode, post size, trimming strings, empty strings to null) has no job in this API. The controller trims
        // and validates the one field there is.
        $middleware->use([]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error is {"error":"…"}: a wrong method on a known path reads as not found, anything else as 500.
        $exceptions->render(fn (Throwable $e) => $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500
            ? FeedController::error(404, 'not found')
            : FeedController::error(500, 'internal server error'));
    })->create();
