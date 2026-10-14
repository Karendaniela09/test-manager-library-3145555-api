<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\AuthenticateLibraryToken;
use App\Http\Middleware\RequireLibraryRole;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'library.auth' => AuthenticateLibraryToken::class,
            'library.role' => RequireLibraryRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof \App\Exceptions\ApiException) {
                return response()->json($exception->toArray(), $exception->status);
            }

            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Los datos enviados no son válidos.',
                    'details' => collect($exception->errors())->flatMap(
                        fn (array $issues, string $field) => collect($issues)->map(
                            fn (string $issue) => ['field' => $field, 'issue' => $issue],
                        ),
                    )->values(),
                ], 422);
            }

            if ($exception instanceof \Illuminate\Database\QueryException
                && str_contains($exception->getMessage(), '23505')) {
                $constraint = $exception->errorInfo[2] ?? '';
                $code = match (true) {
                    str_contains($constraint, 'uq_book_isbn_active') => 'ISBN_TAKEN',
                    str_contains($constraint, 'uq_member_document_number') => 'DOCUMENT_TAKEN',
                    str_contains($constraint, 'uq_user_username') => 'USERNAME_TAKEN',
                    default => 'CONFLICT',
                };

                return response()->json([
                    'code' => $code,
                    'message' => 'El recurso ya existe.',
                    'details' => [],
                ], 409);
            }

            $status = method_exists($exception, 'getStatusCode')
                ? $exception->getStatusCode()
                : 500;
            $status = in_array($status, [401, 403, 404], true) ? $status : 500;

            return response()->json([
                'code' => match ($status) {
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN_ROLE',
                    404 => 'NOT_FOUND',
                    default => 'INTERNAL_ERROR',
                },
                'message' => match ($status) {
                    401 => 'Autenticación requerida.',
                    403 => 'No tiene permiso para esta operación.',
                    404 => 'El recurso solicitado no existe.',
                    default => 'Ocurrió un error interno.',
                },
                'details' => [],
            ], $status);
        });
    })->create();
