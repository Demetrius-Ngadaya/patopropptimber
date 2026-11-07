<?php

use App\Exceptions\BusinessException;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnforceSessionTimeout;
use App\Http\Middleware\EnsureCustomer;
use App\Http\Middleware\EnsureDeveloper;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\ResolveBranch;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The Next.js server sits in front of the API and forwards the real client IP.
        $proxies = env('TRUSTED_PROXIES', '127.0.0.1');
        $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));

        $middleware->append(SecurityHeaders::class);
        $middleware->api(prepend: [ForceJsonResponse::class, SetLocale::class]);

        $middleware->alias([
            'permission' => CheckPermission::class,
            'staff' => EnsureStaff::class,
            'customer' => EnsureCustomer::class,
            'session.timeout' => EnforceSessionTimeout::class,
            'branch' => ResolveBranch::class,
            'activity' => LogActivity::class,
            'two_factor' => EnsureTwoFactor::class,
            'developer' => EnsureDeveloper::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson() ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontReport([BusinessException::class]);

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // let Laravel render non-API errors normally
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->validator->errors()->first() ?: __('The given data was invalid.'),
                    'errors' => $e->errors(),
                ], $e->status);
            }

            if ($e instanceof BusinessException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'code' => $e->errorCode,
                ], $e->status);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => __('Your session has ended. Please log in again.'),
                    'code' => 'UNAUTHENTICATED',
                ], 401);
            }

            if ($e instanceof ThrottleRequestsException) {
                return response()->json([
                    'success' => false,
                    'message' => __('Too many attempts. Please wait a moment and try again.'),
                    'code' => 'TOO_MANY_REQUESTS',
                ], 429, $e->getHeaders());
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $message = match ($status) {
                    403 => $e->getMessage() ?: __('You do not have permission to perform this action.'),
                    404 => __('The requested record was not found.'),
                    405 => __('This action is not allowed.'),
                    default => $e->getMessage() ?: __('Request could not be processed.'),
                };
                if ($status === 403 && in_array($e->getMessage(), ['', 'This action is unauthorized.'], true)) {
                    $message = __('You do not have permission to perform this action.');
                }

                return response()->json(['success' => false, 'message' => $message], $status, $e->getHeaders());
            }

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : __('Something went wrong on the server. The error has been logged.'),
            ], 500);
        });
    })
    ->create();
