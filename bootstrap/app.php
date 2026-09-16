<?php

use App\Fleet\FleetError;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $exceptions->dontReport([FleetError::class]);
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/v1/*')) {
                return null;
            }
            $status = 500;
            $code = 'INTERNAL_ERROR';
            $message = '요청을 처리하지 못했습니다.';
            $fields = [];
            if ($e instanceof FleetError) {
                $status = $e->httpStatus;
                $code = $e->errorCode;
                $message = $e->getMessage();
            } elseif ($e instanceof ValidationException) {
                $status = 422;
                $code = 'VALIDATION_ERROR';
                $message = '입력값을 확인해 주세요.';
                $fields = $e->errors();
            } elseif ($e instanceof QueryException) {
                $status = 503;
                $code = 'DATABASE_UNAVAILABLE';
                $message = '데이터베이스 응답을 확인할 수 없습니다.';
            } elseif ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    404 => 'NOT_FOUND', 419 => 'CSRF_MISMATCH', default => 'HTTP_ERROR'
                };
                $message = match ($status) {
                    404 => '요청한 작업을 찾을 수 없습니다.', 419 => '화면을 새로고침해 주세요.', default => '요청을 처리할 수 없습니다.'
                };
            }

            return response()->json(['mode' => 'sample', 'error' => compact('code', 'message', 'fields')], $status)->header('Cache-Control', 'no-store');
        });
        $exceptions->report(function (QueryException $e) {
            if (request()->is('api/v1/*')) {
                Log::warning('Fleet database request failed', ['type' => class_basename($e)]);

                return false;
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
