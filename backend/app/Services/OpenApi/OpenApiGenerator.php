<?php

namespace App\Services\OpenApi;

use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Builds an OpenAPI 3.0 document from the live router, so the spec cannot silently drift from the routes.
 *
 * What it knows from code: paths, methods, path parameters, auth (bearer), required permission (x-permission),
 * module flag (x-feature), and request fields taken from FormRequest::rules() where a controller uses one.
 * What it does NOT know: response bodies (documented as generic envelopes), and inline $request->validate() rules.
 */
class OpenApiGenerator
{
    public function __construct(private Registrar $router) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $paths = [];
        foreach ($this->apiRoutes() as $route) {
            $path = '/'.preg_replace('#^api/#', '', $route->uri());
            $path = preg_replace('/\{(\w+)\?\}/', '{$1}', $path);
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $paths[$path][strtolower($method)] = $this->operation($route, $method, $path, count(array_diff($route->methods(), ['HEAD', 'OPTIONS'])) > 1);
            }
        }
        ksort($paths);
        foreach ($paths as &$ops) {
            ksort($ops);
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Chefs Trolley API',
                'version' => '1.0.0',
                'description' => 'Generated from the Laravel router by `php artisan openapi:generate`. Money values are BDT numbers. '
                    .'Errors use {code, message, errors?}. Response bodies are documented as generic envelopes; see the controller for exact fields.',
            ],
            'servers' => [['url' => '/api']],
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Staff token'], 'customerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Customer-website token (phone + SMS code)']],
                'schemas' => [
                    'Error' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'message' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]]]],
                    'Envelope' => ['type' => 'object', 'properties' => ['data' => []]],
                ],
            ],
            'paths' => $paths,
        ];
    }

    /** @return list<Route> */
    public function apiRoutes(): array
    {
        return array_values(array_filter(
            $this->router->getRoutes()->getRoutes(),
            fn (Route $r) => str_starts_with($r->uri(), 'api/v1/')
        ));
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, string $method, string $path, bool $multi): array
    {
        $middleware = array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->gatherMiddleware());
        $authed = (bool) array_filter($middleware, fn ($m) => str_starts_with($m, 'auth:'));
        $customer = in_array('customer.auth', $middleware, true);
        $permissions = [];
        $features = [];
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'permission:')) {
                $permissions = array_merge($permissions, explode(',', substr($m, 11)));
            }
            if (str_starts_with($m, 'feature:')) {
                $features[] = substr($m, 8);
            }
        }

        $segments = explode('/', trim($path, '/'));
        $op = [
            'operationId' => $route->getName() ? $route->getName().($multi ? '.'.strtolower($method) : '') : strtolower($method).'_'.preg_replace('/[^a-z0-9]+/i', '_', trim($path, '/')),
            'tags' => [$segments[1] ?? 'api'],
            'summary' => ucfirst(strtolower($method)).' '.$path,
        ];
        if ($params = $this->pathParams($path)) {
            $op['parameters'] = $params;
        }
        if ($authed) {
            $op['security'] = [['bearerAuth' => []]];
            $op['parameters'] = array_merge($op['parameters'] ?? [], [['name' => 'X-Branch-Id', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'uuid']]]);
        }
        if ($customer) {
            $op['security'] = [['customerAuth' => []]];
            $op['responses401'] = true;
        }
        if ($permissions) {
            $op['x-permission'] = array_values(array_unique($permissions));
        }
        if ($features) {
            $op['x-feature'] = array_values(array_unique($features));
        }
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && ($body = $this->requestBody($route))) {
            $op['requestBody'] = $body;
        }

        $err = fn (string $d) => ['description' => $d, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        $responses = ['200' => ['description' => 'Success', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]]];
        if ($authed) {
            $responses['401'] = $err('Not signed in, or the session ended');
            if ($permissions || $features) {
                $responses['403'] = $err('Not allowed, or the module is switched off');
            }
        }
        if (str_contains($path, '{')) {
            $responses['404'] = $err('Not found');
        }
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $responses['422'] = $err('Validation failed');
        }
        if ($customer) {
            $responses['401'] = $err('Not signed in');
            unset($op['responses401']);
        }
        $responses['429'] = $err('Too many requests');
        $op['responses'] = $responses;

        return $op;
    }

    /** @return list<array<string, mixed>> */
    private function pathParams(string $path): array
    {
        preg_match_all('/\{(\w+)\}/', $path, $m);

        return array_map(fn ($n) => ['name' => $n, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], $m[1]);
    }

    /** @return array<string, mixed>|null */
    private function requestBody(Route $route): ?array
    {
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            return null;
        }
        [$class, $method] = explode('@', $action);
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }
        try {
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $p) {
                $t = $p->getType();
                if ($t instanceof ReflectionNamedType && ! $t->isBuiltin() && is_subclass_of($t->getName(), FormRequest::class)) {
                    return $this->fromRules((new ($t->getName()))->rules());
                }
            }
        } catch (Throwable) {
            return null; // rules that need a live request can't be read statically; leave the body undocumented
        }

        return null;
    }

    /** @param array<string, mixed> $rules */
    private function fromRules(array $rules): array
    {
        $props = [];
        $required = [];
        foreach ($rules as $field => $rule) {
            if (str_contains($field, '.')) {
                continue; // nested/array element rules are folded into their parent
            }
            $list = is_array($rule) ? $rule : explode('|', (string) $rule);
            $flat = array_map(fn ($r) => is_string($r) ? $r : (is_object($r) ? class_basename($r) : ''), $list);
            if (in_array('required', $flat, true)) {
                $required[] = $field;
            }
            $props[$field] = $this->typeFor($flat);
        }
        $schema = ['type' => 'object', 'properties' => $props];
        if ($required) {
            $schema['required'] = $required;
        }

        return ['required' => (bool) $required, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /** @param list<string> $rules */
    private function typeFor(array $rules): array
    {
        $s = ['type' => 'string'];
        foreach ($rules as $r) {
            $s = match (true) {
                $r === 'integer' => ['type' => 'integer'],
                in_array($r, ['numeric', 'decimal'], true) || str_starts_with($r, 'decimal:') => ['type' => 'number'],
                $r === 'boolean' => ['type' => 'boolean'],
                $r === 'array' => ['type' => 'array', 'items' => []],
                $r === 'email' => ['type' => 'string', 'format' => 'email'],
                $r === 'uuid' => ['type' => 'string', 'format' => 'uuid'],
                $r === 'date' => ['type' => 'string', 'format' => 'date'],
                default => $s,
            };
            if (str_starts_with($r, 'in:')) {
                $s['enum'] = explode(',', substr($r, 3));
            }
        }
        if (in_array('nullable', $rules, true)) {
            $s['nullable'] = true;
        }

        return $s;
    }
}
