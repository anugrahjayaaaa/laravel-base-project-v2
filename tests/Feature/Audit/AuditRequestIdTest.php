<?php

namespace Tests\Feature\Audit;

use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\Concerns\AuditsSystemActivity;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Every audit row carries the correlation ID of the request that wrote it
 * (Phase 10, P10-D1/D2).
 *
 * Finding F1: `GenerateRequestCorrelationId` bound `request_id` and echoed it on
 * the `X-Request-ID` header, and nine controllers put it in the API error
 * envelope — but `auditContext()` never wrote it into `properties`. The one piece
 * of metadata that would let an operator holding a 403 body tie that request to
 * the rows it caused was the one piece missing.
 *
 * ## Two halves, and the second is the one that bites
 *
 * An HTTP mutation must carry the SAME id as its response header. A queued job
 * must carry `null` — and must not blow up getting there, because the job has no
 * request in scope. Reading `app('request_id')` without a `bound()` guard throws
 * from inside the audit write, which fails the scheduled job at exactly the
 * moment it tried to record why something happened. The HTTP half alone would
 * pass with that bug present; nothing else in the suite runs a job outside a
 * request.
 */
class AuditRequestIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The project ships a CSRF middleware that enforces inside feature tests,
        // so a POST needs it out of the way — same as `NotificationBenchmarkTest`.
        // Nothing here is about CSRF; it is about what the audit row recorded.
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function properties(?int $id = null): array
    {
        $row = DB::table(config('activitylog.table_name', 'activity_log'))
            ->when($id, fn ($q) => $q->where('id', $id))
            ->orderByDesc('id')
            ->first();

        return json_decode($row->properties ?? '{}', true) ?: [];
    }

    #[Test]
    public function test_an_http_mutation_row_carries_the_request_id_from_its_own_response_header(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(RoleLookup::find('admin'));

        $target = User::factory()->create();

        // A known ID travels in, so the assertion cannot pass because both sides
        // happened to be the same random UUID by luck.
        $response = $this->withHeader('X-Request-ID', 'req-test-abc123')
            ->actingAs($admin, 'web')
            ->post(route('users.lock', $target));

        $response->assertRedirect();

        $this->assertSame('req-test-abc123', $response->headers->get('X-Request-ID'));
        $this->assertSame(
            'req-test-abc123',
            $this->properties()['request_id'] ?? null,
            'the audit row does not carry the correlation ID of the request that wrote it'
        );
    }

    #[Test]
    public function test_a_row_written_with_no_request_in_scope_carries_a_null_request_id(): void
    {
        // The job is run synchronously and directly — no HTTP request, so
        // `request_id` is never bound in the container. A blind
        // `app('request_id')` here throws from inside the audit write.
        (new AuditProbeJob)->handle();

        $properties = $this->properties();

        $this->assertArrayHasKey(
            'request_id',
            $properties,
            'the key is missing entirely rather than recorded as null'
        );
        $this->assertNull($properties['request_id']);
        $this->assertSame('system', $properties['source'] ?? null);
    }

    #[Test]
    public function test_a_generated_request_id_is_captured_when_the_client_sends_none(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(RoleLookup::find('admin'));
        $target = User::factory()->create();

        $response = $this->actingAs($admin, 'web')->post(route('users.lock', $target));
        $response->assertRedirect();

        $header = $response->headers->get('X-Request-ID');

        $this->assertNotEmpty($header, 'precondition: the middleware generated an id');
        $this->assertSame($header, $this->properties()['request_id'] ?? null);
    }
}

/**
 * A stand-in for a scheduled job: no request in scope, and it audits through the
 * same system path `AuditsSystemActivity` provides.
 */
class AuditProbeJob implements ShouldQueue
{
    use AuditsSystemActivity;

    public function handle(): void
    {
        $this->audit(User::factory()->create(), 'inactivity.locked');
    }
}
