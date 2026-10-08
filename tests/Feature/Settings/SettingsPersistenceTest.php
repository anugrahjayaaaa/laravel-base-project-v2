<?php

namespace Tests\Feature\Settings;

use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Http\Requests\V1\System\SystemSettingRequest;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The settings form and the persistence whitelist are two separate lists, and
 * nothing in the framework connects them. A key that is validated and rendered
 * but absent from SystemSettingsUpdateAction saves without complaint and never
 * takes effect — which is exactly how Allow Self-Registration silently failed
 * to persist. This pins the two lists together.
 */
class SettingsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    // Note: the request is instantiated with `new`, not resolved from the
    // container. FormRequest registers a resolving hook that runs
    // validateResolved() -> authorize(), so `app(...)` would demand
    // settings.manage (P6-C16) from a test that only wants the rule list. The
    // gate itself is covered by GateCAuthorizationTest.

    public function test_every_validated_setting_is_actually_persisted(): void
    {
        $action = app(SystemSettingsUpdateAction::class);
        $validated = array_keys((new SystemSettingRequest())->rules());

        // Every key at once, because a few are written conditionally — the
        // action only stores inactivity_lock_grace_days when the grace toggle
        // travels with it, and a partial payload would flag that as missing.
        $action->run(array_fill_keys($validated, 1));

        $persisted = array_keys(SystemSetting::getAll());
        $missing = array_values(array_diff($validated, $persisted));

        $this->assertSame([], $missing, implode(
            ', ',
            $missing
        ).' validated but never written — add to SystemSettingsUpdateAction::$updates');
    }

    public function test_the_registration_toggle_persists_in_both_directions(): void
    {
        $action = app(SystemSettingsUpdateAction::class);

        $action->run(['registration_enabled' => true]);
        $this->assertTrue(SystemSetting::getBool('registration_enabled', false));

        // An unchecked checkbox is not submitted at all, so the key is absent
        // rather than false — the action still has to turn it off.
        $action->run([]);
        $this->assertFalse(SystemSetting::getBool('registration_enabled', true));
    }

    public function test_a_partial_payload_leaves_every_other_setting_alone(): void
    {
        $action = app(SystemSettingsUpdateAction::class);

        $action->run([
            'password_min_length' => 12,
            'registration_enabled' => true,
            'password_history_count' => 5,
        ]);

        // One key, as an API client sends it. Before the partial flag this also
        // reset password_min_length to 8 and switched registration off, in one
        // 200 response, with no warning.
        $action->run(['login_max_attempts' => 7], partial: true);
        SystemSetting::bustCache();

        $this->assertSame(7, SystemSetting::getInt('login_max_attempts', 0), 'the named key must change');
        $this->assertSame(12, SystemSetting::getInt('password_min_length', 0), 'an absent key must not reset');
        $this->assertTrue(SystemSetting::getBool('registration_enabled', false), 'an absent boolean must not reset');
        $this->assertSame(5, SystemSetting::getInt('password_history_count', 0), 'an absent key must not reset');
    }

    public function test_a_partial_payload_can_still_switch_a_boolean_off(): void
    {
        $action = app(SystemSettingsUpdateAction::class);
        $action->run(['registration_enabled' => true]);

        // Stored booleans are the strings 'true'/'false', and PHP reads
        // 'false' as truthy — backfilling them raw would flip every setting
        // that was off to on.
        $action->run(['registration_enabled' => false], partial: true);
        SystemSetting::bustCache();
        $this->assertFalse(SystemSetting::getBool('registration_enabled', true));

        $action->run([
            'password_require_upper' => true,
            'password_require_symbol' => true,
            'password_require_digit' => true,
        ], partial: true);
        $action->run(['login_max_attempts' => 6], partial: true);
        SystemSetting::bustCache();

        $this->assertTrue(SystemSetting::getBool('password_require_upper', false));
        $this->assertTrue(SystemSetting::getBool('password_require_symbol', false));
        $this->assertTrue(SystemSetting::getBool('password_require_digit', false));
        $this->assertFalse(SystemSetting::getBool('password_history_enabled', true), 'a stored "false" must stay false');
    }

    /**
     * A rolled-back save must not leave its values in the shared cache.
     *
     * `SystemSetting` caches every read under a key other processes share, and
     * two separate paths could publish an uncommitted value there: busting the
     * cache on write (with nothing to un-bust after a rollback), and reading
     * inside the transaction window, which repopulates it from rows that are
     * about to be discarded. Both are silent, and the second one looks
     * intermittent — with no read in the window the cache is merely empty and
     * the next request repairs itself, so the bug shows up only under load.
     *
     * Asserted against the cache store directly: asserting through
     * `getString()` would read the request-level static and could not see a
     * poisoned shared entry.
     */
    public function test_a_rolled_back_save_leaves_the_shared_cache_clean(): void
    {
        SystemSetting::create(['key' => 'password_min_length', 'value' => '12']);
        SystemSetting::bustCache();

        try {
            DB::transaction(function (): void {
                SystemSetting::set('password_min_length', '20');

                // The read is the dangerous part: it repopulates the shared cache
                // from inside the transaction.
                SystemSetting::getString('password_min_length');

                throw new RuntimeException('a later step failed');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        $cached = Cache::get('app_system_settings');

        $this->assertNotContains(
            '20',
            is_array($cached) ? $cached : [],
            'a rolled-back save published its value to the shared cache'
        );

        $this->assertSame(
            '12',
            DB::table('system_settings')->where('key', 'password_min_length')->value('value'),
            'the rollback did not revert the row'
        );
    }

    /**
     * A committed save still reaches the next reader.
     *
     * Guards the other half of the fix: the cache is now busted in
     * `DB::afterCommit`, so a committed value has to become visible afterwards.
     * Reads through a fresh static (as a new request would), not through the one
     * this request already warmed.
     */
    public function test_a_committed_save_reaches_the_next_reader(): void
    {
        SystemSetting::create(['key' => 'password_min_length', 'value' => '12']);
        SystemSetting::bustCache();

        // Warm both caches so they hold the OLD value going in.
        $this->assertSame('12', SystemSetting::getString('password_min_length'));

        DB::transaction(static function (): void {
            SystemSetting::set('password_min_length', '20');
        });

        // The next request starts with an empty request-level static and hits
        // the shared cache, which afterCommit must have cleared.
        SystemSetting::bustCache();

        $this->assertSame(
            '20',
            SystemSetting::getString('password_min_length'),
            'the shared cache still serves the pre-save value after a committed write'
        );
    }
}
