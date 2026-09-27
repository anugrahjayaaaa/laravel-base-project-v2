<?php

namespace App\View\Composers;

use App\Models\SystemSetting;
use Illuminate\View\View;

/**
 * Supplies the password policy to the strength checklist partial.
 *
 * Registered for `layouts.partials.password-strength` so every caller
 * (register, reset password, profile, password-expired) gets the same
 * active rule set without repeating the lookup per controller.
 */
class PasswordStrengthComposer
{
    /**
     * Populate the minimum length and the rules the policy currently enforces.
     *
     * Only active rules are listed: rendering the full set unconditionally made
     * an admin-disabled rule look required, and the strength bar still divided
     * by five. The active list goes out as data-rules so the JS scores the
     * same set.
     *
     * Pwned Check is deliberately absent: it is the one rule that leaves the
     * server, and telling the browser to ask a third party about a typed
     * candidate is not a trade worth making. The server reports it on submit.
     *
     * @param  View  $view
     * @return void
     */
    public function compose(View $view): void
    {
        $view->with([
            'passwordMinLength' => SystemSetting::getInt('password_min_length', 12),
            'passwordActiveRules' => array_keys(array_filter([
                'length' => true,
                'upper' => SystemSetting::getBool('password_require_upper', true),
                'lower' => SystemSetting::getBool('password_require_lower', true),
                'digit' => SystemSetting::getBool('password_require_digit', true),
                'symbol' => SystemSetting::getBool('password_require_symbol', true),
            ])),
        ]);
    }
}
