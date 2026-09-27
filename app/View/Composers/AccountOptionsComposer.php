<?php

namespace App\View\Composers;

use App\Models\SystemSetting;
use App\Models\RoleLookup;
use Illuminate\View\View;

/**
 * Supplies the assignable roles and the identity-change policy to views.
 *
 * These are not page-specific. `RoleLookup::assignable()` was called by three
 * controllers and the four identity settings were read twice with the same keys
 * and the same defaults, so a page could show a cooldown the form then
 * disagreed with. One composer, one lookup, every page that needs it.
 *
 * Registered for the pages that render an identity or role field. Blade's
 * @include shares the parent view's variables, so partials
 * (user-identity-fields, user-role-picker) are covered by their caller.
 *
 * Deliberately NOT here:
 *   - failedLoginCount, a per-user aggregate that only the user detail page has
 *   - statuses, a zero-cost static list with a single reader
 *   - canResendVerification, one reader and a different concern (verification
 *     mode, not the identity form)
 * Those are page data and belong to the controller that serves the page.
 */
class AccountOptionsComposer
{
    /**
     * Populate the shared form options.
     *
     * @param  View  $view
     * @return void
     */
    public function compose(View $view): void
    {
        $view->with([
            'roles' => RoleLookup::assignable(),
            'allowUsernameChange' => SystemSetting::getBool('allow_username_change', true),
            'allowEmailChange' => SystemSetting::getBool('allow_email_change', true),
            'usernameCooldownDays' => SystemSetting::getInt('username_change_cooldown_days', 30),
            'emailCooldownDays' => SystemSetting::getInt('email_change_cooldown_days', 30),
        ]);
    }
}
