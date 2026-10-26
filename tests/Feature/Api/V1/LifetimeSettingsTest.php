<?php

namespace Tests\Feature\Api\V1;

use App\Models\SystemSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 12 finding #3 — the bearer token has a lifetime, and both that lifetime
 * and the web session's are the values stored in settings.
 *
 * Four things pinned here: the defaults (one day, two hours), the value + unit
 * conversion, and — the one that matters most — that Sanctum actually refuses a
 * token past the bound. A config value nothing reads is the exact failure a
 * settings field is meant to avoid.
 */
class LifetimeSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    public function test_the_default_api_token_lifetime_is_one_day(): void
    {
        AppServiceProvider::bindExpirations();

        $this->assertSame(1440, config('sanctum.expiration'));
    }

    public function test_the_default_web_session_lifetime_is_two_hours(): void
    {
        AppServiceProvider::bindExpirations();

        $this->assertSame(120, config('session.lifetime'));
    }

    public function test_the_token_lifetime_converts_its_stored_unit_to_minutes(): void
    {
        SystemSetting::set('api_token_expire_value', '2');
        SystemSetting::set('api_token_expire_unit', 'hours');

        AppServiceProvider::bindExpirations();

        $this->assertSame(120, config('sanctum.expiration'));
    }

    public function test_the_session_lifetime_converts_its_stored_unit_to_minutes(): void
    {
        SystemSetting::set('web_session_expire_value', '3');
        SystemSetting::set('web_session_expire_unit', 'days');

        AppServiceProvider::bindExpirations();

        $this->assertSame(4320, config('session.lifetime'));
    }

    public function test_a_token_past_its_lifetime_is_refused(): void
    {
        SystemSetting::set('api_token_expire_value', '2');
        SystemSetting::set('api_token_expire_unit', 'hours');
        AppServiceProvider::bindExpirations();

        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $token = $user->createToken('phone');

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson(route('api.v1.profile.show'))
            ->assertOk();

        // Backdated rather than configured smaller, so the test proves the
        // guard's own comparison instead of the binding under it.
        $token->accessToken->forceFill(['created_at' => now()->subHours(3)])->save();

        // The guard resolved on the request above belongs to this test process,
        // not to a new one: without this, its cached user answers the second
        // request and the token is never consulted.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson(route('api.v1.profile.show'))
            ->assertUnauthorized();
    }
}
