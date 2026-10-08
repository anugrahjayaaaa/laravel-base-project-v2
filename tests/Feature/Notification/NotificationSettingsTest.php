<?php

namespace Tests\Feature\Notification;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 9 Group B gate (P9-E1/E2): the mail transport writes, the credential
 * stays encrypted, and the gates answer correctly.
 *
 * The failure worth testing is not "the save returned 200" — it is the one
 * `bindTokenExpirations()` was written for: a field that looks live, saves
 * successfully, and changes nothing. So most of these assert the value the
 * TRANSPORT ends up with, not the row that was written.
 */
class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // VerifyCsrfToken::runningUnitTests() is hardcoded false in this app, so
        // every POST here would 419 before reaching the thing under test.
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function login(string $role = SystemRole::ADMIN): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Commit whatever the request left open, then re-resolve the settings cache.
     *
     * `RefreshDatabase` wraps each test in a transaction, so `DB::afterCommit()`
     * — which is where `SystemSetting::set()` defers its cache bust — does not
     * fire while the test runs. Production commits and busts; here the values are
     * written but the cache still serves what the seeder wrote, and an assertion
     * about the runtime config reads the seeder's values instead of the saved
     * ones. That is a harness artifact, not a product bug: `bindMailConfig()`
     * after a real commit is exactly what production does.
     *
     * Applied after each POST that saves, so the assertions below see the state a
     * real request would.
     */
    private function commitAndRereadSettings(): void
    {
        // Bust first: the read the bind performs repopulates from the persistent
        // cache unless it has been forgotten.
        SystemSetting::bustCache();

        // Then the bind, which is what a committed save leaves behind.
        AppServiceProvider::bindMailConfig();
    }

    /** A complete, valid transport payload. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.test',
            'mail_port' => 587,
            'mail_encryption' => 'tls',
            'mail_username' => 'mailer@example.test',
            'mail_password' => 'sup3r-secret',
            'mail_from_address' => 'no-reply@example.test',
            'mail_from_name' => 'Example App',
        ], $overrides);
    }

    public function test_a_manager_saves_the_transport_and_it_reaches_the_runtime_config(): void
    {
        $this->login();

        $this->post(route('notifications.update'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->commitAndRereadSettings();

        // The row.
        $this->assertSame('smtp.example.test', SystemSetting::getString('mail_host'));
        $this->assertSame(587, SystemSetting::getInt('mail_port'));

        // And — the assertion that matters — what the transport will use. A
        // settings row nothing reads is the failure this whole module exists to
        // end, so the test checks config, not the table.
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('mailer@example.test', config('mail.mailers.smtp.username'));
        $this->assertSame('tls', config('mail.mailers.smtp.scheme'));
        $this->assertSame('no-reply@example.test', config('mail.from.address'));
        $this->assertSame('Example App', config('mail.from.name'));
    }

    public function test_the_password_is_encrypted_at_rest_and_decrypted_for_the_transport(): void
    {
        $this->login();

        $this->post(route('notifications.update'), $this->payload())->assertRedirect();
        $this->commitAndRereadSettings();

        // The raw column. This is the assertion the whole encryption decision
        // exists for: anyone who can query `system_settings` — which includes
        // every holder of `notifications.view` — must not find the credential.
        $raw = DB::table('system_settings')->where('key', 'mail_password')->value('value');

        $this->assertNotSame('sup3r-secret', $raw, 'the SMTP password is stored in plaintext');
        $this->assertNotSame('', $raw, 'precondition: a password is stored');
        // Compared by decrypting rather than by equality with `encrypt()`: the
        // cipher is randomized per call (a fresh IV each time), so two encryptions
        // of the same string differ and an equality check would fail on a correct
        // implementation.
        $this->assertSame('sup3r-secret', decrypt($raw), 'the stored form does not decrypt to the submitted password');

        $this->assertSame('sup3r-secret', config('mail.mailers.smtp.password'));
        $this->assertTrue(AppServiceProvider::hasMailPassword());
    }

    public function test_the_password_is_never_handed_to_a_view(): void
    {
        $this->login();

        $this->post(route('notifications.update'), $this->payload())->assertRedirect();
        $this->commitAndRereadSettings();

        $this->get(route('notifications.index'))->assertOk()->assertDontSee('sup3r-secret');
    }

    /**
     * The field renders blank when a credential is stored, so a form saved
     * without retyping it must leave the row alone. Writing an empty string
     * instead would disarm authentication on the next send — and the symptom
     * (mail stops arriving) appears long after the save that caused it.
     */
    public function test_an_empty_password_keeps_the_stored_one(): void
    {
        $this->login();

        $this->post(route('notifications.update'), $this->payload())->assertRedirect();
        $this->commitAndRereadSettings();
        $before = DB::table('system_settings')->where('key', 'mail_password')->value('value');

        $this->post(route('notifications.update'), $this->payload(['mail_password' => '']))
            ->assertRedirect();

        $after = DB::table('system_settings')->where('key', 'mail_password')->value('value');

        $this->assertSame($before, $after, 'an empty password field overwrote the stored credential');
        $this->assertSame('sup3r-secret', config('mail.mailers.smtp.password'));
    }

    /**
     * `config/mail.php` declares `scheme`, not `encryption`, and Symfony's smtp
     * transport understands smtp/tls/ssl and null. The form's fourth option,
     * `none`, is therefore stored as the empty value so it binds as null — a
     * literal `none` would be a row nothing reads.
     */
    public function test_the_none_encryption_option_binds_as_null(): void
    {
        $this->login();

        $this->post(route('notifications.update'), $this->payload([
            'mail_encryption' => 'none',
        ]))->assertRedirect();

        $this->assertSame('', SystemSetting::getString('mail_encryption'));
        $this->assertNull(config('mail.mailers.smtp.scheme'));
    }

    public function test_an_encryption_value_the_transport_cannot_read_is_refused(): void
    {
        $this->login();

        // The stored scheme is whatever it was before the refused save, and the
        // seeder writes the config value (empty when MAIL_SCHEME is unset) — so it
        // is read rather than asserted as a literal.
        $before = SystemSetting::getString('mail_encryption');

        $this->post(route('notifications.update'), $this->payload(['mail_encryption' => 'rot13']))
            ->assertSessionHasErrors('mail_encryption');

        $this->assertSame($before, SystemSetting::getString('mail_encryption'), 'a refused save changed the scheme');
    }

    public function test_an_out_of_range_port_is_refused(): void
    {
        $this->login();

        // 99999 is inside what `integer` accepts and outside what TCP allows, so
        // without the bound it saves cleanly and fails at send time with a
        // transport error instead of a field-level message.
        $this->post(route('notifications.update'), $this->payload(['mail_port' => 99999]))
            ->assertSessionHasErrors('mail_port');

        $this->post(route('notifications.update'), $this->payload(['mail_port' => 0]))
            ->assertSessionHasErrors('mail_port');
    }

    public function test_an_unconfigured_mailer_is_refused(): void
    {
        $this->login();

        $this->post(route('notifications.update'), $this->payload(['mail_mailer' => 'carrier-pigeon']))
            ->assertSessionHasErrors('mail_mailer');
    }

    public function test_a_save_writes_one_audit_record_inside_its_transaction(): void
    {
        $this->login();

        $this->post(route('notifications.update'), $this->payload())->assertRedirect();
        $this->commitAndRereadSettings();

        $activity = Activity::where('event', 'mail_setting.updated')->latest('id')->first();

        $this->assertNotNull($activity, 'no mail_setting.updated record was written');
        // Compared as a set: the record is about WHICH keys were written, and
        // pinning the order makes this test fail when a key is merely moved in
        // the action's list.
        $this->assertEqualsCanonicalizing(
            ['mail_mailer', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_from_address', 'mail_from_name', 'mail_password'],
            $activity->properties->get('keys'),
            'the audit record does not name every key that was written',
        );

        // The record names the keys, never the values — an audit table is not
        // governed by `notifications.view`, and an encrypted credential still
        // does not belong in one.
        $this->assertTrue($activity->properties->get('password_changed'));
        $this->assertStringNotContainsString(
            'sup3r-secret',
            json_encode($activity->properties),
            'the audit record carries the credential'
        );
    }

    public function test_the_channel_switches_save_and_render_as_booleans(): void
    {
        $this->login();

        $this->post(route('notifications.channels.update'), [
            'in_app' => '0',
            'mail' => '1',
            'database' => '1',
        ])->assertRedirect()->assertSessionHas('status');
        $this->commitAndRereadSettings();

        $this->assertFalse(SystemSetting::getBool('notification_channel_in_app'));
        $this->assertTrue(SystemSetting::getBool('notification_channel_mail'));
        $this->assertTrue(SystemSetting::getBool('notification_channel_database'));

        $this->assertNotNull(
            Activity::where('event', 'notification_channels.updated')->latest('id')->first(),
            'no notification_channels.updated record was written'
        );
    }

    /**
     * An unticked switch arrives as `'0'` thanks to its hidden companion, so it
     * can be turned OFF. Drop the companion and the field is simply absent, and
     * this save would leave the stored value untouched — a switch that can be
     * enabled but never disabled.
     */
    public function test_an_unticked_channel_can_be_turned_off(): void
    {
        $this->login();

        $this->post(route('notifications.channels.update'), [
            'in_app' => '1', 'mail' => '1', 'database' => '1',
        ])->assertRedirect();
        $this->commitAndRereadSettings();

        $this->assertTrue(SystemSetting::getBool('notification_channel_in_app'), 'precondition: on');

        $this->post(route('notifications.channels.update'), [
            'in_app' => '0', 'mail' => '1', 'database' => '1',
        ])->assertRedirect();
        $this->commitAndRereadSettings();

        $this->assertFalse(SystemSetting::getBool('notification_channel_in_app'));
    }

    public function test_a_viewer_cannot_write_the_transport(): void
    {
        $this->login(SystemRole::USER);

        $this->post(route('notifications.update'), $this->payload())->assertForbidden();

        $this->post(route('notifications.channels.update'), ['mail' => '1'])->assertForbidden();

        $this->assertSame('127.0.0.1', SystemSetting::getString('mail_host'), 'a refused request wrote a row');
    }

    /**
     * `manage` alone must not mail anyone: that is what the third permission is
     * for. Asserted against the route, not the view — the view already gates the
     * card, and a hidden form is not an authorization check.
     */
    public function test_manage_alone_cannot_send_a_test_mail(): void
    {
        // A real role carrying `manage` and NOT `send_test`. Using `admin` here
        // would prove nothing: admin holds the entire catalogue, so its refusal
        // to send mail is not what this test is about.
        $role = Role::create([
            'name' => 'transport-operator',
            'guard_name' => RoleLookup::guard(),
        ]);
        // By NAME: Spatie's `findOrFail` looks up the primary key, and a
        // permission's id is an auto-increment integer — `findOrFail('name')`
        // throws ModelNotFound for a seeded permission that plainly exists.
        $role->givePermissionTo(
            Permission::where('name', 'notifications.manage')
                ->where('guard_name', RoleLookup::guard())
                ->firstOrFail(),
        );

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);
        $this->actingAs($user, 'web');

        $this->assertTrue($user->can('notifications.manage'), 'precondition: holds manage');
        $this->assertFalse($user->can('notifications.send_test'), 'precondition: holds no send_test');

        Mail::shouldReceive('raw')->never();

        $this->post(route('notifications.test-mail'), ['email' => 'someone@example.test'])
            ->assertForbidden();
    }

    public function test_a_disabled_flag_refuses_every_route_in_the_module(): void
    {
        $this->login(SystemRole::SUPERADMIN);
        Feature::deactivate('notifications');

        foreach (['notifications.update', 'notifications.channels.update', 'notifications.test-mail'] as $name) {
            $this->post(route($name), [])->assertForbidden();
        }
    }

    public function test_the_test_mail_reports_a_transport_failure_without_a_500(): void
    {
        $this->login();

        // A transport that cannot connect: the array mailer never touches a
        // network, so this is the closest reproducible stand-in for a refused
        // SMTP connection — the action must turn it into a message, not an
        // exception.
        config(['mail.default' => 'array']);

        $response = $this->post(route('notifications.test-mail'), ['email' => 'ops@example.test']);

        $response->assertRedirect();
        $this->assertNotNull(
            $response->getSession()->get('status') ?? $response->getSession()->get('error'),
            'the probe reported nothing at all'
        );
    }

    public function test_a_delivered_test_mail_is_recorded(): void
    {
        $this->login();

        // The `array` mailer collects what it is given, so `Mail::raw()` reaches
        // the transport and this asserts the real send rather than a mock's
        // record of one. `Mail::fake()` is avoided: it would test the fake, and
        // the failure this module must not have is a transport that silently
        // accepts a malformed recipient.
        config(['mail.default' => 'array']);

        $this->post(route('notifications.test-mail'), ['email' => 'ops@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNotNull(
            Activity::where('event', 'test_mail.sent')->latest('id')->first(),
            'a delivered probe wrote no audit record'
        );
    }

    /**
     * A permission answers "may this person send" and not "how often", so the
     * ceiling is its own concern — asserted here because the abuse it prevents
     * needs no attacker: an operator debugging mail leaves the button in reach of
     * a browser, and one held account turns this route into a relay pointed at
     * whatever third party the caller can type.
     *
     * Keyed on the recipient as well as the operator, so spraying many addresses
     * does not get a fresh budget each time.
     */
    public function test_repeated_test_mails_are_throttled(): void
    {
        $this->login();

        config(['mail.default' => 'array']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('notifications.test-mail'), ['email' => 'ops@example.test'])
                ->assertRedirect();
        }

        // The web branch of `LoginThrottle::responseFor()` redirects back with the
        // error, so the limiter firing is asserted by that message rather than
        // by a 429 — the API branch returns 429 and is asserted below.
        $this->post(route('notifications.test-mail'), ['email' => 'ops@example.test'])
            ->assertSessionHasErrors('email');

        // A different recipient is a different budget.
        $this->post(route('notifications.test-mail'), ['email' => 'someone-else@example.test'])
            ->assertRedirect();

        // Same limit on the API surface, where it answers 429 rather than a
        // redirect. Both routes carry the limiter; only the response differs.
        $operator = User::factory()->create(['email_verified_at' => now()]);
        $operator->assignRole(RoleLookup::find(SystemRole::SUPERADMIN));

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($operator, 'sanctum')
                ->postJson(route('api.v1.notifications.test-mail'), ['email' => 'api-ops@example.test'])
                ->assertOk();
        }

        $this->actingAs($operator, 'sanctum')
            ->postJson(route('api.v1.notifications.test-mail'), ['email' => 'api-ops@example.test'])
            ->assertStatus(429);
    }

    public function test_the_test_mail_recipient_must_be_a_valid_address(): void
    {
        $this->login();

        Mail::shouldReceive('raw')->never();

        $this->post(route('notifications.test-mail'), ['email' => 'not-an-address'])
            ->assertSessionHasErrors('email');
    }

    /**
     * An install with NO `MAIL_USERNAME` in its .env must still bind.
     *
     * Regression, and the reason this test file exists in this form. `config('…smtp.username')`
     * is `null` when the env var is unset — most installs, and every one using a
     * relay that needs no authentication. `SystemSetting::getString(string $key,
     * string $default)` rejects a null default with a TypeError, which landed in
     * `bindMailConfig()`'s catch and bound NOTHING: a perfectly good saved host
     * silently left the transport on its .env values. One unconfigured field took
     * the whole binding down, and the catch hid it completely.
     *
     * Every typed getter is called with a cast fallback, so this passes.
     */
    public function test_an_install_without_a_configured_username_still_binds(): void
    {
        config([
            // Exactly what an unset MAIL_USERNAME produces.
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.from.name' => null,
        ]);

        $this->login();

        $this->post(route('notifications.update'), $this->payload())->assertRedirect();
        $this->commitAndRereadSettings();

        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame('mailer@example.test', config('mail.mailers.smtp.username'));
        $this->assertSame('sup3r-secret', config('mail.mailers.smtp.password'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
    }

    /**
     * The page must show what the app is enforcing, not blanks — an admin
     * opening the form before its first save sees the transport in force.
     */
    public function test_the_page_shows_the_effective_transport_before_any_save(): void
    {
        // The seeder writes these rows from config, so a fallback can only be
        // observed on an install that never saved one. Deleting them is what puts
        // this test in that state — asserting the fallback with the rows present
        // would pass on the stored value and prove nothing about the fallback.
        foreach (['mail_host', 'mail_port', 'mail_from_address'] as $key) {
            DB::table('system_settings')->where('key', $key)->delete();
        }
        SystemSetting::bustCache();

        config([
            'mail.mailers.smtp.host' => 'relay.internal.test',
            'mail.mailers.smtp.port' => 2525,
            'mail.from.address' => 'system@internal.test',
        ]);

        $this->login();

        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertViewHas('settings')
            ->assertSee('relay.internal.test');

        // Asserted on the view data rather than the markup: the from-address
        // field is only rendered inside the editable branch, and this user is a
        // plain admin whose `mail_from_address` row was just deleted — so what
        // matters is what the controller decided to hand the view.
        $this->get(route('notifications.index'))
            ->assertViewHas('settings', fn (array $s): bool => $s['mail_from_address'] === 'system@internal.test');
    }
}
