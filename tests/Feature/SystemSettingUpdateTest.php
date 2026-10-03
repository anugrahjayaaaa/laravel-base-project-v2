<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TimezoneSeeder;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemSettingUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Http::fake([
            'aisenseapi.com/*' => Http::response([
                'timezones' => [
                    ['timezone' => 'UTC', 'offset' => '+0000'],
                    ['timezone' => 'Asia/Jakarta', 'offset' => '+0700'],
                ],
            ]),
        ]);
    }

    public function test_update_settings_persists_to_database(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find('admin'));
        $this->actingAs($user, 'web');

        $response = $this->from('/settings')->post(route('settings.update'), [
            'allow_username_change' => 'on',
            'allow_email_change' => 'on',
            'username_change_cooldown_days' => 15,
            'email_change_cooldown_days' => 10,
        ]);

        $response->assertRedirect();
        $this->assertTrue(SystemSetting::getBool('allow_username_change'));
        $this->assertTrue(SystemSetting::getBool('allow_email_change'));
        $this->assertEquals(15, SystemSetting::getInt('username_change_cooldown_days'));
        $this->assertEquals(10, SystemSetting::getInt('email_change_cooldown_days'));
        $this->assertEquals('00:00', SystemSetting::getString('password_security_sweep_time'));
        $this->assertSame('', SystemSetting::getString('password_security_sweep_timezone'));

        $activity = Activity::query()
            ->where('description', 'system_setting.updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(SystemSetting::class, $activity->subject_type);
        $this->assertNotNull($activity->subject_id);
        $this->assertSame($user->id, $activity->causer_id);
    }

    public function test_sweep_schedule_settings_can_be_customized(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find('admin'));
        $this->actingAs($user, 'web');
        $this->seed(TimezoneSeeder::class);

        $response = $this->from('/settings')->post(route('settings.update'), [
            'password_security_sweep_time' => '07:30',
            'password_security_sweep_timezone' => 'Asia/Jakarta',
        ]);

        $response->assertRedirect();
        $this->assertSame('07:30', SystemSetting::getString('password_security_sweep_time'));
        $this->assertSame('Asia/Jakarta', SystemSetting::getString('password_security_sweep_timezone'));
    }

    public function test_sweep_schedule_rejects_invalid_timezone(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find('admin'));
        $this->actingAs($user, 'web');

        $response = $this->from('/settings')->post(route('settings.update'), [
            'password_security_sweep_timezone' => 'Not/A_Timezone',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('password_security_sweep_timezone');
    }

    public function test_grace_toggle_disables_and_does_not_store_disabled_days(): void
    {
        SystemSetting::set('inactivity_lock_grace_enabled', 'true');
        SystemSetting::set('inactivity_lock_grace_days', '45');

        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find('admin'));
        $this->actingAs($user, 'web');

        $response = $this->from('/settings')->post(route('settings.update'), [
            'inactivity_lock_grace_enabled' => 'off',
            'inactivity_lock_grace_days' => 30,
        ]);

        $response->assertRedirect();
        $this->assertFalse(SystemSetting::getBool('inactivity_lock_grace_enabled'));
        $this->assertSame('45', SystemSetting::getString('inactivity_lock_grace_days'));
    }

    public function test_enabled_grace_toggle_stores_grace_days(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find('admin'));
        $this->actingAs($user, 'web');

        $response = $this->from('/settings')->post(route('settings.update'), [
            'inactivity_lock_grace_enabled' => '1',
            'inactivity_lock_grace_days' => 45,
        ]);

        $response->assertRedirect();
        $this->assertTrue(SystemSetting::getBool('inactivity_lock_grace_enabled'));
        $this->assertSame('45', SystemSetting::getString('inactivity_lock_grace_days'));
    }

    public function test_unchecked_checkboxes_store_false(): void
    {
        SystemSetting::set('allow_username_change', 'true');
        SystemSetting::set('allow_email_change', 'true');

        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find('admin'));
        $this->actingAs($user, 'web');

        $response = $this->from('/settings')->post(route('settings.update'), [
            'username_change_cooldown_days' => 30,
            'email_change_cooldown_days' => 30,
        ]);

        $response->assertRedirect();
        $this->assertFalse(SystemSetting::getBool('allow_username_change'));
        $this->assertFalse(SystemSetting::getBool('allow_email_change'));
        $this->assertEquals(30, SystemSetting::getInt('username_change_cooldown_days'));
        $this->assertEquals(30, SystemSetting::getInt('email_change_cooldown_days'));
    }

    /**
     * Every numeric bound in the rules is proven to reject a value outside it.
     *
     * One bound in 37 was ever exercised (`password_security_sweep_timezone`,
     * which is not numeric), so a mistyped bound is invisible: `max:1440`
     * written as `max:140` passes every test in the suite and silently caps a
     * rate limit a quarter of the way it should be. The rule is the contract;
     * this walks it rather than trusting it.
     *
     * One request per key, driven off `rules()` itself, so a new setting is
     * covered the day it is added and a removed one stops being asserted.
     */
    public function test_every_numeric_bound_rejects_a_value_outside_it(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find('admin'));
        $this->actingAs($user, 'web');

        $checked = [];

        foreach ((new \App\Http\Requests\V1\System\SystemSettingRequest())->rules() as $key => $rules) {
            if (! in_array('integer', $rules, true)) {
                continue;
            }

            foreach (['min', 'max'] as $bound) {
                $rule = collect($rules)->first(fn ($r) => is_string($r) && str_starts_with($r, $bound . ':'));

                if ($rule === null) {
                    continue;
                }

                $limit = (int) explode(':', $rule)[1];
                $checked[] = "{$key}.{$bound}";

                // Out of range must be rejected. Necessary, but nowhere near
                // enough on its own: `max:140` instead of `max:1440` rejects
                // 141 exactly as readily, so this half cannot tell a typo'd
                // bound from a correct one. See the boundary half below.
                $this->apiUpdate([$key => $bound === 'min' ? $limit - 1 : $limit + 1])
                    ->assertStatus(422)
                    ->assertJsonValidationErrors($key);

                // The limit itself must be accepted. This is the only assertion
                // that pins the number: it is what fails when a bound is
                // mistyped inward, and it is why probing past the edge is not
                // enough.
                $this->apiUpdate([$key => $limit])
                    ->assertOk();
            }
        }

        $this->assertNotSame([], $checked, 'no integer bound found in rules()');
    }

    /**
     * POST one payload through the API settings endpoint as an admin.
     *
     * The API channel rather than the web form because a web failure redirects
     * and asserts against flashed session state, which reads the same whether
     * the bound held or not. A 422 and a 200 are not confusable.
     */
    /**
     * The API channel writes an audit row attributed to the caller.
     *
     * The web channel has had this covered since `ActionFirstAuditTest`; the API
     * channel had none. It is the same action and the same
     * `system_setting.updated` event, so the property to protect is not "does it
     * audit" but "does the API channel pass its own causer through". A settings
     * change over the API with a NULL `causer_id` would be a real policy change
     * that no reviewer could later attribute to anyone.
     *
     * `event` is asserted as well as `causer_id`: a row with `event` NULL sits in
     * the table and is skipped by every `where('event', ...)` filter, which is
     * the failure mode `ApiRoleAuditTrailTest` documents.
     */
    public function test_the_api_channel_writes_an_audit_row_attributed_to_the_caller(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleLookup::find('admin'));

        $this->actingAs($admin, 'sanctum')
            ->putJson(route('api.v1.settings.update'), ['password_min_length' => 14])
            ->assertOk();

        $rows = DB::table('activity_log')->where('event', 'system_setting.updated')->get();

        $this->assertCount(1, $rows, 'the API settings save did not write exactly one audit record');
        $this->assertSame(
            (int) $admin->id,
            (int) $rows->first()->causer_id,
            'the API settings save did not attribute the row to its caller'
        );
    }

    /**
     * The API channel is partial: the same request that must not reset the keys
     * it omits.
     *
     * The web form always submits every field, so its full-payload default
     * (`partial: false`) is invisible from the browser. The API is where
     * `partial: true` actually matters, and where a wrong default silently
     * rewrites 36 settings an operator never touched.
     */
    public function test_the_api_channel_treats_an_omitted_key_as_untouched(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleLookup::find('admin'));

        SystemSetting::set('password_min_length', '14');
        SystemSetting::set('registration_enabled', 'true');
        SystemSetting::bustCache();

        $this->actingAs($admin, 'sanctum')
            ->putJson(route('api.v1.settings.update'), ['login_max_attempts' => 7])
            ->assertOk();

        SystemSetting::bustCache();

        $this->assertSame(
            14,
            SystemSetting::getInt('password_min_length'),
            'a partial API payload reset a key it did not mention'
        );
        $this->assertTrue(
            SystemSetting::getBool('registration_enabled'),
            'a partial API payload flipped a boolean it did not mention'
        );
        $this->assertSame(7, SystemSetting::getInt('login_max_attempts'));
    }

    private function apiUpdate(array $payload): \Illuminate\Testing\TestResponse
    {
        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find('admin'));
        $this->actingAs($user);

        return $this->putJson(route('api.v1.settings.update'), $payload);
    }

    public function test_settings_page_disables_grace_days_when_toggle_is_off(): void
    {
        SystemSetting::set('inactivity_lock_grace_enabled', 'false');
        SystemSetting::bustCache();

        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find('admin'));
        $response = $this->actingAs($user, 'web')->get(route('settings.index'));

        $response->assertOk()
            ->assertSee('id="inactivity_lock_grace_enabled"', false)
            ->assertSee('id="inactivity_lock_grace_days"', false);

        $this->assertMatchesRegularExpression(
            '/id="inactivity_lock_grace_days"[^>]*disabled/',
            $response->getContent()
        );
    }

    /**
     * A write that bypasses `SystemSetting::set()` still busts the cache.
     *
     * `set()` busts for its own callers. Everything else does not: the seeder
     * writes through `updateOrCreate()`, and any future console command or
     * patch through `DB::table('system_settings')` would leave the shared cache
     * holding a value the row no longer has — silently, with no error, and for
     * as long as nobody runs `cache:clear`.
     *
     * The observer is what closes that. It is proven here by warming the cache,
     * writing through Eloquent deliberately NOT via `set()`, and reading the
     * new value back. Removing `SystemSettingObserver` makes this fail.
     */
    public function test_a_write_that_bypasses_the_setter_still_busts_the_cache(): void
    {
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);

        // Warm the persistent cache, and PROVE it is warm.
        //
        // `RefreshDatabase` wraps the test in a transaction, and `loadSettings()`
        // refuses to fill the shared cache while one is open — so a naive
        // version of this test passes with nothing in the cache at all and a
        // broken observer. Asserting the cache is populated is what makes the
        // rest of the test mean anything.
        $this->assertSame('12', SystemSetting::getString('password_min_length', ''));

        // `loadSettings()` deliberately skips the shared cache inside a
        // transaction, and `RefreshDatabase` holds one open for the whole test —
        // so the read above filled only the per-request static. Populating the
        // real key directly is what makes a later bust observable; without it
        // this test passes against a completely broken observer.
        Cache::forever('app_system_settings', [
            'password_min_length' => '12',
        ]);

        $this->assertNotNull(
            Cache::get('app_system_settings'),
            'the shared cache was never populated, so a bust cannot be observed'
        );

        // Deliberately NOT `set()` — the same shape `SystemSettingSeeder` uses.
        SystemSetting::updateOrCreate(['key' => 'password_min_length'], ['value' => '20']);

        $this->assertSame(
            '20',
            SystemSetting::getString('password_min_length', ''),
            'a write that bypassed set() left the cache holding the old value'
        );
    }

    /**
     * The observer defers its bust, so a rollback does not leave a stale value.
     *
     * The complement to the test above: busting eagerly is what this guards
     * against. Forgetting inside the transaction lets the next reader repopulate
     * the cache from rows that were never committed, and the rollback cannot
     * take that back — the same defect `loadSettings()` documents for reads.
     */
    public function test_a_rolled_back_write_leaves_the_cache_alone(): void
    {
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);

        $this->assertSame('12', SystemSetting::getString('password_min_length', ''));

        try {
            DB::transaction(function (): void {
                SystemSetting::updateOrCreate(['key' => 'password_min_length'], ['value' => '20']);

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // Expected — the transaction must not survive.
        }

        SystemSetting::clearRequestCache();

        $this->assertSame(
            '12',
            SystemSetting::getString('password_min_length', ''),
            'a rolled-back write leaked its value into the cache'
        );
        $this->assertSame(
            '12',
            DB::table('system_settings')->where('key', 'password_min_length')->value('value'),
            'the row itself should have rolled back to its original value'
        );
    }

    public function test_cache_is_invalidated_on_update(): void
    {
        // Warm the cache with initial values
        SystemSetting::set('allow_username_change', 'true');
        SystemSetting::set('allow_email_change', 'false');

        // Cache is now populated — verify cached reads return initial values
        $this->assertTrue(SystemSetting::getBool('allow_username_change'));
        $this->assertFalse(SystemSetting::getBool('allow_email_change'));

        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find('admin'));
        $this->actingAs($user, 'web');

        // Update via UI POST
        $response = $this->from('/settings')->post(route('settings.update'), [
            'allow_username_change' => 'on',
            'allow_email_change' => 'on',
            'username_change_cooldown_days' => 15,
            'email_change_cooldown_days' => 10,
        ]);

        $response->assertRedirect();

        // Assert cache key is invalidated (fresh read from DB)
        $this->assertTrue(SystemSetting::getBool('allow_username_change'));
        $this->assertTrue(SystemSetting::getBool('allow_email_change'));
        $this->assertEquals(15, SystemSetting::getInt('username_change_cooldown_days'));
        $this->assertEquals(10, SystemSetting::getInt('email_change_cooldown_days'));

        // Assert DB reflects the updated values directly
        $this->assertEquals('true', SystemSetting::where('key', 'allow_username_change')->value('value'));
        $this->assertEquals('true', SystemSetting::where('key', 'allow_email_change')->value('value'));
        $this->assertEquals('15', SystemSetting::where('key', 'username_change_cooldown_days')->value('value'));
        $this->assertEquals('10', SystemSetting::where('key', 'email_change_cooldown_days')->value('value'));
    }
}
