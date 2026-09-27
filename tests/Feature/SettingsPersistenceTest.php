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
}
