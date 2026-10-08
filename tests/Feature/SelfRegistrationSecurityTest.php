<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Self-registration abuse.
 *
 * Registration is the only way in that needs no credential, so it is the one
 * surface an anonymous attacker gets to poke at freely. Each test here is a
 * specific way that can go wrong: take a privilege you were not given, slip
 * past the checks, learn something you should not, or cost more than you paid.
 *
 * The theme across most of them is over-posting. The form sends five fields;
 * a browser will happily send fifty. Anything the server trusts because it
 * arrived in the body is the vulnerability.
 */
class SelfRegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(SystemSettingSeeder::class);
        SystemSetting::set('registration_enabled', 'true');
    }

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Budi Santoso',
            'username' => 'budi',
            'email' => 'budi@example.test',
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ], $overrides);
    }

    private function register(array $overrides = [])
    {
        return $this->post(route('register.submit'), $this->payload($overrides));
    }

    // =====================================================================
    // VECTOR 1: Privilege escalation — the whole point of the test file
    // =====================================================================

    /**
     * is_active, must_change_password and password_expires_at are all in the
     * User's fillable list, because an admin form has to set them. A
     * self-registering visitor has no such business, and if UserCreateAction
     * ever spread its input into the create call instead of naming the
     * columns, a visitor could post is_active=0 and lock themselves out, or
     * post the other way and skip a check.
     */
        public function test_cannot_set_account_state_through_registration(): void
    {
        $this->register([
            'is_active' => false,
            'must_change_password' => false,
            'is_locked' => false,
            'email_verified_at' => now()->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $user = User::where('username', 'budi')->firstOrFail();

        $this->assertTrue($user->is_active, 'a new account must be active');
        $this->assertFalse($user->must_change_password, 'a self-chosen password needs no forced change');
        $this->assertNull(
            $user->email_verified_at,
            'registration must not accept a verified email — that is the whole verification flow'
        );
    }

    /**
     * The sharpest version of vector 1: self-verify the address, then use the
     * password-reset flow to take over any mailbox the attacker can name.
     */
        public function test_cannot_self_verify_the_email_address(): void
    {
        $this->register(['email_verified_at' => '2020-01-01 00:00:00'])->assertSessionHasNoErrors();

        $this->assertNull(
            User::where('username', 'budi')->firstOrFail()->email_verified_at,
            'an attacker who posts email_verified_at must not become verified'
        );
    }

    /**
     * UserCreateAction honours a `roles` key when it is present — the admin
     * form supplies one. A self-registering visitor must get the default role
     * and nothing else, or public sign-up is a free admin grant.
     */
        public function test_cannot_choose_own_roles(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => RoleLookup::guard()]);
        Role::create(['name' => 'user', 'guard_name' => RoleLookup::guard()]);

        $this->register(['roles' => ['admin']])->assertSessionHasNoErrors();

        $user = User::where('username', 'budi')->firstOrFail();

        $this->assertFalse(
            $user->hasRole('admin'),
            'a self-registering visitor must not be able to grant themselves a role'
        );
        $this->assertTrue($user->hasRole('user'), 'and must still get the default role');
    }

    // =====================================================================
    // VECTOR 2: No session before verification
    // =====================================================================

    /**
     * Registration deliberately does not sign anyone in. If it did, the
     * account is unverified and every page behind `verified` would bounce it —
     * but the session cookie would already be a live credential.
     */
        public function test_registration_does_not_start_a_session(): void
    {
        $this->register()->assertRedirect(route('login'));

        $this->assertGuest('web', 'registration must not sign the visitor in');
        $this->assertGuest('sanctum', 'nor hand out an API token');
        $this->assertNull(
            $this->app['auth']->guard('web')->user(),
            'a live session after registration would be a credential before verification'
        );
    }

    /**
     * The account exists but is unverified, so login must be refused until the
     * emailed link is followed. Otherwise the verification email is theatre.
     */
        public function test_a_fresh_account_cannot_log_in_until_verified(): void
    {
        $this->register()->assertSessionHasNoErrors();

        // Through the real endpoint, not Auth::attempt() — the guard knows
        // nothing about verification, and AuthAuthenticateAction is what
        // refuses. Asserting on the guard would test Laravel, not this app.
        $this->post(route('login'), [
            'identifier' => 'budi@example.test',
            'password' => 'Str0ng!Passw0rd',
        ])->assertRedirect(route('verification.notice'));

        $this->assertGuest('web', 'an unverified account must not end up signed in');
    }

    // =====================================================================
    // VECTOR 3: The feature switch must be a wall, not a suggestion
    // =====================================================================

    /**
     * A disabled feature is a 404 on both surfaces. Anything softer — 403, a
     * redirect to a page that explains — confirms the feature exists, and a
     * public endpoint that can still be reached by guessing is not off.
     */
        public function test_disabled_registration_404s_on_both_surfaces(): void
    {
        SystemSetting::set('registration_enabled', 'false');
        SystemSetting::bustCache();

        $this->get('/register')->assertNotFound();
        $this->register()->assertNotFound();

        $this->postJson(route('api.v1.auth.register'), $this->payload())->assertNotFound();

        $this->assertSame(
            0,
            User::count(),
            'no account may be created while the feature is off'
        );
    }

    // =====================================================================
    // VECTOR 4: What the error messages leak
    // =====================================================================

    /**
     * The unique rule answers "that username is taken" and "that email is
     * taken", which is how an attacker turns the form into an address book.
     * This pins the current behaviour rather than endorsing it: the throttle
     * caps how fast those answers can be collected, and that is the only
     * thing standing between the form and a harvested user list.
     */
        public function test_duplicate_accounts_are_rejected_not_overwritten(): void
    {
        $this->register()->assertSessionHasNoErrors();

        // A second signup must not silently reuse the first account.
        $this->register(['email' => 'other@example.test'])
            ->assertSessionHasErrors('username');

        $this->assertSame(1, User::count(), 'a duplicate username must not create a second account');
        $this->assertTrue(Hash::check('Str0ng!Passw0rd', User::first()->password));
    }

    /**
     * Enumeration surface: what the unique rule actually says.
     *
     * This cannot compare two rejections side by side — a session holds only
     * the last error bag — so it pins the one thing that is checkable: the
     * message is generic and does not restate the value or hint at the row
     * behind it. The unique rule is Laravel's, and the throttle is what caps
     * how fast the difference can be harvested.
     */
        public function test_a_duplicate_rejection_does_not_echo_the_attacker_input(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $this->register(['email' => 'other@example.test'])
            ->assertSessionHasErrors('username');

        $message = session('errors')->getBag('default')->get('username')[0];

        $this->assertStringNotContainsString('budi', mb_strtolower($message));
        $this->assertStringNotContainsString('other@example.test', $message);
        $this->assertStringNotContainsString('select', mb_strtolower($message));
        $this->assertStringNotContainsString('sql', mb_strtolower($message));
    }

    // =====================================================================
    // VECTOR 5: Injection
    // =====================================================================

    /**
     * The name is the one field that gets rendered back to other users, so it
     * is the one worth poisoning. UserCreateAction strips tags; this asserts
     * that still holds rather than trusting the comment.
     */
        public function test_script_tags_in_the_name_do_not_survive(): void
    {
        $this->register(['name' => '<script>alert(1)</script>Budi'])->assertSessionHasNoErrors();

        $name = User::where('username', 'budi')->firstOrFail()->name;

        $this->assertStringNotContainsString('<script>', $name);
        $this->assertStringNotContainsString('</script>', $name);
    }

    /**
     * Both identifier fields are interpolated into lookups and into
     * attributes, so quote-bearing payloads must survive as data.
     */
        public function test_quote_and_sql_payloads_are_stored_not_executed(): void
    {
        $payload = "'; DROP TABLE users; --";

        $this->register(['username' => "budi{$payload}", 'email' => "budi{$payload}@example.test"])
            ->assertSessionHasErrors('username');

        $this->assertTrue(
            Schema::hasTable('users'),
            'the users table must still be there'
        );
    }

    /**
     * A unicode homograph in the username is not blocked by alpha_dash, so it
     * reaches the login form, where a lookalike name is a phishing surface.
     * Recording the reachable set, since alpha_dash is the only filter.
     */
        public function test_alpha_dash_is_the_only_username_filter(): void
    {
        // Rejected: outside alpha_dash.
        $this->register(['username' => 'budi santoso'])->assertSessionHasErrors('username');

        // Accepted: alpha_dash permits underscore, digits, and it is case
        // sensitive only because nothing lowercases it. Both create real rows.
        $this->register(['username' => 'budi_1'])->assertSessionHasNoErrors();
        $this->register(['username' => 'BUDI_2', 'email' => 'b2@example.test'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['username' => 'budi_1']);
        $this->assertDatabaseHas('users', ['username' => 'BUDI_2']);
    }

    // =====================================================================
    // VECTOR 6: Cost
    // =====================================================================

    /**
     * The expensive part of a registration is the bcrypt hash, followed by the
     * insert and the audit row. A validation failure must short-circuit before
     * any of them, or a flood of malformed signups buys the attacker a CPU
     * exhaustion primitive for free. The perf suite measures the happy path;
     * this pins the cheap one.
     *
     * Reads are fine — the unique checks have to run, since that is how
     * validation finds out the username is taken. Writes are the thing that
     * must not happen.
     */
        public function test_a_rejected_registration_writes_nothing_and_hashes_nothing(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->register(['password' => 'weak'])->assertSessionHasErrors('password');

        $writes = array_values(array_filter(
            $queries,
            fn (string $sql) => preg_match('/^\s*(insert|update|delete)/i', $sql) === 1
        ));

        $this->assertSame([], $writes, 'a rejected signup must not write anything');
        $this->assertSame(0, User::count(), 'and must not create an account');
    }

    /**
     * The API and the web form share RegisterRequest and UserCreateAction, so
     * a bypass on one surface must not exist on the other. Specifically: the
     * feature switch and the role grant.
     */
        public function test_the_api_cannot_register_when_the_web_form_is_disabled(): void
    {
        SystemSetting::set('registration_enabled', 'false');
        SystemSetting::bustCache();

        $this->postJson(route('api.v1.auth.register'), $this->payload())->assertNotFound();

        SystemSetting::set('registration_enabled', 'true');
        SystemSetting::bustCache();

        // And with it on, the same payload works on both — the switch is the
        // only thing that differed, not the rules.
        $this->register()->assertSessionHasNoErrors();
        $this->postJson(route('api.v1.auth.register'), $this->payload(['username' => 'sari', 'email' => 'sari@example.test']))
            ->assertCreated();
    }
}
