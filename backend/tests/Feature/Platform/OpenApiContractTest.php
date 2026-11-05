<?php

namespace Tests\Feature\Platform;

use App\Services\OpenApi\OpenApiGenerator;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    private function spec(): array
    {
        return app(OpenApiGenerator::class)->build();
    }

    public function test_committed_spec_matches_the_router(): void
    {
        $file = base_path('docs/openapi.json');
        $this->assertFileExists($file, 'Run: php artisan openapi:generate');
        $this->assertSame(
            json_encode($this->spec(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
            file_get_contents($file),
            'docs/openapi.json is out of date. Run: php artisan openapi:generate'
        );
    }

    public function test_every_api_route_is_documented_with_responses_and_security(): void
    {
        $spec = $this->spec();
        $gen = app(OpenApiGenerator::class);
        $this->assertGreaterThan(100, count($gen->apiRoutes()));
        foreach ($gen->apiRoutes() as $route) {
            $path = preg_replace('/\{(\w+)\?\}/', '{$1}', '/'.preg_replace('#^api/#', '', $route->uri()));
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $m) {
                $op = $spec['paths'][$path][strtolower($m)] ?? null;
                $this->assertNotNull($op, "$m $path is missing from the spec");
                $this->assertArrayHasKey('responses', $op);
                $this->assertArrayHasKey('operationId', $op);
            }
        }
        $ids = [];
        foreach ($spec['paths'] as $ops) {
            foreach ($ops as $op) {
                $this->assertNotContains($op['operationId'], $ids, 'operationId must be unique');
                $ids[] = $op['operationId'];
            }
        }
    }

    public function test_permissions_features_and_public_routes_are_described(): void
    {
        $p = $this->spec()['paths'];
        $this->assertSame(['settings.manage'], $p['/v1/settings']['put']['x-permission']);
        $this->assertArrayHasKey('bearerAuth', $p['/v1/settings/public']['get']['security'][0]);
        $this->assertArrayNotHasKey('security', $p['/v1/auth/login']['post']);
        $features = [];
        foreach ($p as $path => $ops) {
            if (str_contains($path, 'wifi')) {
                foreach ($ops as $op) {
                    $features = array_merge($features, $op['x-feature'] ?? []);
                }
            }
        }
        $this->assertContains('wifi', $features);
    }

    /** Tiny structural check: required keys + JSON types, enough to catch a renamed or retyped field. */
    private function assertConforms(array $schema, mixed $data, string $at = '$'): void
    {
        $type = $schema['type'] ?? null;
        $actual = match (true) {
            is_array($data) && array_is_list($data) && $data !== [] => 'array',
            is_array($data) => 'object',
            is_int($data) => 'integer',
            is_float($data) => 'number',
            is_bool($data) => 'boolean',
            is_string($data) => 'string',
            default => 'null',
        };
        if ($type === 'number' && $actual === 'integer') {
            $actual = 'number';
        }
        if ($type === 'object' && $data === []) {
            $actual = 'object';
        }
        $this->assertSame($type, $actual, "$at should be $type, got $actual");
        foreach ($schema['required'] ?? [] as $k) {
            $this->assertArrayHasKey($k, $data, "$at.$k is required");
        }
        foreach ($schema['properties'] ?? [] as $k => $sub) {
            if (array_key_exists($k, $data)) {
                $this->assertConforms($sub, $data[$k], "$at.$k");
            }
        }
    }

    public function test_real_responses_conform_to_the_contract(): void
    {
        $this->actingAsUserWithPermissions(['settings.manage']);
        $publicSchema = ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => [
            'type' => 'object', 'required' => ['businessName', 'locale', 'timezone', 'currency', 'features'], 'properties' => [
                'businessName' => ['type' => 'string'], 'locale' => ['type' => 'string'], 'timezone' => ['type' => 'string'],
                'currency' => ['type' => 'object', 'required' => ['code', 'symbol', 'decimals'], 'properties' => ['code' => ['type' => 'string'], 'symbol' => ['type' => 'string'], 'decimals' => ['type' => 'integer']]],
                'features' => ['type' => 'object', 'required' => ['gift_cards', 'campaigns', 'reservations', 'wifi'], 'properties' => ['gift_cards' => ['type' => 'boolean'], 'wifi' => ['type' => 'boolean']]],
            ],
        ]]];
        $this->assertConforms($publicSchema, $this->getJson('/api/v1/settings/public')->assertOk()->json());

        $errorSchema = ['type' => 'object', 'required' => ['code', 'message'], 'properties' => ['code' => ['type' => 'string'], 'message' => ['type' => 'string']]];
        $this->assertConforms($errorSchema, $this->putJson('/api/v1/settings', ['nope' => 1])->assertStatus(422)->json());

    }
}
