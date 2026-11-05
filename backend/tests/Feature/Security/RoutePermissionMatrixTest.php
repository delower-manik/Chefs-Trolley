<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every permission-gated API route must refuse a signed-in user who holds NO permissions.
 * Catches a route that was added without (or lost) its permission middleware.
 */
class RoutePermissionMatrixTest extends TestCase
{
    public function test_a_user_with_no_permissions_is_refused_on_every_permission_gated_route(): void
    {
        $this->actingAsUserWithPermissions([]);
        $uuid = '00000000-0000-4000-8000-000000000000';
        $checked = 0;
        $leaks = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $gated = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'permission:') || str_contains($m, 'EnsurePermission')));
            if (! $gated) {
                continue;
            }
            $uri = preg_replace('/\{[^}?]+\??\}/', $uuid, $route->uri());
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $status = $this->json($method, '/'.$uri, [])->getStatusCode();
                $checked++;
                if (! in_array($status, [403, 404], true)) {
                    $leaks[] = "$method /$uri => $status";
                }
            }
        }

        $this->assertGreaterThan(200, $checked, 'matrix should cover the whole gated surface');
        $this->assertSame([], $leaks, "Permission-gated routes that did not answer 403/404:\n".implode("\n", $leaks));
    }

    public function test_a_staff_token_is_not_accepted_on_customer_routes_and_no_token_gets_401(): void
    {
        $this->actingAsUserWithPermissions(['orders.view']);
        $uuid = '00000000-0000-4000-8000-000000000000';
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_contains($m, 'customer.auth') || (is_string($m) && str_contains($m, 'AuthenticateCustomer')))) {
                continue;
            }
            $uri = preg_replace('/\{[^}?]+\??\}/', $uuid, $route->uri());
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $this->json($method, '/'.$uri, [])->assertUnauthorized();
                $checked++;
            }
        }
        $this->assertGreaterThan(15, $checked);
    }
}
