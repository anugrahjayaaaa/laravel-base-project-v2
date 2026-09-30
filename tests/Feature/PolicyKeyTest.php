<?php

namespace Tests\Feature;

use App\Http\Requests\System\SystemSettingRequest;
use App\Models\SystemSetting;
use App\Support\PasswordPolicy;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The /settings form and PasswordPolicy are two separate lists of keys, and
 * nothing in the framework connects them.
 *
 * They were not connected here either, and the pair drifted into two matching
 * failures at once:
 *
 *   - the form wrote password_mixed_case / password_numbers / password_symbols,
 *     which PasswordPolicy never read, so those three switches did nothing;
 *   - PasswordPolicy read password_require_upper / _lower / _digit / _symbol,
 *     which the form never wrote, so no admin could reach them at all.
 *
 * Both halves were covered by tests that passed anyway: the seed-key test
 * asserted the require_* keys EXISTED, which is precisely what kept the dead
 * pair alive, and nothing ever checked that a form field reached the policy.
 *
 * So: the form field names, the action's whitelist and the keys the policy
 * reads are all asserted against each other. Adding a switch to one list and
 * forgetting the others now fails here instead of in production.
 */
class PolicyKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RoleSeeder too: registration_default_role is validated with a
        // guard-scoped exists() rule, so the role has to actually be there.
        $this->seed(RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    /**
     * Every key the policy consults, as read from the source rather than
     * restated here — restating is how the two lists drifted in the first place.
     *
     * @return array<int, string>
     */
    private function policyKeys(): array
    {
        $source = file_get_contents(app_path('Support/PasswordPolicy.php'));

        preg_match_all("/get(?:Bool|Int)\('(password_[a-z_]+)'/", $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The checkbox names the /settings form actually renders.
     *
     * @return array<int, string>
     */
    private function formCheckboxKeys(): array
    {
        $source = file_get_contents(resource_path('views/pages/settings/index.blade.php'));

        preg_match_all("/'password_[a-z_]+' => \[/", $source, $matches);

        return array_values(array_unique($matches[0] === [] ? [] : array_map(
            fn ($line) => trim($line, "' =>["),
            $matches[0]
        )));
    }

    public function test_the_form_offers_a_switch_for_every_key_the_policy_reads(): void
    {
        $form = $this->formCheckboxKeys();
        $policy = $this->policyKeys();

        $this->assertNotEmpty($form, 'the form checkbox list could not be parsed — this test would pass vacuously');

        // password_reject_username needs the username to fire, so it is opt-in
        // per call rather than unconditional; the rest are checked on every
        // password. password_min_length is a number input, not a switch.
        $switches = array_values(array_diff($policy, ['password_min_length']));

        $this->assertSame([], array_values(array_diff($switches, $form)), implode(
            ', ',
            array_diff($switches, $form)
        ).' — the policy enforces these but /settings has no control for them, so no admin can change them.');
    }

    public function test_the_policy_reads_every_key_the_form_writes(): void
    {
        $form = $this->formCheckboxKeys();
        $policy = $this->policyKeys();

        $this->assertNotEmpty($form, 'the form checkbox list could not be parsed — this test would pass vacuously');

        $this->assertSame([], array_values(array_diff($form, $policy)), implode(
            ', ',
            array_diff($form, $policy)
        ).' — /settings offers these but the policy never reads them, so the switch does nothing.');
    }

    public function test_the_dead_duplicate_keys_are_gone(): void
    {
        foreach (['password_mixed_case', 'password_numbers', 'password_symbols'] as $key) {
            $this->assertArrayNotHasKey(
                $key,
                SystemSetting::getAll(),
                "{$key} was never read by the policy and must not linger"
            );

            $this->assertStringNotContainsString(
                $key,
                implode(' ', array_keys((new SystemSettingRequest())->rules())),
                "{$key} must not be a validated setting"
            );
        }
    }

    public function test_turning_a_rule_off_actually_relaxes_the_policy(): void
    {
        // The end-to-end version of the bug: uncheck the switch, save, and the
        // rule has to stop applying. Every rule is checked, because each of the
        // three dead switches failed this silently and identically.
        $off = [
            'password_require_upper' => 'Aaaaaaaaaaaa1!',
            'password_require_lower' => 'AAAAAAAAAAAA1!',
            'password_require_digit' => 'AAAAAAAAAAAAa!',
            'password_require_symbol' => 'AAAAAAAAAAAAa1',
        ];

        foreach ($off as $key => $password) {
            SystemSetting::set($key, 'false');
            SystemSetting::bustCache();

            $this->assertSame(
                [],
                PasswordPolicy::validate($password),
                "turning {$key} off must relax the policy for '{$password}'"
            );

            SystemSetting::set($key, 'true');
            SystemSetting::bustCache();
        }
    }

    public function test_the_checklist_renders_the_active_rules_and_only_those(): void
    {
        // The checklist ships the active rule names to the browser as
        // data-rules, and the strength bar scores exactly that set. A blade slip
        // here is invisible to the policy tests: implode() over the values
        // instead of the keys renders data-rules="1,1,1,1,1", every row is
        // hidden, and the JS silently falls back to scoring all five.
        $render = function () {
            return (string) view('layouts.partials.password-strength')->render();
        };

        $html = $render();
        $this->assertStringContainsString('data-rules="length,upper,lower,digit,symbol"', $html);
        $this->assertSame(
            ['length', 'upper', 'lower', 'digit', 'symbol'],
            $this->ruleNamesIn($html),
            'every enforced rule gets a checklist row'
        );

        SystemSetting::set('password_require_upper', 'false');
        SystemSetting::set('password_require_symbol', 'false');
        SystemSetting::bustCache();

        $html = $render();
        $this->assertStringContainsString('data-rules="length,lower,digit"', $html);
        $this->assertSame(
            ['length', 'lower', 'digit'],
            $this->ruleNamesIn($html),
            'a rule the policy no longer enforces must not be listed as required'
        );
    }

    /**
     * @return array<int, string>
     */
    private function ruleNamesIn(string $html): array
    {
        preg_match_all('/data-rule="([a-z]+)"/', $html, $matches);

        return $matches[1];
    }

    public function test_every_settings_switch_can_actually_be_turned_off(): void
    {
        // A bare checkbox is ABSENT from the payload when unticked, and
        // SystemSettingsUpdateAction falls back to `?? true` for it — so the
        // action writes the setting straight back ON and the switch looks like
        // it saved. Six of the seven original switches had no hidden companion
        // and could only ever be enabled. The companion input is what makes
        // "off" reach the server.
        $html = $this->settingsPage();

        preg_match_all('/type="checkbox" name="([a-z_]+)"/', $html, $found);
        $switches = array_values(array_unique($found[1]));

        $this->assertGreaterThan(5, count($switches), 'the settings form should offer several switches');

        foreach ($switches as $key) {
            $this->assertStringContainsString(
                '<input type="hidden" name="'.$key.'" value="0">',
                $html,
                "{$key} has no hidden companion, so unticking it can never persist"
            );
        }
    }

    public function test_unticking_a_switch_turns_the_setting_off_end_to_end(): void
    {
        // What a browser actually sends for an unticked box with its hidden
        // companion: the companion's "0" and nothing else. Ticking it adds "1"
        // after, which wins. Asserting the value is "0" rather than omitting the
        // key is the point — the old bug was the key arriving absent, which the
        // action's `?? true` read as ON.
        $response = $this->actingAs($this->superAdmin())
            ->from('/settings')
            ->post(route('settings.update'), $this->settingsPayload(overrides: [
                'password_require_upper' => '0',
                'password_require_symbol' => '0',
            ]));

        $response->assertRedirect('/settings')->assertSessionHasNoErrors();

        SystemSetting::bustCache();
        $this->assertFalse(SystemSetting::getBool('password_require_upper', true));
        $this->assertFalse(SystemSetting::getBool('password_require_symbol', true));
        $this->assertTrue(SystemSetting::getBool('password_require_digit', true), 'untouched switches keep their value');
    }

    /**
     * The live /settings markup, so these tests read the page a browser gets
     * rather than a hand-fed view render that skips the composers.
     */
    private function settingsPage(): string
    {
        return $this->actingAs($this->superAdmin())
            ->get('/settings')
            ->assertOk()
            ->getContent();
    }

    /**
     * The rendered form's own field set, so the test cannot drift from the form.
     * Hidden companions are included: the browser sends value="0" for an
     * unticked box, which is exactly the case under test.
     *
     * @param  array<string, mixed>  $overrides  Key => value, or null to omit.
     * @return array<string, mixed>
     */
    private function settingsPayload(array $overrides = []): array
    {
        $html = $this->settingsPage();

        preg_match_all('/<input[^>]*name="([a-z_]+)"/i', $html, $inputs);
        preg_match_all('/<select[^>]*name="([a-z_]+)"/i', $html, $selects);

        $payload = [];

        $stored = SystemSetting::getAll();

        foreach (array_unique(array_merge($inputs[1], $selects[1])) as $key) {
            $payload[$key] = match (true) {
                $key === '_token' => csrf_token(),
                // Time, timezone and the selects have to carry a real value, not
                // a placeholder. What is stored is what the form would submit.
                default => (string) ($stored[$key] ?? '1'),
            };
        }

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);

                continue;
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    private function superAdmin(): \App\Models\User
    {
        $user = \App\Models\User::where('username', 'superadmin')->first();

        if (! $user) {
            $user = \App\Models\User::create([
                'username' => 'superadmin',
                'name' => 'Super Admin',
                'email' => 'superadmin@example.test',
                'password' => 'Superadmin#2026',
            ]);
            $user->markEmailAsVerified();
        }

        // The username alone grants nothing: Gate::before keys on the superadmin
        // ROLE, so without this the settings.manage gate (P6-C16) refuses it.
        $user->assignRole(\App\Models\RoleLookup::find(\App\Support\SystemRole::SUPERADMIN));

        return $user;
    }

    public function test_a_pwned_password_is_rejected_when_the_check_is_on(): void
    {
        // HIBP range API, k-anonymity. The verifier hashes the candidate itself
        // and matches hashPrefix.suffix, so the fake has to carry the real
        // suffix of a real password — D1FB4EDBC98820B343A41FA6C6F5691EFE43583D
        // is the SHA-1 of 'Leaked#Pass2024'.
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response(
                'EDBC98820B343A41FA6C6F5691EFE43583D:3'."\n".'26BFB2CE51DCD5E07F89327505CD8A3522B:1'
            ),
        ]);

        SystemSetting::set('password_uncompromised', 'true');
        SystemSetting::bustCache();

        // Passes every complexity rule and is still in the breach list.
        $errors = PasswordPolicy::validate('Leaked#Pass2024');

        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'breach')),
            'a password in the breach range must be rejected: '.json_encode($errors)
        );

        // Listed in the same range but with a count of 1 — HIBP's threshold is
        // 0, so a single occurrence still counts.
        $this->assertNotSame([], PasswordPolicy::validate('Str0ng#Pass!2024'));
    }

    public function test_the_pwned_check_is_off_by_default_and_honours_the_toggle(): void
    {
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('EDBC98820B343A41FA6C6F5691EFE43583D:3'),
        ]);

        $this->assertSame([], PasswordPolicy::validate('Leaked#Pass2024'), 'off by default');

        SystemSetting::set('password_uncompromised', 'false');
        SystemSetting::bustCache();
        $this->assertSame([], PasswordPolicy::validate('Leaked#Pass2024'));

        SystemSetting::set('password_uncompromised', 'true');
        SystemSetting::bustCache();
        $this->assertNotSame([], PasswordPolicy::validate('Leaked#Pass2024'));
    }

    public function test_a_pwned_api_outage_does_not_lock_everyone_out(): void
    {
        // Laravel's verifier reports the connection error and returns an empty
        // result set, which reads as "not pwned". That is the behaviour, and it
        // is the safer one: a third-party outage must not block password changes.
        Http::fake(['api.pwnedpasswords.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('HIBP down')]);

        SystemSetting::set('password_uncompromised', 'true');
        SystemSetting::bustCache();

        $this->assertSame([], PasswordPolicy::validate('Str0ng#Pass!2024'));
    }

    public function test_the_complexity_rules_still_apply_when_pwned_check_is_on(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

        SystemSetting::set('password_uncompromised', 'true');
        SystemSetting::bustCache();

        // Fail-open on the network call must not soften the local rules. Lower
        // case is present, so it is the only rule that passes.
        $this->assertSame(
            [
                'Password must contain at least one uppercase letter.',
                'Password must contain at least one number.',
                'Password must contain at least one symbol.',
            ],
            PasswordPolicy::validate('aaaaaaaaaaaa')
        );
    }
}
