<?php

use App\Exceptions\OrderRuleException;
use App\Http\Middleware\AuthenticateCustomer;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\EnsureBranchScope;
use App\Http\Middleware\EnsureEmployee;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRider;
use App\Http\Middleware\EnsureRouteModelsInBranch;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Pure bearer-token API: deliberately NOT using Sanctum's
        // EnsureFrontendRequestsAreStateful. That middleware turns on
        // cookie sessions + CSRF checks for any request whose Origin is a
        // "stateful" domain, which would make every browser POST from the
        // Next.js app fail with 419 even though it sends a bearer token.

        $middleware->api(append: [SecurityHeaders::class]);

        // Behind nginx / a load balancer / Cloudflare set TRUSTED_PROXIES (comma list of IPs/CIDRs, or "*" only if the app
        // is reachable exclusively through the proxy) so $request->ip() is the real client and rate limits work per user.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        $middleware->alias([
            'permission' => EnsurePermission::class,
            'session.active' => EnsureActiveSession::class,
            'branch.scope' => EnsureBranchScope::class,
            'branch.models' => EnsureRouteModelsInBranch::class,
            'feature' => EnsureFeature::class,
            'customer.auth' => AuthenticateCustomer::class,
            'rider' => EnsureRider::class,
            'employee' => EnsureEmployee::class,
        ]);

        // Object-level branch checks need the route's models already bound.
        $middleware->appendToPriorityList(SubstituteBindings::class, EnsureRouteModelsInBranch::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Predictable error envelope for every unhandled exception, matching
        // the {code, message, errors?} shape the frontend ApiError expects.
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'code' => 'validation_error',
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['code' => 'unauthenticated', 'message' => 'Please sign in.'], 401);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['code' => 'not_found', 'message' => 'The requested resource was not found.'], 404);
            }
        });

        $exceptions->render(function (OrderRuleException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], 422);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['code' => 'not_found', 'message' => 'The requested resource was not found.'], 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if ($request->is('api/*')) {
                $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                return response()->json([
                    'code' => 'too_many_requests',
                    'message' => 'Too many attempts. Please try again later.',
                    'retryAfter' => $retryAfter,
                ], 429, $e->getHeaders());
            }
        });
    })->create();
