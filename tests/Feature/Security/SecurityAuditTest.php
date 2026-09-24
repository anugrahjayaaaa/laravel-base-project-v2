<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\CheckAccountState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Security penetration test suite for User Management & Authentication.
 *
 * Covers:
 *  A. Brute-force & rate limiting (login, forgot-password, resend)
 *  B. Privilege escalation / IDOR / BOLA
 *  C. Session invalidation on state change
 *  D. Signed URL tampering
 *  E. SQL injection / mass assignment
 *  F. XSS stored/reflected
 *  G. CSRF on state-changing forms
 *  H. Password policy
 *  I. Information disclosure
 */
class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::flush();
    }

    protected function makeUsers(array $overrides = []): array
    {
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'api']);
        $admin = User::factory()->create(array_merge([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'is_active' => true,
        ], $overrides['admin'] ?? []));
        $admin->assignRole($adminRole);

        $regular = User::factory()->create(array_merge([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'is_active' => true,
        ], $overrides['regular'] ?? []));

        return [$admin, $regular];
    }

    protected function actingAsApi(User $user, array $abilities = ['*']): void
    {
        Sanctum::actingAs($user, $abilities);
    }

    protected function mockRequest(User $user, string $routeName = 'api.v1.users.index'): Request
    {
        $route = new Route('GET', '/test', fn() => response()->json([]));
        $route->name($routeName);
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setRouteResolver(fn() => $route);
        $request->setUserResolver(fn() => $user);
        return $request;
    }

    // =====================================================================
    // A. BRUTE-FORCE & RATE LIMITING
    // =====================================================================

    #[Test]
    public function test_api_login_rate_limits_after_5_failed_attempts(): void
    {
        [$admin] = $this->makeUsers();
        for ($i = 0; $i < 5; $i++) {
            $resp = $this->postJson(route('api.v1.auth.login'), [
                'identifier' => $admin->email,
                'password' => 'wrong',
            ]);

            echo "\nDEBUG Request " . ($i + 1) . ": status=" . $resp->status() . " body=" . $resp->getContent();
            $resp->assertStatus(401);
        }

        $resp = $this->postJson(route('api.v1.auth.login'), [
            'identifier' => $admin->email,
            'password' => 'wrong',
        ]);
        
        echo "\nDEBUG Request 6: status=" . $resp->status() . " body=" . $resp->getContent();
        $resp->assertStatus(429);
    }

    #[Test]
    public function test_api_login_does_not_enumerate_user_existence(): void
    {
        User::factory()->create(['email' => 'existing@example.com', 'email_verified_at' => now()]);
        $exists = $this->postJson(route('api.v1.auth.login'), [
            'identifier' => 'existing@example.com',
            'password' => 'wrong',
        ]);
        $notExists = $this->postJson(route('api.v1.auth.login'), [
            'identifier' => 'nobody@example.com',
            'password' => 'wrong',
        ]);
        $this->assertEquals($exists->json('data.message'), $notExists->json('data.message'));
    }

    #[Test]
    public function test_forgot_password_rate_limited_per_identifier(): void
    {
        [$admin] = $this->makeUsers();
        // First 3 requests should succeed
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(route('api.v1.auth.password.forgot'), [
                'email' => $admin->email,
            ])->assertStatus(200);
        }
        // 4th request should be rate limited
        $this->postJson(route('api.v1.auth.password.forgot'), [
            'email' => $admin->email,
        ])->assertStatus(429);
    }

    #[Test]
    public function test_resend_verification_rate_limited_per_user(): void
    {
        [$admin, $regular] = $this->makeUsers();
        $this->actingAsApi($admin);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson(route('api.v1.auth.email.resend'), ['email' => $regular->email]);
        }
        $this->postJson(route('api.v1.auth.email.resend'), [
            'email' => $regular->email,
        ])->assertStatus(429);
    }

    // =====================================================================
    // B. PRIVILEGE ESCALATION / IDOR / BOLA
    // =====================================================================

    #[Test]
    public function test_regular_user_can_view_other_users_via_api_idor(): void
    {
        [$admin, $regular] = $this->makeUsers();
        $target = User::factory()->create(['name' => 'Target']);
        $this->actingAsApi($regular);
        $this->getJson(route('api.v1.users.show', $target->id))
            ->assertStatus(200)
            ->assertJsonPath('data.user.name', 'Target');
    }

    #[Test]
    public function test_regular_user_can_crud_other_users_via_api(): void
    {
        [$admin, $regular] = $this->makeUsers();
        $this->actingAsApi($regular);

        // Create user
        $this->postJson(route('api.v1.users.store'), [
            'name' => 'Hacker',
            'username' => 'hacker',
            'email' => 'hacker@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertStatus(201);

        // List users
        $this->getJson(route('api.v1.users.index'))->assertStatus(200);

        // Update another user
        $target = User::factory()->create();
        $this->putJson(route('api.v1.users.update', $target->id), [
            'name' => 'Hijacked',
            'email' => $target->email,
            'status' => 'inactive',
        ])->assertStatus(200);

        // Delete another user
        $victim = User::factory()->create();
        $this->deleteJson(route('api.v1.users.destroy', $victim->id))->assertStatus(200);
    }

    #[Test]
    public function test_unauthenticated_access_to_protected_api_returns_401(): void
    {
        $this->getJson(route('api.v1.users.index'))->assertStatus(401);
        $this->postJson(route('api.v1.auth.logout'))->assertStatus(401);
    }

    // =====================================================================
    // C. SESSION INVALIDATION ON STATE CHANGE
    // =====================================================================

    #[Test]
    public function test_api_token_revoked_when_user_deactivated(): void
    {
        [$admin, $regular] = $this->makeUsers();
        $this->actingAsApi($regular);
        $this->getJson(route('api.v1.profile.show'))->assertStatus(200);

        $this->actingAsApi($admin);
        $this->postJson(route('api.v1.users.deactivate', $regular->id));

        $this->actingAsApi($regular);
        $this->getJson(route('api.v1.profile.show'))->assertStatus(403);
    }

    #[Test]
    public function test_api_token_revoked_when_user_locked(): void
    {
        [$admin, $regular] = $this->makeUsers();
        $this->actingAsApi($regular);
        $this->getJson(route('api.v1.profile.show'))->assertStatus(200);

        $this->actingAsApi($admin);
        $this->postJson(route('api.v1.users.lock', $regular->id));

        $this->actingAsApi($regular);
        $this->getJson(route('api.v1.profile.show'))->assertStatus(403);
    }

    #[Test]
    public function test_api_token_revoked_when_user_soft_deleted(): void
    {
        [$admin, $regular] = $this->makeUsers();
        $this->actingAsApi($regular);
        $this->getJson(route('api.v1.profile.show'))->assertStatus(200);

        $this->actingAsApi($admin);
        $this->deleteJson(route('api.v1.users.destroy', $regular->id));

        $this->actingAsApi($regular);
        $this->getJson(route('api.v1.profile.show'))->assertStatus(403);
    }

    #[Test]
    public function test_web_session_invalidation_on_deactivation_via_checkaccountstate(): void
    {
        [$admin, $regular] = $this->makeUsers();
        $middleware = new CheckAccountState();

        $request = $this->mockRequest($regular, 'api.v1.users.index');
        $this->assertEquals(200, $middleware->handle($request, fn($req) => response()->json(['ok' => true]))->getStatusCode());

        $regular->update(['is_active' => false]);

        $request = $this->mockRequest($regular, 'api.v1.users.index');
        $this->assertEquals(403, $middleware->handle($request, fn($req) => response()->json(['ok' => true]))->getStatusCode());
    }

    // =====================================================================
    // D. SIGNED URL TAMPERING
    // =====================================================================

    #[Test]
    public function test_email_verify_change_rejects_tampered_token(): void
    {
        [$admin] = $this->makeUsers();
        $this->actingAsApi($admin);
        $target = User::factory()->create(['email_verified_at' => null]);

        $this->getJson(route('api.v1.email.verify-change', [
            'user' => $target->id,
            'token' => 'fake-token-12345',
        ]))->assertStatus(400);
    }

    #[Test]
    public function test_email_verify_change_rejects_missing_token(): void
    {
        [$admin] = $this->makeUsers();
        $this->actingAsApi($admin);
        $target = User::factory()->create();

        $this->getJson(route('api.v1.email.verify-change', ['user' => $target->id]))
            ->assertStatus(400);
    }

    #[Test]
    public function test_signed_url_tampering_rejected(): void
    {
        [$admin] = $this->makeUsers();
        $this->actingAsApi($admin);
        $target = User::factory()->create();

        $url = url()->signedRoute('api.v1.email.verify-change', [
            'user' => $target->id,
            'token' => 'test-token',
        ], now()->addMinutes(60));

        $this->getJson($url . '&tampered=1')->assertStatus(403);
    }

    // =====================================================================
    // E. SQL INJECTION / MASS ASSIGNMENT
    // =====================================================================

    #[Test]
    public function test_user_index_sort_whitelist_prevents_sql_injection(): void
    {
        [$admin] = $this->makeUsers();
        $this->actingAsApi($admin);

        $this->getJson(route('api.v1.users.index') . '?sort=id;DROP+TABLE+users--')
            ->assertStatus(422); // Validation rejects invalid sort

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);
    }

    #[Test]
    public function test_mass_assignment_protected_on_user_create(): void
    {
        [$admin] = $this->makeUsers();
        $this->actingAsApi($admin);

        $this->postJson(route('api.v1.users.store'), [
            'name' => 'Hacker',
            'username' => 'hacker',
            'email' => 'hacker@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'is_active' => false,
            'is_locked' => true,
            'must_change_password' => false,
        ])->assertStatus(201);

        $user = User::where('email', 'hacker@test.com')->first();
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->is_locked);
    }

    // =====================================================================
    // F. XSS — STORED & REFLECTED
    // =====================================================================

    #[Test]
    public function test_stored_xss_payload_in_user_name(): void
    {
        [$admin] = $this->makeUsers();
        $this->actingAsApi($admin);

        $xssPayload = '<script>alert("XSS")</script>';
        $target = User::factory()->create();

        $this->putJson(route('api.v1.users.update', $target->id), [
            'name' => $xssPayload,
            'email' => $target->email,
            'status' => 'active',
        ])->assertStatus(200);

        $user = User::find($target->id);
        // After sanitization, script tags must be stripped
        $this->assertStringNotContainsString('<script>', $user->name);
    }

    #[Test]
    public function test_reflected_xss_in_search_query_escaped(): void
    {
        [$admin] = $this->makeUsers();
        $this->actingAsApi($admin);

        $payload = '<img+src=x+onerror=alert(1)>';
        $response = $this->getJson(route('api.v1.users.index') . '?search=' . urlencode($payload));

        $response->assertStatus(200);
        $this->assertStringNotContainsString('onerror', $response->getContent());
    }

    // =====================================================================
    // G. CSRF PROTECTION
    // =====================================================================

    #[Test]
    public function test_web_state_changing_forms_require_csrf(): void
    {
        [$admin, $regular] = $this->makeUsers();
        $this->actingAs($admin, 'web');

        $this->post(route('users.deactivate', $regular->id), [])
            ->assertStatus(419);
    }

    #[Test]
    public function test_api_routes_use_sanctum_not_csrf(): void
    {
        [$admin, $regular] = $this->makeUsers();
        $this->actingAsApi($admin);

        $this->postJson(route('api.v1.users.deactivate', $regular->id))->assertStatus(200);
    }

    // =====================================================================
    // H. PASSWORD POLICY
    // =====================================================================

    #[Test]
    public function test_temp_password_minimum_length_enforced(): void
    {
        [$admin] = $this->makeUsers();
        $this->actingAsApi($admin);
        $target = User::factory()->create();

        $this->putJson(route('api.v1.users.update', $target->id), [
            'name' => $target->name,
            'email' => $target->email,
            'status' => 'active',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    #[Test]
    public function test_must_change_password_bypass_blocks_access(): void
    {
        [$admin] = $this->makeUsers();
        $user = User::factory()->create(['must_change_password' => true]);
        $this->actingAsApi($user);
        $this->getJson(route('api.v1.profile.show'))->assertStatus(403);
    }

    // =====================================================================
    // I. INFORMATION DISCLOSURE
    // =====================================================================

    #[Test]
    public function test_error_responses_do_not_leak_stack_traces(): void
    {
        [$admin] = $this->makeUsers();
        $this->actingAsApi($admin);

        $response = $this->getJson('/nonexistent-endpoint-12345');
        $content = $response->json();

        $this->assertNull($content['trace'] ?? null);
        $this->assertNull($content['file'] ?? null);
        $this->assertNull($content['line'] ?? null);
    }
}
