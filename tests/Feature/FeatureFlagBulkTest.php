<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RoleLookup;
use App\Models\User;
use App\Actions\V1\Feature\FeatureBulkToggleAction;
use App\Support\FeatureCatalog;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Laravel\Pennant\Events\FeatureEvent;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bulk flag changes — P7-F3, F4 and F6.
 *
 * The single-toggle path has its own test. What is easy to get wrong here, and
 * therefore what these assert:
 *
 *  - `from` must be read BEFORE the first write, for every flag. Read after, it
 *    is always the new value and the audit row reads as a lie that looks right.
 *  - One request is ONE `feature.bulk_toggled` row, not one row per flag, and
 *    the row must name every slug including the ones that did not move.
 *  - An undeclared slug must abort the WHOLE batch. A crafted POST naming one
 *    real slug and one invented one must change neither.
 *  - One `features.manage` gate covers both directions — the catalogue has no
 *    `features.enable_feature`, and minting one would create a permission that
 *    means nothing separately.
 */
class FeatureFlagBulkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // VerifyCsrfToken::runningUnitTests() is hardcoded false in this app,
        // so every POST here would 419 before reaching the thing under test.
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function login(string $role = SystemRole::ADMIN): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user, 'web');

        return $user;
    }

        public function test_it_disables_several_flags_in_one_request (): void
    {
        $this->login();

        $this->post(route('features.bulk-action'), [
            'action' => 'disable_feature',
            'features' => ['users', 'roles', 'settings'],
        ])->assertRedirect();

        foreach (['users', 'roles', 'settings'] as $slug) {
            $this->assertFalse(
                Feature::active($slug),
                "[{$slug}] is still on after a bulk disable"
            );
        }
    }

        public function test_it_enables_several_flags_in_one_request (): void
    {
        $this->login();
        Feature::deactivate('users');
        Feature::deactivate('settings');

        $this->post(route('features.bulk-action'), [
            'action' => 'enable_feature',
            'features' => ['users', 'settings'],
        ])->assertRedirect();

        $this->assertTrue(Feature::active('users'));
        $this->assertTrue(Feature::active('settings'));
    }

    /**
     * The whole point of the action: one request, one audit row.
     */
        public function test_one_request_writes_exactly_one_audit_row(): void
    {
        $this->login();

        $this->post(route('features.bulk-action'), [
            'action' => 'disable_feature',
            'features' => ['users', 'roles', 'permissions'],
        ])->assertRedirect();

        $rows = DB::table('activity_log')
            ->where('event', FeatureBulkToggleAction::EVENT)
            ->get();

        $this->assertCount(1, $rows, 'a three-flag change wrote more than one audit row');

        // And nothing else was logged. Counting only the bulk event would miss a
        // stray `feature.toggled` per slug — the exact thing looping
        // FeatureToggleAction would have done.
        $this->assertSame(
            1,
            DB::table('activity_log')->count(),
            'the batch wrote audit rows beyond the single feature.bulk_toggled'
        );

        $properties = json_decode($rows[0]->properties, true);

        $this->assertSame(['users', 'roles', 'permissions'], $properties['requested']);
        $this->assertSame(3, $properties['count']);

        foreach ($properties['changed'] as $change) {
            $this->assertTrue($change['from'], 'a flag recorded a from that was not its live state');
            $this->assertFalse($change['to']);
        }
    }

    /**
     * `from` read after the write is the single most likely bug in this action:
     * every row would say from=true to=false and look perfectly plausible.
     */
        public function test_every_from_is_read_before_the_first_write(): void
    {
        $this->login();
        Feature::deactivate('roles');

        $this->post(route('features.bulk-action'), [
            'action' => 'disable_feature',
            'features' => ['users', 'roles'],
        ])->assertRedirect();

        $properties = json_decode(DB::table('activity_log')
            ->where('event', FeatureBulkToggleAction::EVENT)
            ->value('properties'), true);

        $bySlug = collect($properties['changed'])->keyBy('feature');

        $this->assertTrue($bySlug['users']['from'], 'users was ON before the batch');

        // roles was already off, so it is reported as untouched rather than as a
        // change — reading `from` after the write would have put it here with
        // from=false, to=false, a "change" that changed nothing.
        $this->assertContains('roles', $properties['unchanged']);
        $this->assertArrayNotHasKey('roles', $bySlug);
        $this->assertSame(1, count($properties['changed']));

        // `requested` echoes the caller's list verbatim. Dropping an unknown slug
        // before logging would hide a partial batch behind a clean-looking audit
        // row, so the two must account for each other exactly.
        $this->assertSame(
            ['users', 'roles'],
            $properties['requested'],
            'the audit row does not describe the request that was actually made'
        );
    }

        public function test_a_slug_outside_the_catalogue_is_refused (): void
    {
        $this->login();

        $this->post(route('features.bulk-action'), [
            'action' => 'disable_feature',
            'features' => ['users', 'not_a_real_flag'],
        ])->assertSessionHasErrors('features.1');

        $this->assertTrue(Feature::active('users'), 'a refused batch still changed a flag');
    }

    /**
     * The whole batch must abort, not just the bad slug. Half-applying a change
     * the operator asked for as one action is the worst outcome: they see an
     * error and have no idea which half landed.
     */
        public function test_an_undeclared_slug_changes_nothing_in_the_batch (): void
    {
        $this->login();

        $response = $this->post(route('features.bulk-action'), [
            'action' => 'disable_feature',
            'features' => ['users', 'roles', 'nope'],
        ]);

        $response->assertSessionHasErrors();

        $this->assertTrue(Feature::active('users'));
        $this->assertTrue(Feature::active('roles'));
        $this->assertSame(
            0,
            DB::table('activity_log')
                ->where('event', FeatureBulkToggleAction::EVENT)
                ->count(),
            'a refused batch still wrote an audit row'
        );
    }

        public function test_an_unknown_action_is_refused (): void
    {
        $this->login();

        $this->post(route('features.bulk-action'), [
            'action' => 'delete_feature',
            'features' => ['users'],
        ])->assertSessionHasErrors('action');

        $this->assertTrue(Feature::active('users'));
    }

        public function test_a_caller_without_the_manage_permission_is_refused (): void
    {
        $this->login(SystemRole::USER);

        $this->post(route('features.bulk-action'), [
            'action' => 'disable_feature',
            'features' => ['users'],
        ])->assertForbidden();

        $this->assertTrue(Feature::active('users'), 'an unauthorized POST still changed a flag');
    }

        public function test_a_duplicate_slug_is_applied_once (): void
    {
        $this->login();

        $this->post(route('features.bulk-action'), [
            'action' => 'disable_feature',
            // A crafted POST can repeat a slug; without de-duplication the second
            // write reads `from` as the state the first just set and the audit
            // row claims a flag changed to what it already was.
            'features' => ['users', 'users', 'users'],
        ])->assertRedirect();

        $properties = json_decode(DB::table('activity_log')
            ->where('event', FeatureBulkToggleAction::EVENT)
            ->value('properties'), true);

        $this->assertCount(1, $properties['changed'], 'a repeated slug was written more than once');
    }

    /**
     * The action's own catalogue check, reached directly.
     *
     * BulkFeatureRequest refuses an unknown slug with a validation error first,
     * so no HTTP test can get past it to the `abort_unless` inside the action.
     * That guard is what protects every OTHER caller — a queued job, a console
     * command, a future API route — none of which run form requests.
     */
        public function test_the_action_itself_refuses_an_unknown_slug (): void
    {
        $this->login();

        $this->expectException(NotFoundHttpException::class);

        try {
            app(FeatureBulkToggleAction::class)->run(['users', 'not_a_real_flag'], false);
        } finally {
            // The batch must not have applied its valid half before aborting.
            $this->assertTrue(Feature::active('users'), 'the action applied half a refused batch');
        }
    }

        public function test_the_flag_state_survives_a_reread_from_the_store (): void
    {
        $this->login();

        $this->post(route('features.bulk-action'), [
            'action' => 'disable_feature',
            'features' => ['users'],
        ])->assertRedirect();

        // Pennant caches resolved values per process; a stale cache would leave
        // the store changed while the app still serves the module.
        Feature::flushCache();

        $this->assertFalse(FeatureCatalog::isActive('users'));
    }

    /**
     * The kill switch must not make a bulk write a silent no-op.
     *
     * `disabled => true` in config forces the EFFECTIVE state false whatever the
     * store row says. An `unchanged` short-circuit keyed on the effective state
     * therefore skipped the write entirely, leaving the seeder's row in place —
     * so lifting the kill switch brought the module straight back, and the bulk
     * path disagreed with FeatureToggleAction, which always writes.
     */
        public function test_a_kill_switched_flag_is_still_written (): void
    {
        $this->login();

        config()->set('pennant.features.users.disabled', true);

        $this->assertFalse(
            FeatureCatalog::isActive('users'),
            'precondition: the kill switch forces the flag off'
        );

        $result = app(FeatureBulkToggleAction::class)->run(['users'], false);

        $this->assertNotEmpty(
            $result['changed'],
            'the bulk action skipped the write because the effective state already matched'
        );

        // The row itself is what the kill switch was hiding.
        Feature::flushCache();
        $this->assertFalse(Feature::active('users'), 'the store row was left as the seeder wrote it');

        // And lifting the kill switch must not resurrect the module.
        config()->set('pennant.features.users.disabled', false);
        $this->assertFalse(FeatureCatalog::isActive('users'), 'the module came back when the kill switch lifted');
    }

    /**
     * Both directions write, whichever way the flag currently reads.
     *
     * Guards the specific comparison the fix changed: `unchanged` must be decided
     * by the store, not by the effective state.
     */
        public function test_a_flag_already_in_the_requested_store_state_is_unchanged (): void
    {
        $this->login();

        // Already stored false.
        Feature::deactivate('users');
        Feature::flushCache();

        $result = app(FeatureBulkToggleAction::class)->run(['users'], false);

        $this->assertSame([], $result['changed']);
        $this->assertSame(['users'], $result['unchanged']);
    }

    /**
     * De-duplication belongs to the action, not only to the HTTP layer.
     *
     * A queued job or console command never runs BulkFeatureRequest, so the
     * request's `array_unique` is not enough on its own — without this the audit
     * row claimed three changes where one happened.
     */
        public function test_the_action_de_duplicates_slugs_itself (): void
    {
        $this->login();

        $result = app(FeatureBulkToggleAction::class)->run(['users', 'users', 'users'], false);

        $this->assertCount(1, $result['changed'], 'a repeated slug produced more than one change');

        $properties = json_decode(DB::table('activity_log')
            ->where('event', FeatureBulkToggleAction::EVENT)
            ->value('properties'), true);

        $this->assertSame(1, $properties['count']);
        $this->assertSame(['users'], $properties['requested']);
    }

    /**
     * `enable` must be gated exactly like `disable`.
     *
     * `authorize()` returns one boolean for both directions, so a bug that let
     * `enable_feature` through would be invisible to a test that only ever posts
     * the disable action.
     */
        public function test_the_enable_direction_is_gated_too (): void
    {
        $this->login(SystemRole::USER);

        Feature::deactivate('users');

        $this->post(route('features.bulk-action'), [
            'action' => 'enable_feature',
            'features' => ['users'],
        ])->assertForbidden();

        $this->assertFalse(Feature::active('users'), 'an unauthorised POST still enabled a flag');
    }

    /**
     * The catalogue guard must run BEFORE any write, not merely throw.
     *
     * Asserting the stored state afterwards cannot see this: the whole batch is
     * inside a transaction, so a write placed before the guard would be rolled
     * back and the store would look untouched either way. So this counts the
     * writes instead — a guard that runs first can never have written.
     */
        public function test_the_catalogue_guard_runs_before_any_write (): void
    {
        $this->login();

        $flag = Feature::deactivate('users');
        Feature::flushCache();

        $written = 0;

        // Pennant's manager is the single writer. Counting its calls proves the
        // ordering directly, where a store assertion cannot.
        Event::listen(FeatureEvent::class, function () use (&$written): void {
            $written++;
        });

        try {
            app(FeatureBulkToggleAction::class)->run(['users', 'not_a_real_flag'], false);
            $this->fail('the action accepted an undeclared slug');
        } catch (NotFoundHttpException) {
            // expected
        }

        $this->assertSame(0, $written, 'a flag was written before the catalogue check ran');
    }

    /**
     * The resolved-snapshot cache is dropped after a bulk write.
     *
     * FeatureFlagRouteTest proves this for the single-toggle route. Removing it
     * from the bulk action passed the whole suite, because every other bulk test
     * reads the store rather than the cached snapshot.
     */
        public function test_the_resolved_snapshot_cache_is_dropped (): void
    {
        $this->login();

        $this->post(route('features.bulk-action'), [
            'action' => 'disable_feature',
            'features' => ['users'],
        ])->assertRedirect();

        $this->assertNull(
            Cache::get('feature_flags.resolved'),
            'the index snapshot survived a bulk write, so /features would show stale states'
        );
    }

    /**
     * The audit row must be inside the transaction.
     *
     * An audit row that survives a rollback records a change that never
     * happened. Nothing else in the suite can see this, because every other
     * failure path here throws before the writes begin.
     */
        public function test_a_rolled_back_batch_leaves_no_audit_row (): void
    {
        $this->login();

        $auditBefore = DB::table('activity_log')->count();

        try {
            DB::transaction(function (): void {
                app(FeatureBulkToggleAction::class)->run(['users'], false);

                throw new \RuntimeException('force a rollback after the writes');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(
            $auditBefore,
            DB::table('activity_log')->count(),
            'the audit row outlived the transaction that wrote it'
        );
    }
}
