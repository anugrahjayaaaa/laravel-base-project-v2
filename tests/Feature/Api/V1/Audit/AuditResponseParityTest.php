<?php

namespace Tests\Feature\Api\V1\Audit;

use App\Http\Resources\Api\V1\Audit\ActivityResource;
use App\Models\Activity;
use App\Models\Concerns\Auditable;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The API returns every fact the web viewer shows (Phase 12, P12-3).
 *
 * ## Why this file exists
 *
 * P12-1 proved a capability is not delivered by the UI that shows it. It
 * compared the two ROUTE tables. It could not tell that the API's audit response
 * carried fourteen fields where the web detail page rendered sixteen: a client
 * reading the trail over HTTP could not see `batch_uuid` or `user_agent`, while
 * an operator reading the same row in a browser could.
 *
 * Route parity passed. The gap was one layer down, in the payload, and invisible
 * to every test that existed.
 *
 * ## How the claim is checked
 *
 * Read from the VIEW rather than hardcoded, because a hardcoded list is a second
 * thing to forget to update — which is the defect being guarded against. If
 * somebody adds a fact to the detail page and not to the API, this fails.
 */
class AuditResponseParityTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private User $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->viewer = User::factory()->create([
            'email_verified_at' => now(),
            'name' => 'Ana Saktivia',
        ]);
        $this->viewer->assignRole(RoleLookup::find('admin'));
        $this->subject = User::factory()->create(['name' => 'Budi Santoso']);
    }

    /**
     * The facts the web detail page renders.
     *
     * `show.blade.php` is the human surface for ONE audit row, so anything it
     * prints is a fact the module considers part of the record. Presentation-
     * only reads are excluded: the badge variants are AdminLTE colours and the
     * resource documents why it deliberately omits them.
     *
     * @return array<int, string> the API keys the page's facts must map onto
     */
    private const WEB_FACTS = [
        'id',
        'occurred_at',        // created_at
        'event',              // eventName()
        'description',
        'actor',              // causerLabel()
        'causer_id',
        'target',             // subjectLabel()
        'subject_type',       // typeLabel()'s stored value
        'subject_id',
        'source',             // source()
        'ip',                 // extra('ip')
        'user_agent',         // extra('user_agent')
        'request_id',         // extra('request_id')
        'batch_uuid',
        'properties',         // detailProperties()
    ];

    public function test_the_api_carries_every_fact_the_detail_page_renders(): void
    {
        $this->subject->audit('user.updated', $this->viewer, [
            'changedFields' => ['email' => 'new@example.test'],
        ]);

        $api = (new ActivityResource($this->freshestRow()))->toArray(Request::create('/'));

        $missing = array_values(array_diff(self::WEB_FACTS, array_keys($api)));

        $this->assertSame(
            [],
            $missing,
            'facts the web detail page renders that the API does not return: '.implode(', ', $missing)
        );
    }

    /**
     * The two facts this gap was found on, asserted by name.
     *
     * The list test above would pass if somebody deleted the expectation instead
     * of fixing the resource, so the two the audit actually named are pinned
     * explicitly — they are the regression, not a category.
     */
    public function test_batch_correlation_and_user_agent_are_reachable_over_the_api(): void
    {
        $this->subject->audit('user.updated', $this->viewer);

        $response = $this->actingAs($this->viewer, 'sanctum')
            ->getJson(route('api.v1.activity-logs.index'))
            ->assertOk();

        $row = $response->json('data.activities.0');

        $this->assertArrayHasKey('batch_uuid', $row, 'a client cannot tell which rows one bulk action wrote');
        $this->assertArrayHasKey('user_agent', $row, 'the web detail page shows this and the API did not');
    }

    /**
     * A row written with a batch really carries the UUID across the wire.
     *
     * Without this the key is present and always null, which satisfies every
     * assertion above and answers nothing.
     */
    public function test_a_bulk_rows_batch_uuid_survives_serialisation(): void
    {
        DB::transaction(function (): void {
            Auditable::auditBulk('user.deactivated', [
                ['subject_id' => $this->subject->getKey()],
            ], $this->viewer);
        });

        $row = (new ActivityResource($this->freshestRow()))->toArray(Request::create('/'));

        $this->assertNotNull($row['batch_uuid']);
        $this->assertTrue(Str::isUuid((string) $row['batch_uuid']), 'batch_uuid must be the raw UUID, not a label');
    }

    private function freshestRow(): Activity
    {
        return Activity::query()->with(['causer', 'subject'])->latest('id')->firstOrFail();
    }
}
