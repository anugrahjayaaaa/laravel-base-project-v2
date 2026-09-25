<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\PasswordExpirySweep;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PasswordExpiry;
use Database\Seeders\SystemSettingSeeder;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_is_expired_returns_true_when_password_past_expiry(): void
    {
        $user = User::factory()->create([
            'password_expires_at' => now()->subDay(),
        ]);

        $this->assertTrue(PasswordExpiry::isExpired($user));
    }

    public function test_is_expired_returns_false_when_password_not_expired(): void
    {
        $user = User::factory()->create([
            'password_expires_at' => now()->addDays(30),
        ]);

        $this->assertFalse(PasswordExpiry::isExpired($user));
    }

    public function test_is_expired_returns_false_when_no_expiry_set(): void
    {
        $user = User::factory()->create([
            'password_expires_at' => null,
        ]);

        $this->assertFalse(PasswordExpiry::isExpired($user));
    }

    public function test_is_expired_returns_false_when_disabled(): void
    {
        SystemSetting::set('password_expiry_enabled', 'false');
        SystemSetting::bustCache();

        $user = User::factory()->create([
            'password_expires_at' => now()->subDay(),
        ]);

        $this->assertFalse(PasswordExpiry::isExpired($user));
    }

    public function test_days_until_expiry_returns_correct_value(): void
    {
        // Add a small buffer to ensure we cross the 10-day boundary (diffInDays truncates)
        $user = User::factory()->create([
            'password_expires_at' => now()->addDays(10)->addHour(),
        ]);

        $this->assertEquals(10, PasswordExpiry::daysUntilExpiry($user));
    }

    public function test_days_until_expiry_returns_zero_when_expired(): void
    {
        $user = User::factory()->create([
            'password_expires_at' => now()->subDay(),
        ]);

        $this->assertEquals(0, PasswordExpiry::daysUntilExpiry($user));
    }

    public function test_should_warn_returns_true_within_threshold(): void
    {
        $user = User::factory()->create([
            'password_expires_at' => now()->addDays(7),
        ]);

        // Default warn_days = 14, so 7 days should warn
        $this->assertTrue(PasswordExpiry::shouldWarn($user));
    }

    public function test_should_warn_returns_false_outside_threshold(): void
    {
        $user = User::factory()->create([
            'password_expires_at' => now()->addDays(30),
        ]);

        // 30 days > 14 warn_days
        $this->assertFalse(PasswordExpiry::shouldWarn($user));
    }

    public function test_should_warn_returns_false_when_expired(): void
    {
        $user = User::factory()->create([
            'password_expires_at' => now()->subDay(),
        ]);

        // Already expired, not "warning" state
        $this->assertFalse(PasswordExpiry::shouldWarn($user));
    }

    public function test_expiry_sweep_sets_must_change_for_expired_users(): void
    {
        // Create expired user
        $expiredUser = User::factory()->create([
            'password_expires_at' => now()->subDay(),
            'must_change_password' => false,
        ]);

        // Create non-expired user (should not be affected)
        $validUser = User::factory()->create([
            'password_expires_at' => now()->addDays(30),
            'must_change_password' => false,
        ]);

        // Run the sweep
        $job = new PasswordExpirySweep();
        $job->handle();

        // Verify expired user was flagged
        $expiredUser->refresh();
        $this->assertTrue($expiredUser->must_change_password);

        $activity = Activity::query()
            ->where('description', 'auth.password_expiry.sweep')
            ->where('subject_id', $expiredUser->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertNull($activity->causer_id);
        $this->assertSame('SYSTEM', $activity->properties['causer']);

        // Verify valid user was not affected
        $validUser->refresh();
        $this->assertFalse($validUser->must_change_password);
    }

    public function test_expiry_sweep_skips_when_disabled(): void
    {
        SystemSetting::set('password_expiry_enabled', 'false');
        SystemSetting::bustCache();

        $expiredUser = User::factory()->create([
            'password_expires_at' => now()->subDay(),
            'must_change_password' => false,
        ]);

        $job = new PasswordExpirySweep();
        $job->handle();

        $expiredUser->refresh();
        $this->assertFalse($expiredUser->must_change_password);
    }
}
