<?php

use App\Services\ApiResponse\ApiResponseFormatter;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponseFormatter::format($e, 422, 'Error de validación'),
                $e instanceof ModelNotFoundException => ApiResponseFormatter::format($e, 404, 'Recurso no encontrado'),
                $e instanceof AuthenticationException => ApiResponseFormatter::format($e, 401, 'No autenticado'),
                $e instanceof HttpExceptionInterface => ApiResponseFormatter::format($e, $e->getStatusCode(), 'Error inesperado'),
                default => ApiResponseFormatter::format($e, 500, 'Error inesperado'),
            };
        });
    })->create();
