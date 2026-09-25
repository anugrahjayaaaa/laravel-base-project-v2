<?php

namespace Tests\Feature;

use App\Actions\V1\Auth\ChangePasswordAction;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\InactivityLock;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PasswordLifecycleUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_expired_user_is_redirected_to_password_expired_screen(): void
    {
        $user = User::factory()->create([
            'password_expires_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('password.expired'));

        $this->get(route('password.expired'))
            ->assertOk()
            ->assertSee('Your password has expired')
            ->assertSee('Update Password')
            ->assertSee('action="'.route('password.change.update').'"', false)
            ->assertSee('justify-content-center', false);
    }

    public function test_profile_page_uses_shared_password_change_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('id="change-password"', false)
            ->assertSee('action="'.route('password.change.update').'"', false)
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_warning_banner_is_rendered_for_password_expiring_soon(): void
    {
        $user = User::factory()->create([
            'password_expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Password Expiry Warning')
            ->assertSee('Your password expires in 6 days')
            ->assertSee(route('profile.show').'#change-password', false);
    }

    public function test_request_time_inactivity_lock_is_audited(): void
    {
        $user = User::factory()->create([
            'last_activity_at' => now()->subDays(35),
        ]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue($user->is_locked);

        $activity = Activity::query()
            ->where('description', 'auth.inactivity_lock.middleware')
            ->where('subject_id', $user->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertNull($activity->causer_id);
        $this->assertSame('SYSTEM', $activity->properties['causer']);
    }

    public function test_never_logged_in_user_uses_inactivity_grace_period(): void
    {
        $user = User::factory()->create([
            'last_activity_at' => null,
            'created_at' => now()->subDays(59),
        ]);

        $this->assertFalse(InactivityLock::isInactive($user));

        $user->forceFill(['created_at' => now()->subDays(61)])->save();

        $this->assertTrue(InactivityLock::isInactive($user));
    }

    public function test_password_change_uses_canonical_expiry_days_setting(): void
    {
        SystemSetting::set('password_expiry_days', '7');
        SystemSetting::bustCache();

        $user = User::factory()->create();

        app(ChangePasswordAction::class)->run(
            user: $user,
            currentPassword: 'password',
            newPassword: 'NewP@ss1!',
        );

        $expiration = $user->fresh()->password_expires_at;

        $this->assertTrue($expiration->between(now()->addDays(6), now()->addDays(7)));
        $this->assertDatabaseHas('system_settings', [
            'key' => 'password_expiry_days',
            'value' => '7',
        ]);
        $this->assertDatabaseMissing('system_settings', [
            'key' => 'password_expiration_days',
        ]);
    }
}
