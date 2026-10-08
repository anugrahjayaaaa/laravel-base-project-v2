<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\RegisterNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use App\Http\Middleware\VerifyCsrfToken;
use Database\Seeders\SystemSettingSeeder;

/**
 * POST /api/v1/auth/register
 *
 * Shares RegisterRequest and UserCreateAction with the web form, so these
 * assert only what the API contract adds on top.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    private function enable(): void
    {
        SystemSetting::set('registration_enabled', 'true');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Api Registered',
            'username' => 'apireg',
            'email' => 'apireg@example.com',
            'password' => 'ChosenP@ss1!',
            'password_confirmation' => 'ChosenP@ss1!',
        ], $overrides);
    }

    public function test_endpoint_is_absent_while_registration_is_off(): void
    {
        $this->postJson(route('api.v1.auth.register'), $this->payload())->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_client_can_register(): void
    {
        Notification::fake();
        $this->enable();

        $response = $this->postJson(route('api.v1.auth.register'), $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.username', 'apireg')
            ->assertJsonPath('data.email', 'apireg@example.com')
            ->assertJsonStructure(['data' => ['id', 'username', 'email'], 'meta' => ['request_id', 'timestamp']]);

        $user = User::where('email', 'apireg@example.com')->firstOrFail();

        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertFalse($user->must_change_password, 'a chosen password needs no forced change');
        $this->assertTrue(Hash::check('ChosenP@ss1!', $user->password));

        Notification::assertSentTo($user, RegisterNotification::class);
    }

    public function test_registration_grants_the_default_role(): void
    {
        Notification::fake();
        $this->enable();
        Role::findOrCreate('member', 'web');
        SystemSetting::set('registration_default_role', 'member');

        $this->postJson(route('api.v1.auth.register'), $this->payload())->assertCreated();

        $this->assertTrue(User::where('email', 'apireg@example.com')->firstOrFail()->hasRole('member'));
    }

    public function test_validation_errors_use_the_api_contract(): void
    {
        Notification::fake();
        $this->enable();
        User::factory()->create(['username' => 'taken', 'email' => 'taken@example.com']);

        $this->postJson(route('api.v1.auth.register'), $this->payload([
            'username' => 'taken',
            'email' => 'taken@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['message', 'errors', 'code', 'meta']);

        $this->assertSame(1, User::where('username', 'taken')->count());
    }

    /**
     * The limiter used to answer every caller with an HTML redirect, which an
     * API client cannot parse. This is the regression that motivated the fix.
     */
    public function test_rate_limit_answers_with_json_not_html(): void
    {
        Notification::fake();
        $this->enable();
        SystemSetting::set('registration_rate_limit_per_minute', '2');

        $this->postJson(route('api.v1.auth.register'), $this->payload())->assertCreated();
        $this->postJson(route('api.v1.auth.register'), $this->payload([
            'username' => 'second', 'email' => 'second@example.com',
        ]))->assertCreated();

        $response = $this->postJson(route('api.v1.auth.register'), $this->payload([
            'username' => 'third', 'email' => 'third@example.com',
        ]));

        $response->assertStatus(429)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('code', 'RATE_LIMITED')
            ->assertJsonStructure(['message', 'code', 'retry_after_seconds']);

        $this->assertDatabaseCount('users', 2);
    }

    /**
     * Both channels share one limiter, so a JSON caller must not spend the
     * budget of a browser user on the same address.
     */
    public function test_the_limiter_is_shared_between_web_and_api(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Notification::fake();
        $this->enable();
        SystemSetting::set('registration_rate_limit_per_minute', '2');

        $this->post(route('register.submit'), $this->payload())->assertRedirect(route('login'));
        $this->post(route('register.submit'), $this->payload([
            'username' => 'second', 'email' => 'second@example.com',
        ]))->assertRedirect(route('login'));

        // The third attempt comes from an API client and must already be blocked.
        $this->postJson(route('api.v1.auth.register'), $this->payload([
            'username' => 'third', 'email' => 'third@example.com',
        ]))->assertStatus(429);
    }
}
