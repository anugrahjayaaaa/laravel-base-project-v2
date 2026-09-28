<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * email_verification_mode decides WHO MAY SEND a verification link, never who
 * may use one. A link already in the inbox is a capability and stays valid in
 * every mode until it expires.
 *
 *      mode      admin may send   user may request   link may be used
 *      public    yes              yes                yes
 *      admin     yes              no                 yes
 *      disabled  no               no                 yes
 */
class EmailVerificationModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'email_verified_at' => null,
            'password' => \Illuminate\Support\Facades\Hash::make('Password1!'),
        ]);
    }

    private function signedVerifyUrl(User $user): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
        );
    }

    /**
     * @return array<string, array{string, bool, bool}>  [mode, admin may send, user may request]
     */
    public static function modeMatrix(): array
    {
        return [
            'public'   => ['public', true, true],
            'admin'    => ['admin', true, false],
            'disabled' => ['disabled', false, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modeMatrix')]
    public function test_the_link_works_in_every_mode(string $mode, bool $adminCanSend, bool $userCanRequest): void
    {
        SystemSetting::set('email_verification_mode', $mode);
        $user = $this->user();

        $this->get($this->signedVerifyUrl($user))
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $this->assertTrue($user->fresh()->hasVerifiedEmail(), "link must work in {$mode} mode");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modeMatrix')]
    public function test_login_still_requires_a_verified_address_in_every_mode(string $mode, bool $adminCanSend, bool $userCanRequest): void
    {
        // Waiving the requirement at login did not help: the `verified` route
        // middleware checks the same column and would bounce the user anyway.
        SystemSetting::set('email_verification_mode', $mode);
        $user = $this->user();

        $this->post(route('login.submit'), [
            'identifier' => $user->email,
            'password' => 'Password1!',
        ])->assertSessionHas('error');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modeMatrix')]
    public function test_a_user_may_only_request_a_link_in_public_mode(string $mode, bool $adminCanSend, bool $userCanRequest): void
    {
        Notification::fake();
        SystemSetting::set('email_verification_mode', $mode);
        $user = $this->user();

        if ($userCanRequest) {
            $this->post(route('verification.resend'), ['email' => $user->email])
                ->assertSessionHasNoErrors();

            Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
        } else {
            // A blocked request must say so rather than pretend it worked.
            $this->post(route('verification.resend'), ['email' => $user->email])
                ->assertSessionHasErrors('error');
            Notification::assertNothingSent();
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modeMatrix')]
    public function test_an_admin_may_resend_except_when_disabled(string $mode, bool $adminCanSend, bool $userCanRequest): void
    {
        Notification::fake();
        SystemSetting::set('email_verification_mode', $mode);

        $admin = User::factory()->create();
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin', 'web'));
        $this->actingAs($admin);

        $user = $this->user();

        $this->post(route('users.resend-verification', $user))->assertRedirect();

        if ($adminCanSend) {
            Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
        } else {
            Notification::assertNothingSent();
            $this->assertFalse($user->fresh()->hasVerifiedEmail());
        }
    }

    /**
     * The gate lives in AdminResendVerificationAction, so the API endpoint and
     * the web form cannot drift apart. This is the caller that had no gate at
     * all before: the web controller refused, the API one sent anyway.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modeMatrix')]
    public function test_the_api_resend_endpoint_obeys_disabled_too(string $mode, bool $adminCanSend, bool $userCanRequest): void
    {
        Notification::fake();
        SystemSetting::set('email_verification_mode', $mode);

        $admin = User::factory()->create();
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin', 'web'));
        $this->actingAs($admin, 'sanctum');

        $user = $this->user();

        $response = $this->postJson(route('api.v1.users.resend-verification', $user));

        if ($adminCanSend) {
            $response->assertOk();
            Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
        } else {
            $response->assertForbidden();
            Notification::assertNothingSent();
        }
    }

    /**
     * `disabled` has to be gone from the UI too, not just refused by the
     * server. A button that is always going to bounce is worse than none.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modeMatrix')]
    public function test_the_user_page_hides_the_resend_button_when_disabled(string $mode, bool $adminCanSend, bool $userCanRequest): void
    {
        SystemSetting::set('email_verification_mode', $mode);

        $admin = User::factory()->create();
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin', 'web'));
        $this->actingAs($admin);

        $user = $this->user();

        $body = $this->get(route('users.show', $user))->assertOk()->getContent();

        $hasButton = str_contains($body, 'Resend Verification');

        $this->assertSame(
            $adminCanSend,
            $hasButton,
            "resend button visibility wrong in {$mode} mode"
        );

        // The "not verified" warning is a fact about the account, not an
        // action, so it stays either way.
        $this->assertStringContainsString('Email not verified', $body);
    }

    public function test_the_notice_page_tells_the_user_when_no_one_can_resend(): void
    {
        SystemSetting::set('email_verification_mode', 'disabled');
        $this->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('New verification emails cannot be sent')
            ->assertDontSee('Resend Verification Email');

        SystemSetting::set('email_verification_mode', 'admin');
        $this->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Contact your admin')
            ->assertDontSee('Resend Verification Email');

        SystemSetting::set('email_verification_mode', 'public');
        $this->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Resend Verification Email');
    }
}
