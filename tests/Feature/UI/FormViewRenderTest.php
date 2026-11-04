<?php

namespace Tests\Feature\UI;

use App\Enums\UserStatusEnum;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 15 P15-A2: every form page renders from the payload its controller
 * hands over.
 *
 * Settings, feature flags and the notification pages already own a render gate
 * (`SettingsUiRenderTest`, `FeatureFlagUiRenderTest`, `NotificationUiRenderTest`).
 * This file covers the four that did not: user create, user detail, profile and
 * the forced password change.
 *
 * Rendered outside the HTTP kernel with an empty error bag, because a bare
 * `view()->render()` has no `ShareErrorsFromSession` behind it and `@error`
 * would fail. `AccountOptionsComposer` and `PasswordStrengthComposer` still run
 * — they are registered on these views — so the render is the real one minus
 * the router.
 */
class FormViewRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function signIn(string $role = 'admin'): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data, ?ViewErrorBag $errors = null): string
    {
        return view($view, array_merge(
            ['errors' => $errors ?? new ViewErrorBag()],
            $data,
        ))->render();
    }

    public function test_the_create_user_form_renders_its_contract(): void
    {
        $this->signIn();
        $html = $this->render('pages.users.create', ['title' => 'Create User']);

        $this->assertStringContainsString('action="'.route('users.store').'"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);

        foreach (['name', 'username', 'email'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "the create form lost its {$field} field");
        }

        // A create form has no status to set and no roles to pick: the account
        // starts pending verification, and the action assigns the default role.
        $this->assertStringNotContainsString('name="status"', $html);
        $this->assertStringContainsString('Temporary Password Policy', $html);
    }

    public function test_the_edit_user_form_renders_every_state_trigger(): void
    {
        $this->signIn();

        // One transition per state, because the page offers exactly the move
        // that is legal from where the account is now. Unlock is matched by its
        // title: it is the one trigger that deliberately carries no
        // `action-type`, so the shared modal driver takes its own copy instead
        // of inventing one from another action's config.
        $cases = [
            'active' => ['attributes' => [], 'expected' => ['data-action-type="deactivate"', 'data-action-type="lock"']],
            'locked' => ['attributes' => ['is_locked' => true], 'expected' => ['data-title="Unlock User Account?"']],
            'inactive' => ['attributes' => ['is_active' => false], 'expected' => ['data-action-type="activate"']],
        ];

        foreach ($cases as $state => $case) {
            $user = User::factory()->create(['email_verified_at' => now()] + $case['attributes']);

            $this->assertSame($state, $user->getStatus()->value, 'precondition: the fixture is in the intended state');

            $html = $this->render('pages.users.edit', [
                'title' => 'User Detail',
                'user' => $user,
                'statuses' => UserStatusEnum::cases(),
                'failedLoginCount' => 0,
                'canResendVerification' => true,
            ]);

            $this->assertStringContainsString('action="'.route('users.update', $user).'"', $html);
            $this->assertStringContainsString('name="status"', $html, 'the state select is gone');
            $this->assertStringContainsString('name="roles[]"', $html, 'the role picker is gone');
            $this->assertStringContainsString('data-action-type="delete"', $html, 'the delete trigger is gone');

            foreach ($case['expected'] as $marker) {
                $this->assertStringContainsString(
                    $marker,
                    $html,
                    "the {$state} account lost its {$marker} trigger"
                );
            }
        }
    }

    public function test_the_edit_user_form_offers_restore_and_force_delete_for_a_trashed_user(): void
    {
        $this->signIn();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->delete();

        $this->assertTrue($user->fresh()->trashed(), 'precondition: the user is trashed');

        $html = $this->render('pages.users.edit', [
            'title' => 'User Detail',
            'user' => $user->fresh(),
            'statuses' => UserStatusEnum::cases(),
            'failedLoginCount' => 0,
            'canResendVerification' => true,
        ]);

        $this->assertStringContainsString('data-action-type="restore"', $html);
        $this->assertStringContainsString('data-action-type="force_delete"', $html);
        $this->assertStringNotContainsString('data-action-type="deactivate"', $html, 'a trashed user was offered a state change');
    }

    public function test_the_profile_form_renders_the_identity_fields_and_the_password_form(): void
    {
        $this->signIn();
        $html = $this->render('pages.profile.edit', ['title' => 'Profile', 'user' => User::factory()->create()]);

        $this->assertStringContainsString('action="'.route('profile.update').'"', $html);

        foreach (['name', 'username', 'email'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "the profile form lost its {$field} field");
        }

        $this->assertStringContainsString('action="'.route('password.change.update').'"', $html, 'the password form is gone');
        $this->assertStringNotContainsString('name="status"', $html, 'the admin state select leaked onto the profile page');
    }

    public function test_the_forced_password_change_page_renders_the_three_password_fields(): void
    {
        $this->signIn();
        $html = $this->render('pages.auth.password-expired', ['title' => 'Password Expired']);

        $this->assertStringContainsString('action="'.route('password.change.update').'"', $html);

        foreach (['current_password', 'password', 'password_confirmation'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "the page lost its {$field} field");
        }

        // The checklist is fed by the same policy the validator enforces.
        $this->assertStringContainsString('password-strength', $html);
        $this->assertStringContainsString('data-policy="password-strength"', $html);
    }

    public function test_a_validation_error_reaches_the_field_that_caused_it(): void
    {
        $this->signIn();

        $errors = new ViewErrorBag();
        $errors->put('default', new MessageBag(['name' => ['The name field is required.']]));

        $html = $this->render('pages.users.create', ['title' => 'Create User'], $errors);

        preg_match('/<input[^>]*name="name"[^>]*>/', $html, $input);

        $this->assertStringContainsString('is-invalid', $input[0] ?? '', 'an errored field is not marked invalid');
        $this->assertStringContainsString('The name field is required.', $html, 'the error message is not rendered');

        // The message must FOLLOW its input: Bootstrap's rule is
        // `.is-invalid ~ .invalid-feedback`, so a message ahead of the field is
        // invisible while the border is red — the exact failure Phase 8 fixed on
        // the settings page by wrapping fields in `input-group`.
        $this->assertLessThan(
            strpos($html, 'The name field is required.'),
            strpos($html, 'name="name"'),
            'the error message renders before its field'
        );
    }

    public function test_no_form_view_reaches_for_the_database(): void
    {
        $pages = [
            'pages/users/create.blade.php',
            'pages/users/edit.blade.php',
            'pages/profile/edit.blade.php',
            'pages/auth/password-expired.blade.php',
        ];

        $forbidden = [
            '/\bDB::/',
            '/SystemSetting::/',
            '/RoleLookup::/',
            '/::query\s*\(/',
            '/::where\s*\(/',
        ];

        foreach ($pages as $page) {
            // Blade comments stripped first: a comment documenting the rule
            // names the calls it forbids and trips itself.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path('views/'.$page)));

            foreach ($forbidden as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $source,
                    "{$page} reaches into the application instead of reading a variable"
                );
            }
        }
    }
}
