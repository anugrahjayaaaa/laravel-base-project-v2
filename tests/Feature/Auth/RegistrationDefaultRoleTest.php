<?php

namespace Tests\Feature\Auth;

use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Http\Middleware\VerifyCsrfToken;

/**
 * The registration default role is the role every self-registering account
 * receives, so setting it to superadmin is a mass privilege grant. The dropdown
 * already hid the choice; this pins the rule to the same set the dropdown is
 * built from, so the form and the validation cannot disagree.
 */
class RegistrationDefaultRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function delegate(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find(SystemRole::ADMIN));

        return $user;
    }

    private function superadmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find(SystemRole::SUPERADMIN));

        return $user;
    }

    public function test_a_delegated_admin_cannot_set_the_default_to_superadmin(): void
    {
        $this->actingAs($this->delegate())
            ->from(route('settings.index'))
            ->post(route('settings.update'), ['registration_default_role' => SystemRole::SUPERADMIN])
            ->assertSessionHasErrors('registration_default_role');

        $this->assertNotSame(
            SystemRole::SUPERADMIN,
            SystemSetting::getString('registration_default_role', 'user'),
            'the rejected value was stored anyway'
        );
    }

    public function test_a_delegated_admin_can_still_set_an_ordinary_role(): void
    {
        $this->actingAs($this->delegate())
            ->post(route('settings.update'), ['registration_default_role' => SystemRole::USER])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            SystemRole::USER,
            SystemSetting::getString('registration_default_role', 'user')
        );
    }

    public function test_a_superadmin_can_set_the_default_to_superadmin(): void
    {
        // The one legitimate use: the superadmin may hand the role to whoever it
        // likes, including itself by default. Option A forbids the delegated
        // admin, not this.
        $this->actingAs($this->superadmin())
            ->post(route('settings.update'), ['registration_default_role' => SystemRole::SUPERADMIN])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            SystemRole::SUPERADMIN,
            SystemSetting::getString('registration_default_role', 'user')
        );
    }

    public function test_an_unknown_role_is_still_rejected(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('settings.update'), ['registration_default_role' => 'wizard'])
            ->assertSessionHasErrors('registration_default_role');
    }

    public function test_an_empty_value_means_no_role(): void
    {
        $this->actingAs($this->delegate())
            ->post(route('settings.update'), ['registration_default_role' => ''])
            ->assertSessionHasNoErrors();
    }
}
