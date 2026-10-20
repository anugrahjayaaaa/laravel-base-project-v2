<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * The drift gate between `routes/api.php` and the published OpenAPI document.
 *
 * Scramble reads the code, so a route it cannot infer disappears from the spec
 * silently — the documentation still renders, it just omits an endpoint. This
 * test makes that omission fail CI on the commit that introduces it, the same
 * trick `ChannelParityTest` uses for channel parity.
 *
 * The response assertions cover what each operation can actually answer. The
 * phase plan asked for 401/403/422/500 on every operation; that is impossible
 * for the public auth routes, the health probe, and endpoints with no input, so
 * the reachable subset is asserted instead:
 *
 *   - `auth:sanctum` route → 401
 *   - `can:` route        → 403
 *   - FormRequest action  → 422
 *
 * 500 is emitted by the handler fallback and covered per status in
 * `Tests\Feature\CrossCutting\ApiErrorEnvelopeTest`, not duplicated per route.
 *
 * A *deleted* route cannot turn this red: the document is generated per
 * request, so it simply stops listing the route too. The gate protects the
 * opposite direction — a route that exists but is undocumented.
 */
class ApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, mixed>
     */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(SystemRole::ADMIN);

        $this->spec = $this->actingAs($admin)
            ->getJson('/docs/api.json')
            ->assertOk()
            ->json();
    }

    public function test_every_api_v1_route_appears_as_a_path_and_method(): void
    {
        $missing = [];

        foreach ($this->apiRoutes() as $route) {
            foreach ($this->documentedMethods($route) as $method) {
                if (! isset($this->spec['paths'][$this->documentedPath($route)][strtolower($method)])) {
                    $missing[] = $method.' '.$this->documentedPath($route);
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Routes missing from /docs/api.json: '.implode(', ', $missing),
        );
    }

    public function test_every_authenticated_operation_documents_a_401(): void
    {
        $missing = [];

        foreach ($this->apiRoutes() as $route) {
            if (! $this->hasMiddleware($route, 'auth:sanctum')) {
                continue;
            }

            foreach ($this->documentedMethods($route) as $method) {
                $responses = $this->operation($route, $method)['responses'] ?? [];

                if (! isset($responses['401'])) {
                    $missing[] = $method.' '.$this->documentedPath($route);
                }
            }
        }

        $this->assertSame([], $missing, 'Authenticated operations without a documented 401: '.implode(', ', $missing));
    }

    public function test_every_gated_operation_documents_a_403(): void
    {
        $missing = [];

        foreach ($this->apiRoutes() as $route) {
            if (! $this->hasMiddlewarePrefix($route, 'can:')) {
                continue;
            }

            foreach ($this->documentedMethods($route) as $method) {
                $responses = $this->operation($route, $method)['responses'] ?? [];

                if (! isset($responses['403'])) {
                    $missing[] = $method.' '.$this->documentedPath($route);
                }
            }
        }

        $this->assertSame([], $missing, 'Gated operations without a documented 403: '.implode(', ', $missing));
    }

    public function test_every_operation_with_a_form_request_documents_a_422(): void
    {
        $missing = [];

        foreach ($this->apiRoutes() as $route) {
            if (! $this->hasFormRequest($route)) {
                continue;
            }

            foreach ($this->documentedMethods($route) as $method) {
                $responses = $this->operation($route, $method)['responses'] ?? [];

                if (! isset($responses['422'])) {
                    $missing[] = $method.' '.$this->documentedPath($route);
                }
            }
        }

        $this->assertSame([], $missing, 'Form-request operations without a documented 422: '.implode(', ', $missing));
    }

    public function test_the_document_declares_the_bearer_scheme(): void
    {
        $this->assertSame('bearer', $this->spec['components']['securitySchemes']['http']['scheme'] ?? null);
        $this->assertSame([['http' => []]], $this->spec['security'] ?? null);
    }

    public function test_the_document_emits_no_status_outside_the_contract(): void
    {
        $allowed = ['200', '201', '202', '400', '401', '403', '404', '409', '410', '422', '429', '500', '502', '503'];
        $found = [];

        foreach ($this->spec['paths'] as $path) {
            foreach ($path as $operation) {
                foreach (array_keys($operation['responses'] ?? []) as $status) {
                    $found[(string) $status] = true;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($found), $allowed)),
            'The spec documents a status `docs/base/api/error-contract.md` does not define.',
        );
    }

    /**
     * @return array<int, Route>
     */
    private function apiRoutes(): array
    {
        $routes = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'api.v1.')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * HEAD is not documented separately; GET covers it.
     *
     * @return array<int, string>
     */
    private function documentedMethods(Route $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD']));
    }

    /**
     * Scramble strips the documented `api` prefix: `api/v1/users` → `/v1/users`.
     */
    private function documentedPath(Route $route): string
    {
        return '/'.preg_replace('#^api/#', '', $route->uri());
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, string $method): array
    {
        return $this->spec['paths'][$this->documentedPath($route)][strtolower($method)] ?? [];
    }

    private function hasMiddleware(Route $route, string $middleware): bool
    {
        return in_array($middleware, $route->gatherMiddleware(), true);
    }

    private function hasMiddlewarePrefix(Route $route, string $prefix): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (str_starts_with((string) $middleware, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function hasFormRequest(Route $route): bool
    {
        $class = $route->getControllerClass();
        $method = $route->getActionMethod();

        if ($class === null || ! method_exists($class, $method)) {
            return false;
        }

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_a($type->getName(), FormRequest::class, true)) {
                return true;
            }
        }

        return false;
    }
}
