<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\InactivityLockSweep;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\InactivityLock;
use Database\Seeders\SystemSettingSeeder;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InactivityLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_is_inactive_returns_true_when_beyond_threshold(): void
    {
        $user = User::factory()->create([
            'last_activity_at' => now()->subDays(35),
        ]);

        // Default inactivity_lock_days = 30
        $this->assertTrue(InactivityLock::isInactive($user));
    }

    public function test_is_inactive_returns_false_when_within_threshold(): void
    {
        $user = User::factory()->create([
            'last_activity_at' => now()->subDays(15),
        ]);

        $this->assertFalse(InactivityLock::isInactive($user));
    }

    public function test_is_inactive_returns_false_when_no_activity_set(): void
    {
        $user = User::factory()->create([
            'last_activity_at' => null,
        ]);

        $this->assertFalse(InactivityLock::isInactive($user));
    }

    public function test_grace_period_enabled_uses_configured_days(): void
    {
        SystemSetting::set('inactivity_lock_grace_enabled', 'true');
        SystemSetting::set('inactivity_lock_grace_days', '30');
        SystemSetting::bustCache();

        $user = User::factory()->create([
            'last_activity_at' => null,
            'created_at' => now()->subDays(59),
        ]);

        $this->assertFalse(InactivityLock::isInactive($user));
    }

    public function test_grace_period_can_be_disabled_for_never_logged_in_users(): void
    {
        SystemSetting::set('inactivity_lock_grace_enabled', 'false');
        SystemSetting::bustCache();

        $user = User::factory()->create([
            'last_activity_at' => null,
            'created_at' => now()->subDays(35),
        ]);

        $this->assertTrue(InactivityLock::isInactive($user));
    }

    public function test_is_inactive_returns_false_when_disabled(): void
    {
        SystemSetting::set('inactivity_lock_enabled', 'false');
        SystemSetting::bustCache();

        $user = User::factory()->create([
            'last_activity_at' => now()->subDays(35),
        ]);

        $this->assertFalse(InactivityLock::isInactive($user));
    }

    public function test_should_lock_returns_true_when_inactive_and_not_locked(): void
    {
        $user = User::factory()->create([
            'last_activity_at' => now()->subDays(35),
            'is_locked' => false,
        ]);

        $this->assertTrue(InactivityLock::shouldLock($user));
    }

    public function test_should_lock_returns_false_when_already_locked(): void
    {
        $user = User::factory()->create([
            'last_activity_at' => now()->subDays(35),
            'is_locked' => true,
        ]);

        $this->assertFalse(InactivityLock::shouldLock($user));
    }

    public function test_lock_sets_is_locked_true(): void
    {
        $user = User::factory()->create([
            'last_activity_at' => now()->subDays(35),
            'is_locked' => false,
        ]);

        InactivityLock::lock($user);

        $user->refresh();
        $this->assertTrue($user->is_locked);
    }

    public function test_lock_revokes_all_tokens(): void
    {
        $user = User::factory()->create([
            'last_activity_at' => now()->subDays(35),
            'is_locked' => false,
        ]);

        // Create a token
        $user->createToken('test-token');
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);

        InactivityLock::lock($user);

        // Tokens should be deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_inactivity_sweep_locks_inactive_users(): void
    {
        // Create inactive user
        $inactiveUser = User::factory()->create([
            'last_activity_at' => now()->subDays(35),
            'is_locked' => false,
        ]);

        // Create active user (should not be affected)
        $activeUser = User::factory()->create([
            'last_activity_at' => now()->subDays(5),
            'is_locked' => false,
        ]);

        // Create locked user (should not be affected)
        $alreadyLocked = User::factory()->create([
            'last_activity_at' => now()->subDays(35),
            'is_locked' => true,
        ]);

        // Run the sweep
        $job = new InactivityLockSweep();
        $job->handle();

        // Verify inactive user was locked
        $inactiveUser->refresh();
        $this->assertTrue($inactiveUser->is_locked);

        $activity = Activity::query()
            ->where('description', 'auth.inactivity_lock.sweep')
            ->where('subject_id', $inactiveUser->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertNull($activity->causer_id);
        $this->assertSame('SYSTEM', $activity->properties['causer']);

        // Verify active user was not affected
        $activeUser->refresh();
        $this->assertFalse($activeUser->is_locked);

        // Verify already-locked user is still locked
        $alreadyLocked->refresh();
        $this->assertTrue($alreadyLocked->is_locked);
    }

    public function test_inactivity_sweep_skips_when_disabled(): void
    {
        SystemSetting::set('inactivity_lock_enabled', 'false');
        SystemSetting::bustCache();

        $inactiveUser = User::factory()->create([
            'last_activity_at' => now()->subDays(35),
            'is_locked' => false,
        ]);

        $job = new InactivityLockSweep();
        $job->handle();

        $inactiveUser->refresh();
        $this->assertFalse($inactiveUser->is_locked);
    }
}
