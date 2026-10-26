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
        $this->assertSame('bearer', $this->spec['components']['securitySchemes']['bearerAuth']['scheme'] ?? null);
        $this->assertSame([['bearerAuth' => []]], $this->spec['security'] ?? null);
    }

    /**
     * Every error response the document publishes must describe the body the
     * handler actually sends (`docs/base/api/error-contract.md`).
     *
     * Scramble answers an inferred exception with `{message}`, which is not the
     * contract, and this gate used to assert the status only — so 148 responses
     * documented a body no client receives and nothing failed. `code` and `meta`
     * are the two keys that separate the envelope from the framework default.
     *
     * 503 is the one documented exception: the health probe answers with its own
     * unwrapped body on purpose, so it is exempt by name rather than by accident.
     */
    public function test_every_documented_error_response_publishes_the_contract_envelope(): void
    {
        $wrong = [];

        foreach ($this->spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                foreach ($operation['responses'] ?? [] as $status => $response) {
                    if (str_starts_with((string) $status, '2') || (string) $status === '503') {
                        continue;
                    }

                    $body = json_encode($response);

                    if (! str_contains($body, '"code"') || ! str_contains($body, '"meta"')) {
                        $wrong[] = strtoupper($method).' '.$path.' '.$status;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $wrong,
            'These error responses document a body outside the contract envelope: '.implode(', ', $wrong),
        );
    }

    /**
     * A gated operation must say what gates it.
     *
     * Scramble reads neither `can:` nor `feature:`, so before
     * `AuthorizationDescriptionExtension` the document showed a permission-gated
     * endpoint as if any token could call it. The requirement lives in the route
     * middleware; this test makes the description carry the same words.
     */
    public function test_every_gated_operation_states_its_requirement(): void
    {
        $missing = [];

        foreach ($this->apiRoutes() as $route) {
            $requirements = array_values(array_filter(
                $route->gatherMiddleware(),
                static fn (string $middleware): bool => str_starts_with($middleware, 'can:')
                    || str_starts_with($middleware, 'feature:'),
            ));

            if ($requirements === []) {
                continue;
            }

            foreach ($this->documentedMethods($route) as $method) {
                $description = $this->operation($route, $method)['description'] ?? '';

                foreach ($requirements as $requirement) {
                    if (! str_contains($description, '`'.$requirement.'`')) {
                        $missing[] = $method.' '.$this->documentedPath($route).' ('.$requirement.')';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These operations do not state the middleware that gates them: '.implode(', ', $missing),
        );
    }

    /**
     * Health is deliberately unnamed, so the route→path loop above cannot see it.
     */
    public function test_the_unnamed_health_route_is_documented_with_both_statuses(): void
    {
        $responses = $this->spec['paths']['/v1/health']['get']['responses'] ?? [];

        $this->assertArrayHasKey('200', $responses);
        $this->assertArrayHasKey('503', $responses);
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
