<?php

declare(strict_types=1);

use App\Http\Middleware\PreserveSixFactorHttpMethod;
use App\Http\Middleware\PreventApiResponseCaching;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $signedDelivery = fn (Request $request): bool => $request->is('api/internal/intelligence/deliveries');
        $middleware->trimStrings(except: [$signedDelivery]);
        $middleware->convertEmptyStringsToNull(except: [$signedDelivery]);
        $middleware->prepend(PreserveSixFactorHttpMethod::class);
        $middleware->appendToGroup('api', PreventApiResponseCaching::class);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (SymfonyResponse $response, Throwable $exception, Request $request): SymfonyResponse {
            return $request->is('api/*') ? PreventApiResponseCaching::apply($response) : $response;
        });
        $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
            if ($request->is('api/internal/intelligence/deliveries')) {
                $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503;
                $code = match ($status) {
                    400 => 'invalid_request',
                    401 => 'unauthorized',
                    405 => 'method_not_allowed',
                    413 => 'payload_too_large',
                    415 => 'unsupported_media_type',
                    429 => 'rate_limited',
                    default => 'delivery_unavailable',
                };

                return response()->json(['code' => $code], $status, [
                    ...($exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []),
                    'Cache-Control' => 'no-store',
                ]);
            }

            if ($request->is('api/internal/intelligence/recipients', 'api/internal/intelligence/recipients/*')) {
                $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503;
                $code = match ($status) {
                    400 => 'invalid_request',
                    401 => 'unauthorized',
                    404 => 'event_not_found',
                    405 => 'method_not_allowed',
                    409 => 'event_identity_conflict',
                    429 => 'rate_limited',
                    default => 'recipient_unavailable',
                };

                return response()->json(['code' => $code], $status, [
                    ...($exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []),
                    'Cache-Control' => 'no-store',
                ]);
            }

            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return response()->json([
                    'message' => 'Data yang diberikan tidak valid.',
                    'errors' => (object) $exception->errors(),
                ], 422);
            }

            if ($exception instanceof AuthenticationException) {
                $message = $exception->getMessage() === 'Unauthenticated.'
                    ? 'Silakan masuk untuk melanjutkan.'
                    : 'Email atau kata sandi tidak sesuai.';

                return response()->json(['message' => $message], 401);
            }

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $message = match ($status) {
                400 => 'Permintaan tidak valid.',
                401 => 'Email atau kata sandi tidak sesuai.',
                404 => 'Data tidak ditemukan.',
                409 => $exception instanceof ConflictHttpException ? $exception->getMessage() : 'Permintaan tidak dapat diproses.',
                429 => 'Terlalu banyak percobaan. Silakan coba lagi nanti.',
                default => $status >= 500
                    ? 'Terjadi kesalahan pada server. Silakan coba lagi.'
                    : 'Permintaan tidak dapat diproses.',
            };

            return response()->json(['message' => $message], $status,
                $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
        });
    })->create();
