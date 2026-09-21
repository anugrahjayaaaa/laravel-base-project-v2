<?php

namespace Tests\Feature\Api\V1\User;

use App\Http\Middleware\CheckAccountState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class CheckAccountStateMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_allows_active_user(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_locked' => false]);
        $middleware = new CheckAccountState();
        $request = $this->makeRequest($user);

        $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]));
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_blocks_inactive_user(): void
    {
        $user = User::factory()->create(['is_active' => false, 'is_locked' => false]);
        $middleware = new CheckAccountState();
        $request = $this->makeRequest($user);

        $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_blocks_locked_user(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_locked' => true]);
        $middleware = new CheckAccountState();
        $request = $this->makeRequest($user);

        $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_allows_exempt_routes(): void
    {
        $user = User::factory()->create(['is_active' => false, 'is_locked' => true]);
        $middleware = new CheckAccountState();
        $request = $this->makeRequest($user, 'login');

        $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]));
        $this->assertEquals(200, $response->getStatusCode());
    }

    private function makeRequest(User $user, ?string $routeName = null): Request
    {
        $route = new Route('POST', '/test', fn () => response()->json([]));
        if ($routeName) {
            $route->name($routeName);
        }

        $request = Request::create('/test', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}