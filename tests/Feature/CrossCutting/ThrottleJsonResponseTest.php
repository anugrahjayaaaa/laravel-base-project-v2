<?php

namespace Tests\Feature\CrossCutting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RoleLookup;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Testing\TestResponse;

/**
 * A throttled request must be answered in the shape the caller asked for.
 *
 * Two paths can answer it, and they are easy to confuse. A limiter WITH a
 * custom response returns whatever that callback returns — `back()->withErrors()`
 * there is an HTML 302, which is not an error status, so a client treats the
 * throttling as success. A limiter with NO callback falls through to the
 * ThrottleRequestsException handler in bootstrap/app.php, which does answer
 * JSON, so the absence of a callback was never a live API bug.
 *
 * What was missing is `retry_after_seconds`, which the shared builder
 * (LoginThrottle::responseFor) supplies and the framework fallback does not.
 * That is what these assert, along with the web side still redirecting.
 */
class ThrottleJsonResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(SystemSettingSeeder::class);
    }

    /**
     * 429, JSON, and an actual number to wait for. The last one is the part
     * the framework's fallback handler does not provide, so it is the part
     * that proves the shared builder is the thing answering.
     */
    private function assertThrottledForJson(TestResponse $response): void
    {
        $response->assertStatus(429)->assertHeader('content-type', 'application/json');

        $body = $response->json();

        $this->assertSame('RATE_LIMITED', $body['code'] ?? null);
        $this->assertArrayHasKey('retry_after_seconds', $body);
        $this->assertGreaterThan(0, $body['retry_after_seconds']);
    }

    private function apiUser(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find('admin'));
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        return $user;
    }

    // user-state-actions is 15/minute. The route mutates the user, so the
    // first fifteen are real requests rather than a faked counter.
    public function test_user_state_actions_tells_a_json_client_when_to_retry(): void
    {
        $admin = $this->apiUser();
        $this->actingAs($admin, 'sanctum');

        $target = $this->apiUser();

        for ($i = 0; $i < 15; $i++) {
            $this->postJson(route('api.v1.users.deactivate', $target))->assertOk();
        }

        $this->assertThrottledForJson($this->postJson(route('api.v1.users.deactivate', $target)));
    }

    public function test_bulk_action_tells_a_json_client_when_to_retry(): void
    {
        $admin = $this->apiUser();
        $this->actingAs($admin, 'sanctum');

        $ids = User::factory()->count(6)->create()->pluck('id')->all();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('api.v1.users.bulk-action'), [
                'action' => 'activate',
                'user_ids' => $ids,
            ])->assertOk();
        }

        $this->assertThrottledForJson($this->postJson(route('api.v1.users.bulk-action'), [
            'action' => 'activate',
            'user_ids' => $ids,
        ]));
    }

    public function test_email_verification_tells_a_json_client_when_to_retry(): void
    {
        $admin = $this->apiUser();
        $this->actingAs($admin, 'sanctum');

        $target = User::factory()->create(['email_verified_at' => now()]);
        $target->assignRole(RoleLookup::find('admin'));

        // 5/hour. throttle:email-verification is listed before `signed`, so
        // the sixth attempt is the throttle rather than a signature refusal —
        // which is what a real flood of forged links would hit first.
        for ($i = 0; $i < 5; $i++) {
            $this->getJson(route('api.v1.email.verify-change', $target, 'bad-token'));
        }

        $this->assertThrottledForJson(
            $this->getJson(route('api.v1.email.verify-change', $target, 'bad-token'))
        );
    }

    /**
     * The same limiter, from a browser: still a redirect with a field error,
     * not the JSON body above. One builder, two shapes.
     */
    public function test_the_web_side_still_redirects(): void
    {
        $admin = $this->apiUser();
        $this->actingAs($admin);

        $target = $this->apiUser();

        for ($i = 0; $i < 15; $i++) {
            $this->post(route('users.deactivate', $target));
        }

        $this->from('/admin/users')
            ->post(route('users.deactivate', $target))
            ->assertStatus(302)
            ->assertSessionHasErrors('user');
    }
}
