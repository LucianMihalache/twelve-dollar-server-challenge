<?php

use App\Http\Middleware\AuthenticateJwt;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['jwt' => AuthenticateJwt::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error is {"error":"…"} with the status and message SPEC.md gives.
        $exceptions->render(fn (ValidationException $e) => response()->json(['error' => $e->validator->errors()->first()], 400));
        $exceptions->render(fn (HttpExceptionInterface $e) => $e->getStatusCode() >= 500
            ? response()->json(['error' => 'internal server error'], 500)
            : response()->json(['error' => $e->getPrevious() instanceof ModelNotFoundException ? 'post not found' : 'not found'], 404));
        $exceptions->render(fn (Throwable $e) => response()->json(['error' => 'internal server error'], 500));
    })->create();
