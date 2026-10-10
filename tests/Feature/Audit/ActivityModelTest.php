<?php

namespace Tests\Feature\Audit;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `App\Models\Activity` — the labels the viewer renders.
 *
 * No database: every method under test derives from attributes already on the
 * instance, so constructing one is enough. That keeps these cases fast and makes
 * the badge-ordering tests below read as what they are — a data table over an
 * ordered rule list, not an integration test that happens to pass.
 */
class ActivityModelTest extends TestCase
{
    /**
     * An in-memory row with the given attributes.
     *
     * `forceFill`, not `new Activity([...])`: the model is `$guarded = ['*']`, so
     * the array form throws a `MassAssignmentException`. That is the read-only
     * guarantee working, not a fixture problem — and `forceFill` is the honest
     * way to build an object for a unit test, since nothing is persisted here.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function row(array $attributes = []): Activity
    {
        return (new Activity())->forceFill($attributes + ['description' => 'description']);
    }

    // ---------------------------------------------------------------- labels

    /**
     * The two badge-ordering collisions, both directions.
     *
     * `EVENT_BADGE_RULES` is an ordered substring list, so order is the whole
     * correctness of the method and a reordering is invisible to any test that
     * only checks one event per category. Both failure modes are silent: the page
     * renders, the badge is simply wrong, and nothing reports it.
     *
     * - `deactivated` CONTAINS `activate` — testing only `activated` would pass
     *   with the rules reversed.
     * - `unlocked` CONTAINS `lock` — testing only `locked` would pass with the
     *   rules reversed.
     */
    #[Test]
    #[DataProvider('eventBadgeVariants')]
    public function test_the_event_badge_variant_follows_the_documented_colour_meaning(
        string $event,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            $this->row(['event' => $event])->eventBadgeVariant(),
            'event "'.$event.'" painted the wrong badge'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function eventBadgeVariants(): array
    {
        return [
            // Deactivation collides with activation — the deactivation must win.
            'deactivated' => ['user.deactivated', 'warning'],
            'activated' => ['user.activated', 'success'],

            // Unlock collides with lock — the unlock must win.
            'unlocked' => ['user.unlocked', 'success'],
            'locked' => ['user.locked', 'warning'],
            'account locked' => ['auth.account_locked', 'warning'],

            // Destruction.
            'deleted' => ['user.deleted', 'danger'],
            'force deleted' => ['user.force_deleted', 'danger'],
            'role deleted' => ['role.deleted', 'danger'],

            // Restoration and verification.
            'restored' => ['user.restored', 'success'],
            'email verified' => ['auth.email_verified', 'success'],

            // A failed attempt is the row an operator scans for.
            'login failed' => ['auth.login_failed', 'danger'],

            // Routine changes stay neutral — "a role was updated" is not news.
            'created' => ['user.created', 'neutral'],
            'updated' => ['user.updated', 'neutral'],
            'logged in' => ['auth.login', 'neutral'],
            'logged out' => ['auth.logout', 'neutral'],
            'roles assigned' => ['user.roles_assigned', 'neutral'],
            'setting updated' => ['system_setting.updated', 'neutral'],
        ];
    }

    /**
     * The colour words must be ones `x-ui.badge` actually accepts.
     *
     * A typo here renders as the component's `default` branch — `secondary` —
     * with no error anywhere, so a variant outside the set is a silently
     * different colour on every row of that kind.
     */
    #[Test]
    public function test_every_badge_variant_is_one_the_badge_component_accepts(): void
    {
        $accepted = ['primary', 'success', 'warning', 'danger', 'info', 'neutral'];

        foreach (array_keys(self::eventBadgeVariants()) as $case) {
            $this->assertContains(
                $this->row(['event' => self::eventBadgeVariants()[$case][0]])->eventBadgeVariant(),
                $accepted,
                'event "'.$case.'" produced a variant x-ui.badge does not accept'
            );
        }

        $this->assertContains($this->row(['properties' => ['source' => 'system']])->sourceBadgeVariant(), $accepted);
    }

    // ----------------------------------------------------------- type labels

    /**
     * The stored type must become a word, on BOTH the spellings that exist today.
     *
     * There is no morph map, so a new row holds `App\Models\User`; Group D's
     * backfill rewrites history to `user`. Both must resolve, or the backfill
     * turns every label in the table into "Unknown".
     */
    #[Test]
    #[DataProvider('typeLabels')]
    public function test_a_stored_type_becomes_a_word(?string $stored, string $expected): void
    {
        $this->assertSame($expected, Activity::labelForType($stored));
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function typeLabels(): array
    {
        return [
            'no subject' => [null, 'None'],
            'empty string' => ['', 'None'],
            'user fqcn' => ['App\Models\User', 'User'],
            'role fqcn' => ['App\Models\Role', 'Role'],
            'feature flag fqcn' => ['App\Models\FeatureFlag', 'Feature Flag'],
            'system setting fqcn' => ['App\Models\SystemSetting', 'System Setting'],
            // The multi-word entries are the ones a naive `Str::snake` on the
            // basename also has to reach: `FeatureFlag` -> `feature_flag`.
            'unknown type is not blank' => ['App\Models\Whatever', 'Unknown (App\Models\Whatever)'],
        ];
    }

    // ---------------------------------------------------------- actor labels

    #[Test]
    public function test_a_job_row_reads_as_system_generated(): void
    {
        // The shape `AuditsSystemActivity` actually writes: no causer, and
        // `source` overridden to `system`.
        $row = $this->row(['properties' => ['causer' => 'SYSTEM', 'source' => 'system']]);

        $this->assertSame('System', $row->causerLabel());
        $this->assertTrue($row->isSystemGenerated());
    }

    /**
     * A failed login attempt: no actor was recorded, and no system produced it.
     *
     * This is the pair that separates "no causer" from "the system". The first
     * version of `isSystemGenerated()` returned `$this->causer === null`, which
     * painted a system gear beside every successful login — because
     * `AuthLoginCompletedAction` also recorded `auth.login` with a null causer.
     *
     * That second half is fixed. `auth.login` now names the account that logged
     * in, so a web row with a genuinely null causer is the failed-login shape and
     * nothing else — which is what makes THIS test a meaningful claim rather than
     * a description of a bug. `AuditActorAndDetailTest` pins the successful login
     * to a named actor.
     */
    #[Test]
    public function test_a_web_row_with_no_causer_is_not_system_generated(): void
    {
        $row = $this->row(['properties' => ['source' => 'web', 'identifier' => 'someone@example.com']]);

        $this->assertSame('Anonymous', $row->causerLabel());
        $this->assertFalse($row->isSystemGenerated(), 'a person at a browser is not the system');
    }

    /**
     * A force-deleted user leaves audit rows behind pointing at nobody. The id
     * outlives the record, and a blank actor cell is the failure this whole
     * viewer exists to prevent.
     *
     * `setRelation('causer', null)` states "the relation resolved to nothing"
     * without a database. Leaving it unset would make `morphTo` lazy-load — which
     * is precisely the N+1 the action's `with(['causer', 'subject'])` prevents in
     * production, and which `AuditLogUiRenderTest` pins at zero queries.
     */
    #[Test]
    public function test_a_causer_row_that_is_gone_reads_as_the_deleted_id(): void
    {
        $row = $this->row(['causer_id' => 42, 'causer_type' => User::class]);
        $row->setRelation('causer', null);

        $this->assertSame('Deleted user (#42)', $row->causerLabel());
    }

    #[Test]
    public function test_a_resolved_causer_reads_as_their_name(): void
    {
        $user = new User(['name' => 'Budi Santoso']);

        $row = $this->row(['causer_id' => 7]);
        $row->setRelation('causer', $user);

        $this->assertSame('Budi Santoso', $row->causerLabel());
        $this->assertFalse($row->isSystemGenerated(), 'a resolved person is not the system');
    }

    /**
     * A user with no name falls back to the email rather than rendering blank.
     */
    #[Test]
    public function test_a_resolved_causer_with_no_name_reads_as_their_email(): void
    {
        $user = new User(['name' => '', 'email' => 'ana@example.com']);

        $row = $this->row();
        $row->setRelation('causer', $user);

        $this->assertSame('ana@example.com', $row->causerLabel());
    }

    // --------------------------------------------------------- subject labels

    #[Test]
    public function test_a_missing_subject_reads_as_the_bare_id(): void
    {
        $row = $this->row(['subject_type' => User::class, 'subject_id' => 99]);
        $row->setRelation('subject', null);

        $this->assertSame('#99', $row->subjectLabel());
    }

    #[Test]
    public function test_a_subject_less_event_reads_as_an_em_dash(): void
    {
        $this->assertSame('—', $this->row(['subject_type' => null, 'subject_id' => null])->subjectLabel());
    }

    // ------------------------------------------------------------ properties

    /**
     * Non-scalar values must not raise "Array to string conversion".
     *
     * `UserUpdateAction` passes `changedFields($before, $user)`, and a caller is
     * free to pass anything — so a nested array reaches the detail page. The page
     * whose entire job is to show what was recorded cannot be the thing that
     * crashes on an unusual record.
     */
    #[Test]
    public function test_properties_are_flattened_into_printable_strings(): void
    {
        $row = $this->row(['properties' => [
            'source' => 'web',
            'count' => 3,
            'enabled' => true,
            'disabled' => false,
            'missing' => null,
            'nested' => ['a' => 1, 'b' => ['c' => 2]],
        ]]);

        $flat = $row->displayProperties();

        $this->assertSame('web', $flat['source']);
        $this->assertSame('3', $flat['count']);
        $this->assertSame('true', $flat['enabled']);
        $this->assertSame('false', $flat['disabled']);
        $this->assertSame('—', $flat['missing']);
        $this->assertSame('{"a":1,"b":{"c":2}}', $flat['nested']);
    }

    /**
     * The derived context keys belong in their own card, so the detail page does
     * not print `source` and the IP twice — on the one page whose job is to show
     * the record exactly as stored.
     */
    #[Test]
    public function test_detail_properties_exclude_the_derived_context_keys(): void
    {
        $row = $this->row(['properties' => [
            'source' => 'web',
            'ip' => '10.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'request_id' => 'abc',
            'reason' => 'manual',
        ]]);

        $this->assertSame(['reason' => 'manual'], $row->detailProperties());
        $this->assertCount(5, $row->displayProperties(), 'displayProperties() is the unfiltered set');
    }

    #[Test]
    public function test_a_row_with_null_properties_reads_as_an_empty_set(): void
    {
        $row = $this->row(['properties' => null]);

        $this->assertSame([], $row->displayProperties());
        $this->assertSame([], $row->detailProperties());
        $this->assertSame('unknown', $row->source());
        $this->assertNull($row->extra('ip'));
    }

    // ---------------------------------------------------------------- naming

    /**
     * `extra()` is the null-safe property reader the viewer uses.
     *
     * Spatie's `getExtraProperty()` is `Arr::get($this->properties->toArray(), …)`,
     * which is a method call on null on a row with no properties — the reason the
     * index page 500ed on a legacy row until this existed.
     */
    #[Test]
    public function test_extra_reads_through_the_cast_collection(): void
    {
        $row = $this->row(['properties' => ['source' => 'api', 'nested' => ['deep' => 'yes']]]);

        $this->assertSame('api', $row->extra('source'));
        $this->assertSame('yes', data_get($row->extra('nested'), 'deep'));
        $this->assertSame('fallback', $row->extra('absent', 'fallback'));
    }

    #[Test]
    public function test_properties_are_cast_to_a_collection(): void
    {
        $row = $this->row(['properties' => ['source' => 'web']]);

        $this->assertInstanceOf(Collection::class, $row->properties);
    }

    // ------------------------------------------------------ read-only shape

    /**
     * `$guarded = ['*']` overrides the package's `$guarded = []`.
     *
     * `public`, because PHP refuses to let a child narrow a property's visibility
     * — declaring it `protected` is a fatal at class-load, not a lint error.
     */
    #[Test]
    public function test_nothing_on_the_read_model_is_mass_assignable(): void
    {
        $this->assertSame(['*'], (new Activity())->getGuarded());
    }

    /**
     * `eventName()` falls back to the description.
     *
     * `event` was added to the table after `description` and the column is
     * nullable, so a row from before that migration has one and not the other. An
     * empty filter chip is worse than the description.
     */
    #[Test]
    public function test_the_event_name_falls_back_to_the_description(): void
    {
        $this->assertSame('user.locked', $this->row(['event' => 'user.locked'])->eventName());
        $this->assertSame('legacy', $this->row(['event' => null, 'description' => 'legacy'])->eventName());
        $this->assertSame('legacy', $this->row(['event' => '', 'description' => 'legacy'])->eventName());
    }
}
