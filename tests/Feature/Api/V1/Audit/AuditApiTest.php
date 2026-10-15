<?php

namespace Tests\Feature\Api\V1\Audit;

use App\Enums\AuditExportStatus;
use App\Models\Activity;
use App\Models\AuditExport;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The audit trail over the API (Phase 10 follow-up).
 *
 * ## What this pins
 *
 * The gap audit found the module was browser-only on the READ side: a client
 * that is not a browser could not see what happened or take an extract, while
 * every WRITE had been going through the shared Actions all along. So these cases
 * exist to prove the new surface inherits the guards the web one already had —
 * not to prove it reads rows.
 *
 * The three that matter most are the authorization ones, because an API that
 * returns the same table as the web UI is a copy of the most sensitive table in
 * the application: every actor's IP and user agent, on every change, forever.
 */
class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        Storage::fake('local');
    }

    private function login(RoleLookup|string $role = SystemRole::ADMIN): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_the_list_returns_rows_with_the_shape_the_web_view_shows(): void
    {
        $admin = $this->login();

        // The SUBJECT is Budi; the actor is whoever made the change. Named
        // separately because conflating the two is exactly what the resource has
        // to keep straight.
        $budi = User::factory()->create(['name' => 'Budi Santoso']);
        $budi->audit('user.updated', $admin, ['changedFields' => ['email' => 'new@example.test']]);

        $response = $this->getJson(route('api.v1.activity-logs.index'));

        $response->assertOk()
            ->assertJsonPath('data.activities.0.event', 'user.updated')
            // The MODEL's resolvers, not a second implementation: the actor and
            // the target are answered by the same methods the viewer and the CSV
            // call, so a deleted actor is named by id here too rather than
            // rendered "Anonymous".
            ->assertJsonPath('data.activities.0.actor', $admin->name)
            ->assertJsonPath('data.activities.0.target', 'Budi Santoso')
            // Raw JSON, same as the CSV writes — a client that does not know
            // this application's flattening convention can still read it.
            ->assertJsonPath('data.activities.0.properties.changedFields.email', 'new@example.test')
            ->assertJsonStructure([
                'data' => [
                    'activities' => [['id', 'occurred_at', 'event', 'description', 'actor', 'source']],
                    'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
                ],
                'meta' => ['request_id', 'timestamp'],
            ]);
    }

    public function test_filters_narrow_the_list_the_same_way_the_web_filter_bar_does(): void
    {
        $admin = $this->login();

        $target = User::factory()->create();
        $target->audit('user.updated', $admin);
        $target->audit('user.deleted', $admin);

        $this->getJson(route('api.v1.activity-logs.index', ['event' => 'user.updated']))
            ->assertOk()
            ->assertJsonCount(1, 'data.activities')
            ->assertJsonPath('data.activities.0.event', 'user.updated');
    }

    public function test_show_returns_one_row_and_404s_for_an_unknown_id(): void
    {
        $admin = $this->login();

        // Through the helper, not `create()`: `App\Models\Activity` is
        // `$guarded = ['*']`, because an audit row is evidence and nothing
        // mass-assigns one.
        $activity = activity()
            ->performedOn(User::factory()->create())
            ->causedBy($admin)
            ->withProperties(['note' => 'from the api test'])
            ->log('user.updated');

        $this->getJson(route('api.v1.activity-logs.show', $activity->getKey()))
            ->assertOk()
            ->assertJsonPath('data.activity.id', $activity->getKey());

        $this->getJson(route('api.v1.activity-logs.show', 999999))->assertNotFound();
    }

    /**
     * The id is constrained to a number, which is what keeps `/activity-logs/exports`
     * from being swallowed by `{activity}`.
     */
    public function test_a_literal_path_under_activity_logs_is_not_read_as_an_id(): void
    {
        $this->login();

        // Without `whereNumber`, this is `{activity} = 'exports'` and a 404 from
        // the SHOW action — an endpoint that looks absent rather than one that is
        // shadowed by a sibling route.
        $this->getJson('/api/v1/activity-logs/exports')
            ->assertOk()
            ->assertJsonStructure(['data' => ['exports']]);
    }

    public function test_reading_the_trail_writes_no_audit_row(): void
    {
        $admin = $this->login();
        $admin->audit('user.updated', $admin);

        $before = Activity::query()->count();

        $this->getJson(route('api.v1.activity-logs.index'))->assertOk();
        $this->getJson(route('api.v1.activity-logs.show', Activity::query()->first()->getKey()))->assertOk();

        // The row recording "someone read the trail" would be a row in the trail
        // being read. Same rule as the web viewer.
        $this->assertSame($before, Activity::query()->count());
    }

    public function test_a_user_without_audit_view_is_refused_every_audit_route(): void
    {
        Queue::fake();

        $this->login(SystemRole::USER);

        $this->getJson(route('api.v1.activity-logs.index'))->assertForbidden();
        $this->getJson(route('api.v1.activity-logs.show', 1))->assertForbidden();
        $this->getJson(route('api.v1.activity-logs.exports.index'))->assertForbidden();
        $this->postJson(route('api.v1.activity-logs.exports.store'), ['format' => 'csv'])->assertForbidden();
        $this->get(route('api.v1.activity-logs.exports.download', 1))->assertForbidden();
        $this->postJson(route('api.v1.activity-logs.exports.retry', 1))->assertForbidden();
    }

    /**
     * The retry is the web surface's twin, guards included.
     *
     * Three things have to survive the crossing: the cap, the ownership check, and
     * the rate limiter. The first two because the same Action enforces both on
     * both channels — a cap implemented in Blade would be a cap the API client
     * walks straight past, and this is the client most likely to try.
     */
    public function test_the_api_retry_is_guarded_like_the_web_one(): void
    {
        Queue::fake();

        $owner = $this->login();
        $export = AuditExport::query()->create([
            'user_id' => $owner->getKey(),
            'filters' => ['event' => 'user.updated'],
            'status' => AuditExportStatus::FAILED,
            'expires_at' => now()->addDays(7),
        ]);

        // Someone else's row, three clicks spent, and a row that is not failed:
        // the three refusals, in the order a client would hit them.
        $spent = AuditExport::query()->create([
            'user_id' => $owner->getKey(),
            'filters' => [],
            'status' => AuditExportStatus::FAILED,
            'retry_count' => AuditExport::maxRetries(),
            'expires_at' => now()->addDays(7),
        ]);

        $live = AuditExport::query()->create([
            'user_id' => $owner->getKey(),
            'filters' => [],
            'status' => AuditExportStatus::PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        $someoneElses = AuditExport::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'filters' => [],
            'status' => AuditExportStatus::FAILED,
            'expires_at' => now()->addDays(7),
        ]);

        $this->postJson(route('api.v1.activity-logs.exports.retry', $spent->getKey()))->assertStatus(409);
        $this->postJson(route('api.v1.activity-logs.exports.retry', $live->getKey()))->assertStatus(409);
        $this->postJson(route('api.v1.activity-logs.exports.retry', $someoneElses->getKey()))->assertForbidden();

        Queue::assertNothingPushed();

        // And the one that is allowed, on the same connection.
        $response = $this->postJson(route('api.v1.activity-logs.exports.retry', $export->getKey()));

        $response->assertStatus(202)
            ->assertJsonPath('data.export.status.value', AuditExportStatus::PENDING->value)
            // The budget travels with the row, so a polling client can watch it run
            // down instead of discovering the cap as a 409.
            ->assertJsonPath('data.export.retry_count', 1)
            ->assertJsonPath('data.export.max_retries', AuditExport::maxRetries())
            // False, and not by accident: the row is `pending` again, so re-running
            // it would put two workers on one file. One click spent and nothing left
            // to click is the honest reading of a job that is already running.
            ->assertJsonPath('data.export.can_retry', false);

        $this->assertDatabaseCount('audit_exports', 4);
    }

    public function test_asking_for_an_export_returns_the_row_not_a_bare_acknowledgement(): void
    {
        Queue::fake();

        $this->login();

        $response = $this->postJson(route('api.v1.activity-logs.exports.store'), [
            'format' => 'csv',
            'event' => 'user.updated',
        ]);

        // 202, not 200: the file does not exist yet, and a 200 would read as a
        // success the client has to take on trust.
        $response->assertStatus(202)
            ->assertJsonPath('data.export.status.value', AuditExportStatus::PENDING->value)
            // Which filters produced it, so five exports can be told apart
            // without the client keeping its own state.
            ->assertJsonPath('data.export.filters.event', 'user.updated')
            // No path yet — hence no download URL. A null URL is a different
            // answer from a URL that would 410.
            ->assertJsonPath('data.export.download_url', null)
            // Never the disk path: that is a private-storage location, and it
            // keeps working in a client's head after the sweep unlinks the file.
            ->assertJsonMissingPath('data.export.path');

        $this->assertDatabaseCount('audit_exports', 1);
    }

    public function test_the_export_list_is_the_viewers_own(): void
    {
        $this->login();

        $mine = AuditExport::query()->create([
            'user_id' => auth()->id(),
            'filters' => ['event' => 'user.updated'],
            'expires_at' => now()->addDays(7),
        ]);

        $other = User::factory()->create();
        AuditExport::query()->create([
            'user_id' => $other->getKey(),
            'filters' => ['event' => 'role.deleted'],
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->getJson(route('api.v1.activity-logs.exports.index'));

        $response->assertOk()
            ->assertJsonCount(1, 'data.exports')
            ->assertJsonPath('data.exports.0.id', $mine->getKey())
            ->assertDontSee('role.deleted', false);
    }

    public function test_a_ready_export_gains_a_download_url_and_an_expired_one_does_not(): void
    {
        $this->login();

        $export = AuditExport::query()->create([
            'user_id' => auth()->id(),
            'filters' => [],
            'expires_at' => now()->addDays(7),
        ]);

        $path = 'audit-exports/'.auth()->id().'/file.csv';
        $export->update(['status' => AuditExportStatus::READY, 'path' => $path, 'completed_at' => now()]);
        Storage::disk('local')->put($path, "id,event\n");

        $this->getJson(route('api.v1.activity-logs.exports.index'))
            ->assertOk()
            ->assertJsonPath('data.exports.0.download_url', route('api.v1.activity-logs.exports.download', $export));

        $export->update(['expires_at' => now()->subMinute()]);

        $this->getJson(route('api.v1.activity-logs.exports.index'))
            ->assertOk()
            ->assertJsonPath('data.exports.0.expired', true)
            ->assertJsonMissingPath('data.exports.0.download_url');
    }

    /**
     * The one IDOR surface in this module, now reachable over HTTP.
     *
     * `audit.export` answers "may this person extract the trail at all". It does
     * not answer "may this person download extract #41", and this route takes an
     * id. Without the check in `AuditExportDownloadAction`, every operator
     * holding the permission can enumerate ids and pull another operator's file
     * — which is the entire table, including the rows about the person asking.
     */
    public function test_another_operators_export_cannot_be_downloaded_over_the_api(): void
    {
        $this->login();

        $other = User::factory()->create();
        $path = 'audit-exports/'.$other->getKey().'/theirs.csv';

        $theirs = AuditExport::query()->create([
            'user_id' => $other->getKey(),
            'filters' => ['event' => 'role.deleted'],
            'status' => AuditExportStatus::READY,
            'path' => $path,
            'expires_at' => now()->addDays(7),
            'completed_at' => now(),
        ]);
        Storage::disk('local')->put($path, "id,event\nrole.deleted\n");

        $this->get(route('api.v1.activity-logs.exports.download', $theirs))->assertForbidden();

        // Still 403 after the window closes, and that ordering is deliberate:
        // ownership is checked BEFORE expiry, so an expired export belonging to
        // somebody else does not become an oracle for "that id was real". The
        // 410 below is therefore about the viewer's OWN expired file.
        $theirs->update(['expires_at' => now()->subMinute()]);
        $this->get(route('api.v1.activity-logs.exports.download', $theirs))->assertForbidden();
    }

    /**
     * 410 past the window, not 404 — the file existed and is now gone.
     *
     * A client that gets 404 looks for a typo; 410 is the status it can act on,
     * which is "ask again". 404 stays right for an id that was never there and
     * for a row whose file the nightly sweep unlinked early.
     */
    public function test_an_expired_export_answers_gone_over_the_api(): void
    {
        $this->login();

        $path = 'audit-exports/'.auth()->id().'/expired.csv';

        $mine = AuditExport::query()->create([
            'user_id' => auth()->id(),
            'filters' => [],
            'status' => AuditExportStatus::READY,
            'path' => $path,
            'expires_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        Storage::disk('local')->put($path, "id,event\n");

        $this->get(route('api.v1.activity-logs.exports.download', $mine))->assertGone();
    }

    /**
     * The ceiling is the same limiter the web route uses, so arriving over HTTP
     * is not a way to get a larger allowance.
     */
    public function test_the_export_route_shares_the_web_rate_limit(): void
    {
        Queue::fake();

        $this->login();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(route('api.v1.activity-logs.exports.store'), ['format' => 'csv'])->assertStatus(202);
        }

        $this->postJson(route('api.v1.activity-logs.exports.store'), ['format' => 'csv'])->assertStatus(429);

        $this->assertDatabaseCount('audit_exports', 5);
    }

    public function test_the_routes_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(route('api.v1.activity-logs.index'))->assertUnauthorized();
        $this->getJson(route('api.v1.activity-logs.show', 1))->assertUnauthorized();
        $this->postJson(route('api.v1.activity-logs.exports.store'), ['format' => 'csv'])->assertUnauthorized();
        $this->get(route('api.v1.activity-logs.exports.download', Str::uuid()))->assertUnauthorized();
    }
}
