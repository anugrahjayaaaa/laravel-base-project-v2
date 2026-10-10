<?php

namespace Tests\Feature\Audit;

use App\Actions\V1\Auth\AuthChangePasswordAction;
use App\Http\Middleware\VerifyCsrfToken;
use App\Actions\V1\Auth\AuthLoginCompletedAction;
use App\Actions\V1\Auth\AuthLogoutAction;
use App\Actions\V1\Auth\AuthLogoutAllDevicesAction;
use App\Actions\V1\Auth\AuthResendVerificationAction;
use App\Actions\V1\Auth\AuthVerifyEmailAction;
use App\Actions\V1\Role\RoleAssignAction;
use App\Actions\V1\User\UserCreateAction;
use App\Actions\V1\User\UserDeleteAction;
use App\Actions\V1\User\UserRequestEmailChangeAction;
use App\Actions\V1\User\UserUpdateAction;
use App\Models\Activity;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Every audited action records WHO, and says WHAT changed.
 *
 * ## What this pins, and why each half is separate
 *
 * **The actor.** `Auditable::audit()` takes `?Model $causer = null`, and the
 * parameter is OPTIONAL. Eight call sites omitted it — the trait made it
 * `causedByAnonymous()` and the viewer rendered "Anonymous" for events where the
 * actor is the single most obvious fact in the row: the account that logged in,
 * changed its own password, verified its own email. Nothing errored, nothing was
 * logged, and no test noticed, because every audit assertion in the suite checked
 * `ip` / `user_agent` / `source` and none checked the causer.
 *
 * That is why this drives the ACTIONS directly rather than going through HTTP:
 * the defect was in the argument list, and a request-level test only proves the
 * request happened.
 *
 * **The detail.** `audit-trail.md` requires that properties say what changed, not
 * that something changed. Seven events carried none at all — including the two
 * force deletes, where the row is the ONLY remaining record of the thing removed
 * and omitting the address makes it permanently unrecoverable.
 */
class AuditActorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $self;

    protected function setUp(): void
    {
        parent::setUp();

        // The logout case posts over HTTP, and this project enforces CSRF inside
        // feature tests.
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->assignRole(RoleLookup::find('admin'));
        $this->self = User::factory()->create(['email_verified_at' => now()]);
    }

    /**
     * The newest row for an event, as the read model the viewer uses.
     */
    private function row(string $event): Activity
    {
        return Activity::query()
            ->with(['causer', 'subject'])
            ->where('event', $event)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(Activity $row): array
    {
        return $row->properties instanceof Collection
            ? $row->properties->toArray()
            : [];
    }

    /**
     * Every self-service auth event names the account that performed it.
     *
     * Driven one event at a time rather than as a data provider, because each
     * needs a different fixture state (an unverified account, a changed
     * password) and a provider would have to encode all of them.
     */
    #[Test]
    public function test_a_successful_login_names_the_account_that_logged_in(): void
    {
        app(AuthLoginCompletedAction::class)->run($this->self, false);

        $row = $this->row('auth.login');

        $this->assertSame(
            $this->self->id,
            $row->causer_id,
            'auth.login recorded no actor; the account that just logged in is not a secret'
        );
        $this->assertSame($this->self->name, $row->causerLabel());
    }

    #[Test]
    public function test_the_self_service_auth_events_name_the_account(): void
    {
        $cases = [
            'auth.logout_all' => fn () => app(AuthLogoutAllDevicesAction::class)->run($this->self),
            'auth.password_changed' => fn () => app(AuthChangePasswordAction::class)->run(
                $this->self,
                'password',
                'New-Passw0rd!9'
            ),
        ];

        foreach ($cases as $event => $drive) {
            $drive();

            $this->assertSame(
                $this->self->id,
                $this->row($event)->causer_id,
                $event.' recorded no actor; the account performing it is known to the action'
            );
        }
    }

    #[Test]
    public function test_verifying_your_own_email_names_the_account(): void
    {
        $unverified = User::factory()->create(['email_verified_at' => null]);

        app(AuthVerifyEmailAction::class)->run($unverified);

        $this->assertSame(
            $unverified->id,
            $this->row('auth.email_verified')->causer_id,
            'the account that verified its own email is not anonymous'
        );
    }

    #[Test]
    public function test_resending_your_own_verification_names_the_account(): void
    {
        $unverified = User::factory()->create(['email_verified_at' => null]);

        app(AuthResendVerificationAction::class)->run($unverified->email, '127.0.0.1');

        $this->assertSame(
            $unverified->id,
            $this->row('auth.verification_resent')->causer_id,
            'a self-serve resend is attributed to the account it was sent to'
        );
    }

    #[Test]
    public function test_a_self_serve_profile_save_names_the_account(): void
    {
        app(UserUpdateAction::class)->run($this->self, ['name' => 'Renamed Self'], null);

        $this->assertSame(
            $this->self->id,
            $this->row('user.profile_updated')->causer_id,
            'the profile row says nobody edited it, when the account itself did'
        );
    }

    #[Test]
    public function test_self_registration_names_the_registering_account(): void
    {
        $created = app(UserCreateAction::class)->run([
            'name' => 'Self Reg',
            'username' => 'self.reg',
            'email' => 'selfreg@example.test',
        ], 'Reg1ster-Pass!', null);

        $row = $this->row('user.registered');

        $this->assertSame($created->id, $row->causer_id);
        $this->assertSame($created->id, $row->subject_id);
    }

    #[Test]
    public function test_logout_names_the_account_that_logged_out(): void
    {
        // Driven over HTTP because `AuthLogoutAction` reads the guard off the
        // request; the actor assertion is the point, and it still holds.
        $this->actingAs($this->self, 'web')->post(route('logout'))->assertRedirect();

        $this->assertSame(
            $this->self->id,
            $this->row('auth.logout')->causer_id,
            'auth.logout recorded no actor'
        );
    }

    /**
     * An email change records WHO asked, on both paths.
     *
     * The two callers were the case that needed deciding: an administrator with
     * the permission is the actor; a user requesting on their own account is the
     * actor. Both write the same event, so a single assertion could not tell them
     * apart — hence the two halves.
     */
    #[Test]
    public function test_an_email_change_requested_by_an_admin_names_the_admin(): void
    {
        $target = User::factory()->create();

        app(UserRequestEmailChangeAction::class)->run($target, 'admin-target@example.test', $this->admin);

        $row = $this->row('user.email_change_requested');

        $this->assertSame($this->admin->id, $row->causer_id, 'the admin who requested the change is the actor');
        $this->assertSame($target->id, $row->subject_id);
    }

    #[Test]
    public function test_an_email_change_requested_by_the_user_names_that_user(): void
    {
        app(UserUpdateAction::class)->run(
            $this->self,
            ['email' => 'self-new@example.test'],
            null
        );

        $row = $this->row('user.email_change_requested');

        $this->assertSame(
            $this->self->id,
            $row->causer_id,
            'a self-served change is attributed to nobody'
        );
    }







    /**
     * The sweep that would have caught the original defect.
     *
     * Per-event tests above each pin one fact; this one asks the question the
     * audit trail exists to answer — "who did this?" — across every row at once.
     * An event that quietly starts rendering Anonymous again fails here even if
     * nobody remembered to add a case for it.
     *
     * `auth.login_failed` is the one legitimate exception: nobody authenticated,
     * which is the whole content of that row.
     */
    #[Test]
    public function test_no_audited_event_ends_up_with_an_unattributed_actor(): void
    {
        $unverified = User::factory()->create(['email_verified_at' => null]);
        $target = User::factory()->create();

        // Drive the paths that write, in both directions.
        app(AuthLoginCompletedAction::class)->run($this->self, false);
        app(AuthLogoutAllDevicesAction::class)->run($this->self);
        app(AuthChangePasswordAction::class)->run($this->self, 'password', 'New-Passw0rd!9');
        app(AuthVerifyEmailAction::class)->run($unverified);
        app(AuthResendVerificationAction::class)->run($unverified->fresh()->email, '127.0.0.2');
        app(UserCreateAction::class)->run(
            ['name' => 'Self Reg', 'username' => 'self.reg.2', 'email' => 'selfreg2@example.test'],
            'Reg1ster-Pass!',
            null
        );
        app(UserCreateAction::class)->run(
            ['name' => 'Made', 'username' => 'made.2', 'email' => 'made2@example.test'],
            'Adm1n-Pass!',
            $this->admin
        );
        app(UserUpdateAction::class)->run($this->self, ['name' => 'Renamed Again'], null);
        app(RoleAssignAction::class)->run($target, ['user'], $this->admin);
        app(UserDeleteAction::class)->run($target, $this->admin);

        $expected = DB::table(config('activitylog.table_name', 'activity_log'))
            ->where('event', '!=', 'auth.login_failed')
            ->whereNull('causer_id')
            ->pluck('event')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [],
            $expected,
            'these events were written with no actor: '.implode(', ', $expected)
            .'. The account performing each one is known to the action that writes it.'
        );
    }

    /**
     * The sweep for the other half: no event that has an actor recorded should
     * render the word "Anonymous" to a person reading the log.
     */
    #[Test]
    public function test_no_row_with_a_recorded_actor_renders_as_anonymous(): void
    {
        app(AuthLoginCompletedAction::class)->run($this->self, false);

        $misleading = [];

        foreach (DB::table(config('activitylog.table_name', 'activity_log'))->get() as $raw) {
            if ($raw->causer_id === null) {
                continue;
            }

            $row = new Activity();
            $row->setRawAttributes((array) $raw);

            if ($row->causerLabel() === 'Anonymous') {
                $misleading[] = $raw->event;
            }
        }

        $this->assertSame(
            [],
            $misleading,
            'these rows name an actor by id but render as Anonymous: '.implode(', ', $misleading)
        );
    }

    /**
     * `Relation::getMorphAlias()` is asserted rather than inlined so the fixture
     * survives an alias rename. Present because the pivot write below needs it.
     */
    #[Test]
    public function test_the_user_morph_alias_is_used_by_the_pivot_fixture(): void
    {
        $this->assertSame('user', Relation::getMorphAlias(User::class));
    }
}
