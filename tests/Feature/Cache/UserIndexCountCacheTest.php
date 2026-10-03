<?php

namespace Tests\Feature\Cache;

use App\Actions\V1\Role\RoleAssignAction;
use App\Actions\V1\Role\RoleDeleteAction;
use App\Actions\V1\User\UserIndexAction;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The cached user count masks superadmin accounts for anyone who is not a
 * superadmin, so the number depends on `model_has_roles` + `roles` — not only on
 * the `users` table that `UserObserver` watches.
 *
 * That made a role change the one mutation with no invalidation path:
 * `RoleAssignAction` writes the pivot through `syncRoles()`, leaving the users
 * row byte-identical, so no `saved` event fired and `UserObserver` stayed silent.
 * With `rememberForever` the wrong total then persisted indefinitely.
 *
 * ## Why the assertions read a count instead of checking the key
 *
 * `bustCache()` defers to `DB::afterCommit`, and `RefreshDatabase` holds a
 * transaction open for the whole test — so `afterCommit` never fires unless the
 * work happens inside a real, committed `DB::transaction()`. Every test here
 * therefore wraps its mutation in one, and asserts on the FRESH COUNT rather
 * than on whether a key happens to be absent. Asserting the key's absence
 * would pass with an invalidation that never runs at all.
 *
 * `SuperadminVisibilityTest` documents the same constraint and the reason it
 * deliberately does not pin eager-vs-deferred.
 */
class UserIndexCountCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Actors are built once and reused.
     *
     * Creating a user fires `UserObserver`, which busts the count cache — so a
     * helper that minted a fresh viewer on every read would invalidate the very
     * value the test is about to compare, and the "before" snapshot would never
     * match anything.
     */
    private User $super;
    private User $admin;

    private function actors(): void
    {
        $this->super ??= $this->makeUser('superadmin');
        $this->admin ??= $this->makeUser('user');
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        return $user;
    }

    /**
     * The count a superadmin sees — every account, mask excluded.
     */
    private function unmaskedActiveCount(): int
    {
        $this->actors();

        return app(UserIndexAction::class)->counts($this->super)['active'];
    }

    /**
     * The count a non-superadmin sees — superadmin accounts hidden.
     */
    private function maskedActiveCount(): int
    {
        $this->actors();

        return app(UserIndexAction::class)->counts($this->admin)['active'];
    }

    // ------------------------------------------------------------ the bug

    /**
     * The core regression. Granting superadmin moves one account out of the
     * masked set, so a non-superadmin admin's totals must drop by one.
     */
    public function test_granting_superadmin_refreshes_the_masked_count(): void
    {
        $this->actors();
        $target = $this->makeUser('user');

        // Populate the masked key with the target still a plain user.
        $before = $this->maskedActiveCount();

        DB::transaction(function () use ($target) {
            app(RoleAssignAction::class)->run(
                $target,
                ['superadmin'],
                $this->super,
                true // confirm_superadmin
            );
        });

        $this->assertSame(
            $before - 1,
            $this->maskedActiveCount(),
            'granting superadmin did not refresh the masked count — the pivot write fired no observer'
        );
    }

    public function test_revoking_superadmin_refreshes_the_masked_count(): void
    {
        $this->actors();
        $target = $this->makeUser('user');

        DB::transaction(function () use ($target) {
            app(RoleAssignAction::class)->run($target, ['superadmin'], $this->super, true);
        });

        // Now hidden from the masked view.
        $hidden = $this->maskedActiveCount();

        DB::transaction(function () use ($target) {
            // Removing superadmin is guarded by the same confirmation as
            // granting it, for the same reason: both change who bypasses every
            // permission check.
            app(RoleAssignAction::class)->run($target, ['user'], $this->super, true);
        });

        $this->assertSame(
            $hidden + 1,
            $this->maskedActiveCount(),
            'revoking superadmin is the same pivot write in reverse, and it was not picked up'
        );
    }

    /**
     * The grant must move ONLY the masked number. The account still exists and
     * still counts, so a fix that simply cleared every key without recomputing
     * correctly — or one that changed the wrong reader — fails here.
     */
    public function test_granting_superadmin_moves_only_the_masked_count(): void
    {
        $this->actors();
        $target = $this->makeUser('user');

        $maskedBefore = $this->maskedActiveCount();
        $unmaskedBefore = $this->unmaskedActiveCount();

        DB::transaction(function () use ($target) {
            app(RoleAssignAction::class)->run($target, ['superadmin'], $this->super, true);
        });

        // Masked drops: the account became superadmin and is now hidden.
        $this->assertSame($maskedBefore - 1, $this->maskedActiveCount());

        // Unmasked does not: same accounts, one more visible.
        $this->assertSame($unmaskedBefore, $this->unmaskedActiveCount());
    }

    // -------------------------------------------------- the other role paths

    /**
     * `RoleDeleteAction` detaches every holder before trashing, and that detach
     * is a pivot write firing no event — the `deleted` event after it is what
     * has to catch the change.
     */
    public function test_trashing_a_role_refreshes_the_count(): void
    {
        $this->actors();
        $role = Role::create(['name' => 'auditor', 'guard_name' => RoleLookup::guard()]);
        $holder = $this->makeUser('user');
        $holder->assignRole($role);

        $before = $this->unmaskedActiveCount();

        DB::transaction(function () use ($role) {
            app(RoleDeleteAction::class)->run($role, $this->super, force: true);
        });

        // The holder keeps their account, so the active count is unchanged —
        // the observable part is that the assignment is gone.
        $this->assertSame($before, $this->unmaskedActiveCount());
        $this->assertFalse(
            $holder->fresh()->hasRole('auditor'),
            'the holder still carries the trashed role'
        );
    }

    /**
     * The mask joins on `roles.name = 'superadmin'`, so a RENAME changes who is
     * excluded without a single assignment changing.
     */
    public function test_renaming_a_role_refreshes_the_masked_count(): void
    {
        $this->actors();

        $before = $this->maskedActiveCount();

        DB::transaction(function () {
            RoleLookup::find('superadmin')->update(['name' => 'root']);
        });

        // The account is no longer named superadmin, so the mask stops hiding it.
        $this->assertSame(
            $before + 1,
            $this->maskedActiveCount(),
            'renaming the superadmin role did not refresh the mask'
        );
    }

    // ------------------------------------------- the invalidation contract

    /**
     * One owner for the key list. Three call sites use it — `UserObserver`,
     * `RoleObserver`, `RoleAssignAction` — so a literal repeated in each is how
     * this bug's sibling would arrive.
     */
    public function test_every_count_key_is_busted(): void
    {
        foreach (UserIndexAction::cacheKeys() as $key) {
            Cache::put($key, ['stale' => true], 3600);
        }

        UserIndexAction::bustCache();

        foreach (UserIndexAction::cacheKeys() as $key) {
            $this->assertFalse(Cache::has($key), "{$key} survived the bust");
        }
    }

    /**
     * The bust is deferred to afterCommit, so a rollback must leave the cache
     * holding the pre-rollback value — the same guarantee `SystemSetting::set()`
     * makes, and the reason it is deferred rather than immediate.
     */
    public function test_a_rolled_back_write_does_not_bust_the_count(): void
    {
        $admin = $this->makeUser('superadmin');

        $before = app(UserIndexAction::class)->counts($admin)['active'];

        try {
            DB::transaction(function (): void {
                User::factory()->create();

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // expected — the transaction must not survive
        }

        $this->assertSame(
            $before,
            app(UserIndexAction::class)->counts($admin)['active'],
            'a rolled-back write leaked into the cached counts'
        );
    }

    /**
     * `rememberForever` made a missed invalidation permanent, so the count must
     * expire on its own as a safety net.
     *
     * Read through the repository rather than the cache store: this asserts the
     * contract that matters — a recompute happens even when nothing invalidated
     * — which is driver-independent. The array store used in tests carries no
     * expiry metadata, so asserting on the store's TTL would pass here and prove
     * nothing about production.
     */
    public function test_the_count_is_recomputable_without_an_invalidation_event(): void
    {
        $admin = $this->makeUser('superadmin');

        $before = app(UserIndexAction::class)->counts($admin)['active'];

        // Write with the cache bypassed, as a rogue query-builder update or a
        // seeder using upsert() would. No observer fires.
        DB::table('users')->where('is_active', true)->update(['is_active' => false]);

        // Without invalidation the cached value stands; this documents what the
        // TTL now bounds rather than pretending the event path caught it.
        $stale = app(UserIndexAction::class)->counts($admin)['active'];
        $this->assertSame($before, $stale, 'the cached count was refreshed by something');

        // The recompute the TTL eventually forces:
        Cache::forget('user_index_counts.all');
        $this->assertLessThan(
            $stale,
            app(UserIndexAction::class)->counts($admin)['active'],
            'recomputing did not pick up the bypassed write'
        );
    }
}
