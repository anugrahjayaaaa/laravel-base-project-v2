<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\SystemRole;
use App\View\Composers\AppMenuComposer;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Three per-request costs that a page pays on EVERY authenticated view, and
 * that nothing else in the suite would notice going away.
 *
 * ## Why these three and not a page-level latency budget
 *
 * A millisecond budget is a number someone picked; it passes for an N+1 that
 * happens to fit and keeps passing as the catalogue grows. Each of these is a
 * COUNT against a mechanism that either exists or does not, so the assertion
 * is a fact about the code rather than a budget about the machine:
 *
 *  1. `User::can()` memoizes — /users fires 44 gate checks for 13 distinct
 *     abilities, because the action column renders `@can('users.lock')` once
 *     per row and every row answers identically.
 *  2. `AppMenuComposer` composes once — it is registered on the sidebar view,
 *     which every authenticated page includes.
 *  3. the header's `$sessionsVisible` is answered WITHOUT rebuilding the menu.
 *     Registering the full composer on the header, as it once was, ran a second
 *     complete compose() per page: eleven items and five gates, all discarded
 *     for one boolean.
 *
 * ## Why not tests/Unit
 *
 * All three are observable only through a real request: the memo is per model
 * instance, and the composer only runs when the view is rendered. A "unit" test
 * of either would assert against a hand-built instance the framework never
 * builds. This sits beside RbacPerformanceTest and FeatureFlagPerformanceTest,
 * which measure the same surfaces.
 */
class PerformanceGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A viewer holding the whole catalogue.
     *
     * NOT the superadmin: `Gate::before` short-circuits on `hasRole()` for it,
     * so a superadmin cannot distinguish a cheap gate from an expensive one —
     * the measurement would hide behind the short circuit. This viewer makes
     * every ability fall through to the real check.
     */
    private function viewerWithEveryPermission(): User
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $role = Role::create([
            'name' => 'Guard '.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);

        $role->givePermissionTo(
            \Spatie\Permission\Models\Permission::whereIn('name', PermissionCatalog::all())
                ->where('guard_name', RoleLookup::guard())
                ->get()
        );

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * Count real Gate evaluations with an `after` callback.
     *
     * `after`, not `before`: Spatie registers its own `Gate::before` that
     * returns true for a permission the viewer holds, so a counting `before`
     * added afterwards is never reached — it measured 0 for a call that plainly
     * happened. `after` runs on every raw evaluation whatever answered it, and
     * a memoized second `can()` never reaches the Gate at all, which is
     * exactly the difference being measured.
     */
    private function countGateEvaluations(): \Closure
    {
        $count = 0;

        Gate::after(function () use (&$count): void {
            $count++;
        });

        return function () use (&$count): int {
            return $count;
        };
    }

    /**
     * Test Case 1 — repeated `can()` for ONE ability evaluates the Gate once.
     *
     * The count is a DELTA rather than a ceiling on purpose. A ceiling like
     * "under 5" passes for a memo that only half works; asking the same
     * question 5 times and requiring exactly one evaluation cannot.
     */
        public function repeated_permission_checks_for_one_ability_evaluate_the_gate_once(): void
    {
        $user = $this->viewerWithEveryPermission();
        $gateEvaluations = $this->countGateEvaluations();

        $user->can('users.view');
        $oneAbility = $gateEvaluations();

        for ($i = 0; $i < 4; $i++) {
            $user->can('users.view');
        }

        $this->assertSame(1, $oneAbility, 'precondition: the first check reaches the Gate');
        $this->assertSame(
            1,
            $gateEvaluations(),
            'five can() calls for one ability evaluated the Gate '
            .($gateEvaluations() - 1).' extra times — the request-level memo is gone'
        );
    }

    /**
     * The memo is keyed on the ability, not a single shared answer.
     *
     * A memo that returned the FIRST result for every later question would pass
     * the test above and hand `users.view`'s answer to `roles.view`. Two
     * distinct abilities must therefore cost two evaluations.
     */
        public function the_memo_is_keyed_per_ability_not_shared(): void
    {
        $user = $this->viewerWithEveryPermission();
        $gateEvaluations = $this->countGateEvaluations();

        $user->can('users.view');
        $user->can('roles.view');

        $this->assertSame(2, $gateEvaluations(), 'two distinct abilities collapsed to one answer');

        // And each still answers for itself.
        $user->can('users.view');
        $user->can('roles.view');

        $this->assertSame(2, $gateEvaluations(), 're-checking both abilities re-ran the Gate');
    }

    /**
     * The documented carve-out: `can()` WITH arguments is not memoized.
     *
     * `$user->can('update', $post)` is a different question per subject, so a
     * memo keyed on the ability alone would answer for the wrong model. This
     * pins the fall-through, because it is the one place where memoizing would
     * be a correctness bug rather than a slow path.
     */
        public function a_check_with_arguments_is_not_memoized(): void
    {
        $user = $this->viewerWithEveryPermission();
        $gateEvaluations = $this->countGateEvaluations();

        $subject = User::factory()->create();

        $user->can('users.view', $subject);
        $user->can('users.view', $subject);

        $this->assertSame(
            2,
            $gateEvaluations(),
            'two subject-scoped checks collapsed into one — the memo is answering for the wrong model'
        );
    }

    /**
     * Test Case 2 — the menu composer runs EXACTLY once per authenticated page.
     *
     * Counted by binding a counting subclass in the container, because the
     * composer is registered as a class STRING and Laravel resolves it through
     * the container on every dispatch — so a bound instance is what actually
     * runs. Asserting on the binding rather than on a spy keeps the test honest
     * about the mechanism it is guarding.
     *
     * 1 is the point: not "at most", not "the query count looks fine".
     */
        public function the_menu_composer_composes_exactly_once_per_authenticated_page(): void
    {
        $runs = 0;

        $this->app->bind(AppMenuComposer::class, function () use (&$runs): AppMenuComposer {
            return new class ($runs) extends AppMenuComposer {
                public function __construct(private int &$runs)
                {
                }

                public function compose(View $view): void
                {
                    $this->runs++;

                    parent::compose($view);
                }
            };
        });

        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $admin->assignRole(SystemRole::SUPERADMIN);
        $this->actingAs($admin, 'web');

        $this->get(route('dashboard'))->assertOk();

        $this->assertSame(
            1,
            $runs,
            "the menu composed {$runs} times on one page — it is registered on a view that renders more than once"
        );
    }

    /**
     * Test Case 3 — the header gets `$sessionsVisible` without rebuilding the
     * menu, and the reordered layout means the catalogue is read ONCE.
     *
     * Two halves, because "the flag is right" and "the flag was cheap" are
     * different regressions:
     *
     *  - the header partial must be rendered with the variable BOUND, so the
     *    dropdown is not silently empty;
     *  - the page must spend exactly ONE feature-store read, which only holds
     *    because `layouts/app.blade.php` includes the SIDEBAR FIRST: its
     *    `activeMap()` warms Pennant's in-request cache, and the header's
     *    single-flag read is then answered from memory. Header-first costs two.
     *
     * Measured, flushing Pennant before each request — a guard on a warm cache
     * reports green on cold work, which is how a doubled composer once passed
     * this very assertion.
     */
        public function the_header_flag_is_bound_from_the_warmed_catalogue(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $admin->assignRole(SystemRole::SUPERADMIN);
        $this->actingAs($admin, 'web');

        Feature::flushCache();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get(route('dashboard'))->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $header = view('layouts.partials.header', [
            'currentUserName' => 'probe',
            'sessionsVisible' => true,
        ])->render();

        $this->assertStringContainsString(
            route('sessions'),
            $header,
            'precondition: a bound $sessionsVisible renders the header link'
        );

        $featureReads = collect($log)
            ->filter(fn (array $q): bool => str_contains($q['query'], 'features'))
            ->count();

        $this->assertSame(
            1,
            $featureReads,
            "a cold page view read the feature store {$featureReads} times. One is the sidebar's "
            .'single `WHERE name IN (...)` for the whole catalogue, with the header answered from '
            .'Pennant\'s in-request cache. Two means the header was included BEFORE the sidebar; '
            .'more means something reads a flag per consumer again.'
        );

        $this->assertSame(
            1,
            substr_count($response->getContent(), 'app-sidebar'),
            'precondition: the sidebar really rendered exactly once'
        );
    }
}
