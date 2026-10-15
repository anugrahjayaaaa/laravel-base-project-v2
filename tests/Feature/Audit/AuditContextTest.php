<?php

namespace Tests\Feature\Audit;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Metadata derived from a request that is not there (Phase 10, P10-D1 follow-up).
 *
 * ## What this pins
 *
 * `auditContext()` derives `source`, `ip` and `user_agent` from the request. A
 * queued job and an artisan command both run with a SYNTHETIC request object
 * whose values are defaults rather than facts — `is('api/*')` is false, so the
 * row said `source => web`; `ip()` returns `127.0.0.1` rather than null. A
 * scheduler's work, filed in the audit trail under a channel that a human
 * pressed a button for, from an address nobody was at.
 *
 * That was a CONVENTION problem, not a bug: the two system writers
 * (`PasswordExpirySweep`, `InactivityLock`) each used
 * `App\Jobs\Concerns\AuditsSystemActivity`, which passes `source => system` and
 * overrides the context. Nothing stopped the next job from forgetting it, and
 * nothing would have noticed when it did.
 *
 * The fix is in the derivation rather than in a list of who remembered: with no
 * request in scope, `source` is `system` and the address fields are null. The
 * concern still exists and still wins — it just stopped being load-bearing for
 * the one field it was guarding.
 *
 * ## The two discriminators that do not work
 *
 * `runningInConsole()` is true for the test runner, so it would label every row
 * written inside a feature test as `system` and the suite would stop testing
 * anything real. `server('REQUEST_METHOD')` is `'GET'` in console too, because
 * Laravel builds that request with `Request::create()`. The current route is
 * the signal that survives both — and it is also the truthful answer for a `sync`
 * job, which does run inside the web request that dispatched it.
 */
class AuditContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(): array
    {
        $row = DB::table(config('activitylog.table_name', 'activity_log'))
            ->orderByDesc('id')
            ->first();

        // json_decode, not a cast: the column is a JSON STRING, and `(array)` on
        // a string yields `[0 => '{…}']` — which reads as "no `source` key" and
        // makes a working derivation look broken.
        return json_decode($row->properties ?? '{}', true) ?: [];
    }

    /**
     * The case that used to be wrong: a writer with no request in scope and no
     * override.
     *
     * `$user->audit(...)` directly — not through HTTP, not through
     * `AuditsSystemActivity`. Before this was fixed it recorded `source => web`
     * and `ip => 127.0.0.1`, which is a scheduled job claiming to be a browser.
     */
    public function test_a_writer_with_no_request_records_system_rather_than_web(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $target->audit('user.updated', $user);

        $properties = $this->properties();

        $this->assertSame('system', $properties['source'] ?? null);

        // `assertArrayHasKey` then `assertNull`, never `?? 'missing'` — the `??`
        // operator treats a stored null as an absent key, which is precisely the
        // distinction this test exists to draw. `$properties['ip'] ?? 'missing'`
        // reports "missing" about a value that is right there being null.
        $this->assertArrayHasKey('ip', $properties, 'the key is omitted rather than recorded as null');
        $this->assertNull(
            $properties['ip'],
            'a fabricated 127.0.0.1 is not an observation'
        );

        $this->assertArrayHasKey('user_agent', $properties);
        $this->assertNull($properties['user_agent']);
    }

    /**
     * Inside a real request nothing changed — this is the 99% case and a
     * regression here would be invisible everywhere else.
     */
    public function test_a_web_request_still_records_web(): void
    {
        $admin = $this->login();
        $target = User::factory()->create();

        $this->actingAs($admin, 'web')
            ->get(route('activity-logs.index'));

        // Written from inside the request's own lifetime, which is the point:
        // the route exists at the moment the row is written.
        $target->audit('user.updated', $admin);

        $this->assertSame('web', $this->properties()['source'] ?? null);
    }

    public function test_an_api_request_still_records_api(): void
    {
        $admin = $this->login();
        $target = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson(route('api.v1.activity-logs.index'));

        $target->audit('user.updated', $admin);

        $this->assertSame('api', $this->properties()['source'] ?? null);
    }

    /**
     * The concern still works, and still wins.
     *
     * `AuditsSystemActivity` exists to label a row `system` explicitly, and it
     * does. That matters for the case the derivation cannot decide for itself: a
     * `sync`-queued job running inside a web request is correctly `web` by the
     * rule above, and only the caller knows it was the scheduler.
     */
    public function test_the_system_concern_still_overrides_the_derived_value(): void
    {
        $admin = $this->login();
        $target = User::factory()->create();

        $this->actingAs($admin, 'web')->get(route('activity-logs.index'));

        $target->audit('user.updated', null, [
            'causer' => 'SYSTEM',
            'source' => 'system',
        ]);

        $properties = $this->properties();

        $this->assertSame('system', $properties['source'] ?? null);
        $this->assertSame('SYSTEM', $properties['causer'] ?? null);
    }

    /**
     * A caller-supplied value still outranks the derivation — deliberately.
     *
     * `auditContext()` is merged FIRST, so `$properties` wins. That is the
     * documented extension mechanism and the reason the `sync`-queue case above
     * is answerable at all. Pinned here so that changing the merge order becomes
     * a visible decision rather than a silent one: flipping it would make every
     * system writer's explicit `source` unreachable.
     */
    public function test_a_caller_can_still_override_the_derived_source(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $target->audit('user.updated', $user, ['source' => 'web']);

        $this->assertSame('web', $this->properties()['source'] ?? null);
    }

    private function login(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::ADMIN));

        $this->actingAs($user, 'web');

        return $user;
    }
}
