<?php

namespace Tests\Unit\Support;

use App\Exceptions\LastSuperadminException;
use App\Models\User;
use App\Support\LastSuperadmin;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The last-superadmin invariant, counted once for every caller.
 *
 * It lives in `App\Support\LastSuperadmin` because it used to live in one
 * action: `RoleAssignAction` refused to strip the role, and the three sibling
 * actions that remove a superadmin by another means (deactivate, soft delete,
 * force delete) never asked. A caller holding the three permissions and NOT the
 * superadmin role could walk the only superadmin down to zero.
 *
 * Two directions matter equally, and both are pinned here: the guard must fire
 * on the last one, and it must NOT fire while another remains — otherwise the
 * safety net becomes a lockout.
 */
class LastSuperadminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function superadmin(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'is_active' => true,
            'is_locked' => false,
        ], $attributes));
        $user->assignRole(SystemRole::SUPERADMIN);

        return $user;
    }

    public function test_the_only_active_superadmin_is_the_last_one(): void
    {
        $only = $this->superadmin();

        $this->assertTrue(LastSuperadmin::isLastActiveSuperadmin($only));
        $this->assertSame(1, LastSuperadmin::activeSuperadminCount());
    }

    public function test_nobody_is_the_last_one_while_a_second_superadmin_exists(): void
    {
        $first = $this->superadmin();
        $second = $this->superadmin();

        $this->assertFalse(LastSuperadmin::isLastActiveSuperadmin($first));
        $this->assertFalse(LastSuperadmin::isLastActiveSuperadmin($second));
        $this->assertSame(2, LastSuperadmin::activeSuperadminCount());
    }

    /**
     * A deactivated superadmin cannot administer anything, so counting them
     * would let a dormant row "save" the count while nobody can actually log in
     * — and a locked one counts, because a locked account is still a way back
     * in once it is unlocked. Both directions are the safe one here.
     */
    public function test_the_count_is_about_accounts_that_are_still_usable(): void
    {
        $live = $this->superadmin();
        $this->superadmin(['is_active' => false]);
        $this->superadmin()->delete();

        $this->assertSame(1, LastSuperadmin::activeSuperadminCount());
        $this->assertTrue(LastSuperadmin::isLastActiveSuperadmin($live));

        $live->update(['is_locked' => true]);

        $this->assertSame(
            1,
            LastSuperadmin::activeSuperadminCount(),
            'a locked superadmin stopped counting, so the guard would now allow the account to be removed'
        );
        $this->assertTrue(LastSuperadmin::isLastActiveSuperadmin($live->fresh()));
    }

    public function test_a_deactivated_or_trashed_superadmin_is_never_the_last_one(): void
    {
        $this->superadmin();

        $dormant = $this->superadmin(['is_active' => false]);
        $trashed = $this->superadmin();
        $trashed->delete();

        $this->assertFalse(
            LastSuperadmin::isLastActiveSuperadmin($dormant),
            'removing an inactive account changes nothing about who can administer the app'
        );
        $this->assertFalse(
            LastSuperadmin::isLastActiveSuperadmin($trashed),
            'a soft-deleted account cannot be the last one — deleting it again is not what strands the app'
        );
    }

    public function test_a_non_superadmin_is_never_the_last_one(): void
    {
        $this->superadmin();

        $this->assertFalse(LastSuperadmin::isLastActiveSuperadmin(User::factory()->create()));
    }

    public function test_the_guard_refuses_the_last_superadmin_and_names_the_verb(): void
    {
        $only = $this->superadmin();

        try {
            LastSuperadmin::guard($only, 'deleted');
            $this->fail('guard() allowed the last superadmin to be removed');
        } catch (LastSuperadminException $e) {
            $this->assertStringContainsString('last superadmin', $e->getMessage());
            $this->assertStringContainsString(
                'deleted',
                $e->getMessage(),
                'the refusal does not say which operation was refused'
            );
        }
    }

    public function test_the_guard_is_a_no_op_when_another_superadmin_remains(): void
    {
        $first = $this->superadmin();
        $second = $this->superadmin();

        LastSuperadmin::guard($first);
        LastSuperadmin::guard($second);

        $this->assertSame(2, LastSuperadmin::activeSuperadminCount(), 'a permitted removal was refused');
    }

    public function test_the_guard_is_a_no_op_for_an_ordinary_account(): void
    {
        $this->superadmin();

        LastSuperadmin::guard(User::factory()->create(['is_active' => true]));

        $this->assertSame(1, LastSuperadmin::activeSuperadminCount());
    }
}
