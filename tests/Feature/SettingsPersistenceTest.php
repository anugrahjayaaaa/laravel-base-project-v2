<?php

namespace Tests\Feature;

use App\Actions\V1\System\UpdateSystemSettingsAction;
use App\Http\Requests\System\SystemSettingRequest;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The settings form and the persistence whitelist are two separate lists, and
 * nothing in the framework connects them. A key that is validated and rendered
 * but absent from UpdateSystemSettingsAction saves without complaint and never
 * takes effect — which is exactly how Allow Self-Registration silently failed
 * to persist. This pins the two lists together.
 */
class SettingsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_validated_setting_is_actually_persisted(): void
    {
        $action = app(UpdateSystemSettingsAction::class);
        $validated = array_keys(app(SystemSettingRequest::class)->rules());

        // Every key at once, because a few are written conditionally — the
        // action only stores inactivity_lock_grace_days when the grace toggle
        // travels with it, and a partial payload would flag that as missing.
        $action->run(array_fill_keys($validated, 1));

        $persisted = array_keys(SystemSetting::getAll());
        $missing = array_values(array_diff($validated, $persisted));

        $this->assertSame([], $missing, implode(
            ', ',
            $missing
        ).' validated but never written — add to UpdateSystemSettingsAction::$updates');
    }

    public function test_the_registration_toggle_persists_in_both_directions(): void
    {
        $action = app(UpdateSystemSettingsAction::class);

        $action->run(['registration_enabled' => true]);
        $this->assertTrue(SystemSetting::getBool('registration_enabled', false));

        // An unchecked checkbox is not submitted at all, so the key is absent
        // rather than false — the action still has to turn it off.
        $action->run([]);
        $this->assertFalse(SystemSetting::getBool('registration_enabled', true));
    }

    public function test_a_partial_payload_leaves_every_other_setting_alone(): void
    {
        $action = app(UpdateSystemSettingsAction::class);

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
        $action = app(UpdateSystemSettingsAction::class);
        $action->run(['registration_enabled' => true]);

        // Stored booleans are the strings 'true'/'false', and PHP reads
        // 'false' as truthy — backfilling them raw would flip every setting
        // that was off to on.
        $action->run(['registration_enabled' => false], partial: true);
        SystemSetting::bustCache();
        $this->assertFalse(SystemSetting::getBool('registration_enabled', true));

        $action->run([
            'password_mixed_case' => true,
            'password_symbols' => true,
            'password_numbers' => true,
        ], partial: true);
        $action->run(['login_max_attempts' => 6], partial: true);
        SystemSetting::bustCache();

        $this->assertTrue(SystemSetting::getBool('password_mixed_case', false));
        $this->assertTrue(SystemSetting::getBool('password_symbols', false));
        $this->assertTrue(SystemSetting::getBool('password_numbers', false));
        $this->assertFalse(SystemSetting::getBool('password_history_enabled', true), 'a stored "false" must stay false');
    }
}
