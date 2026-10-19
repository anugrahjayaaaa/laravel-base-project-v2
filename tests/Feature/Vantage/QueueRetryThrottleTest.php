<?php

namespace Tests\Feature\Vantage;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The retry button has a ceiling — Phase 11 fix #3.
 *
 * `queues.retry` answers "may this person", not "how often": it is the one
 * mutating control on the queue dashboard, and without a limit a compromised
 * operator account re-dispatches work as fast as it can click. Same pairing as
 * the audit export's retry route.
 *
 * The ceiling is the operator setting, and that is asserted separately from the
 * throttle existing: a hardcoded `throttle:10,1` would pass the first test and
 * fail the second.
 *
 * Both cases post an id that does not exist. The limiter runs BEFORE the
 * controller, so the first request 404s on `findOrFail` and the second is
 * refused by the ceiling — nothing the controller does is what stops it.
 */
class QueueRetryThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function login(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::ADMIN));

        $this->actingAs($user, 'web');

        return $user;
    }

    public function test_the_retry_route_is_throttled(): void
    {
        SystemSetting::set('queue_retry_rate_limit_per_minute', '2');

        $this->login();

        // Both within the ceiling: the permission is held, so the answer is the
        // controller's 404 and not a refusal.
        $this->post(route('vantage.jobs.retry', 4242))->assertNotFound();
        $this->post(route('vantage.jobs.retry', 4242))->assertNotFound();

        // The web channel answers a throttle with a redirect and a flash, not a
        // 429 — the queue monitor renders `session('error')` and has no error
        // bag, so a field error there would reload the page saying nothing.
        $this->post(route('vantage.jobs.retry', 4242))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * The ceiling follows the setting in both directions.
     *
     * The case above uses two, this one five, and five is BELOW the seeded
     * default of ten: a hardcoded `throttle:10,1` would let the sixth request
     * through and fail here, while a hardcoded `throttle:1,1` would fail the
     * case above. Neither passes the pair by accident.
     */
    public function test_a_larger_ceiling_lets_more_requests_through(): void
    {
        SystemSetting::set('queue_retry_rate_limit_per_minute', '5');

        $this->login();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('vantage.jobs.retry', 4242))->assertNotFound();
        }

        $this->post(route('vantage.jobs.retry', 4242))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
