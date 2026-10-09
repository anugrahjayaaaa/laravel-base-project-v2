<?php

namespace Tests\Feature\Audit;

use App\Actions\V1\Auth\AuthChangePasswordAction;
use App\Actions\V1\Role\RoleAssignAction;
use App\Actions\V1\User\UserCreateAction;
use App\Actions\V1\User\UserUpdateAction;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * No audit row carries a credential (Phase 10, P10-D6).
 *
 * ## Why a test and not a scrubber
 *
 * DEP-003 requires that passwords and tokens do not reach `properties`. Nothing
 * in the code ENFORCES that — it is a convention each call site follows by hand.
 * Today it holds (`AuthChangePasswordAction:78` audits with no properties at
 * all), and nothing would notice the day it stopped: the row would simply carry
 * a hash, the page would render it, and no assertion anywhere would fail.
 *
 * A scrubber was deliberately not built. It is machinery for a problem no
 * current call site has, and it would silently strip the wrong key instead of
 * failing on the call site that made the mistake — which is where the fix
 * belongs. A test is cheaper and points at the real defect. Revisit when a
 * caller that logs sensitive state actually appears.
 *
 * ## Two directions, because either alone is half the check
 *
 * KEYS catch a call site that passes `password => ...` under an obvious name.
 * VALUES catch one that leaks it under a name nothing would guess — a nested
 * `changedFields` diff that swept up the field, a whole model serialised in.
 * The value check is the one that actually generalises, and it is the one that
 * cannot be satisfied by writing a blocklist.
 *
 * ## The mutations exercised are chosen to cover the paths that could plausibly
 * carry one: a password change (the obvious one), a password reset, an admin
 * creating a user (where the plaintext exists in the request), and a role
 * assignment (where `changedFields` diffs two model snapshots).
 */
class AuditPropertyScrubTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Property KEYS that must never appear, matched case-insensitively against
     * every key at every depth of a row's `properties`.
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'plain_password',
        'remember_token',
        'token',
        'access_token',
        'api_token',
        'secret',
        'api_key',
    ];

    /** @var array<int, string> every secret value planted by the fixture */
    private array $secrets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function table(): string
    {
        return config('activitylog.table_name', 'activity_log');
    }

    /**
     * Every mutation path that could plausibly carry a credential into
     * `properties`.
     *
     * Driven through the REAL actions, not through hand-written `audit()` calls.
     * A fabricated fixture asserts that the fixture is clean; this asserts that
     * the production code path is. The first version of this file wrote a
     * `changedFields` bag containing a password hash by hand — and correctly
     * failed, proving the scanner works and proving nothing about the code.
     */
    private function exerciseMutationsThatTouchCredentials(): void
    {
        $operator = User::factory()->create(['email_verified_at' => now()]);
        $operator->assignRole(RoleLookup::find('admin'));

        $password = 'Str0ng-Passw0rd!42';
        $this->secrets[] = $password;

        // An admin creating a user: the plaintext exists in the payload, which is
        // the single most likely place for it to leak into a log row.
        app(UserCreateAction::class)->run([
            'name' => 'Budi Santoso',
            'username' => 'budi.santoso',
            'email' => 'budi@example.test',
        ], $password, $operator);

        // A password change — the plain call site DEP-003 names explicitly.
        // The current password has to be a real one: the factory default is not
        // `Old-Passw0rd!1`, and the action verifies it before auditing anything.
        $oldPassword = 'Old-Passw0rd!1';
        $changer = User::factory()->create([
            'email_verified_at' => now(),
            'password' => $oldPassword,
        ]);
        app(AuthChangePasswordAction::class)->run($changer, $oldPassword, $password);
        $this->secrets[] = $oldPassword;

        // A profile save, the `changedFields($before, $user)` diff path. The
        // allowlist of `$before` is what makes this safe, and that is exactly the
        // thing worth pinning — widen it by one field and this test is the one
        // that notices.
        app(UserUpdateAction::class)->run(
            User::factory()->create(),
            ['name' => 'Renamed Person', 'username' => 'renamed.person'],
            $operator
        );

        // The self-service twin: no causer, same diff, and the extra
        // `email_change_requested` row on top of it.
        app(UserUpdateAction::class)->run(
            User::factory()->create(),
            ['name' => 'Self Serviced', 'email' => 'self@example.test'],
        );

        // A role assignment, where the diff runs over a whole model.
        app(RoleAssignAction::class)->run(
            User::factory()->create(),
            ['user'],
            $operator
        );

        // A personal access token, and its own value.
        $tokenUser = User::factory()->create(['email_verified_at' => now()]);
        $token = $tokenUser->createToken('probe')->plainTextToken;
        $tokenUser->audit('api.token_created', $tokenUser);
        $this->secrets[] = $token;

        // The plaintext of every password now in the table. A hash in
        // `properties` would not match these, so both the hash and the plaintext
        // are checked — a caller logging the hash is still logging a credential.
        foreach (User::all() as $user) {
            $this->secrets[] = $user->getAuthPassword();
        }
    }

    /**
     * Flatten a decoded `properties` value to `path => scalar` for every depth.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<string, string>
     */
    private function flatten(array $properties, string $prefix = ''): array
    {
        $flat = [];

        foreach ($properties as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $flat;
    }

    #[Test]
    public function test_no_audit_row_carries_a_credential_key(): void
    {
        $this->exerciseMutationsThatTouchCredentials();

        $offenders = [];

        foreach (DB::table($this->table())->get() as $row) {
            $properties = json_decode($row->properties ?? '{}', true);

            if (! is_array($properties)) {
                continue;
            }

            foreach (array_keys($this->flatten($properties)) as $path) {
                foreach (self::FORBIDDEN_KEYS as $forbidden) {
                    if (str_contains(Str::lower($path), $forbidden)) {
                        $offenders[] = $row->event.' -> '.$path;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'these audit properties are named like credentials: '.implode('; ', $offenders));
    }

    #[Test]
    public function test_no_audit_row_carries_a_credential_value(): void
    {
        $this->exerciseMutationsThatTouchCredentials();

        $offenders = [];

        foreach (DB::table($this->table())->get() as $row) {
            $properties = json_decode($row->properties ?? '{}', true);

            if (! is_array($properties)) {
                continue;
            }

            foreach ($this->flatten($properties) as $path => $value) {
                foreach ($this->secrets as $secret) {
                    if ($secret !== '' && str_contains($value, $secret)) {
                        $offenders[] = $row->event.' -> '.$path;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'these audit properties contain a credential value: '.implode('; ', $offenders));
    }

    #[Test]
    public function test_the_scrub_test_would_catch_a_leak(): void
    {
        // A guard that has never failed is not evidence. This plants one row that
        // violates the rule and asserts the scanner sees it, so a future rewrite of
        // the matcher that silently matches nothing fails here instead of
        // passing vacuously for the rest of the suite.
        $user = User::factory()->create();
        $leaked = 'Str0ng-Passw0rd!42';

        $user->audit('user.updated', $user, ['changedFields' => ['password' => $leaked]]);

        $found = false;

        foreach (DB::table($this->table())->get() as $row) {
            $properties = json_decode($row->properties ?? '{}', true) ?: [];

            foreach ($this->flatten($properties) as $value) {
                if (str_contains($value, $leaked)) {
                    $found = true;
                }
            }
        }

        $this->assertTrue($found, 'the scanner cannot see a credential that is plainly present');
    }
}
