<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Auth\ChangePasswordAction;
use App\Actions\V1\User\UpdateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ProfileUpdateRequest;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Profile controller, view and update user profile, change password.
 */
class ProfileController extends Controller
{
    /**
     * @param  UpdateUserAction  $updateAction
     * @param  ChangePasswordAction  $ChangePasswordAction
     */
    public function __construct(
        private readonly UpdateUserAction $updateAction,
        private readonly ChangePasswordAction $ChangePasswordAction,
    ) {}

    /**
     * Show the profile edit page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function show()
    {
        $user = Auth::user();
        $initials = str($user->name)->substr(0, 2)->upper();
        $allowEmailChange = SystemSetting::getBool('allow_email_change', true);
        $allowUsernameChange = SystemSetting::getBool('allow_username_change', true);
        $emailCooldownDays = (int) SystemSetting::getInt('email_change_cooldown_days', 30);
        $usernameCooldownDays = (int) SystemSetting::getInt('username_change_cooldown_days', 30);

        // Password policy hint (min length + complexity toggles only; history/expiry intentionally excluded)
        $minPasswordLength = (int) SystemSetting::getInt('password_min_length', 12);
        $passwordUpper = SystemSetting::getBool('password_require_upper', true);
        $passwordLower = SystemSetting::getBool('password_require_lower', true);
        $passwordDigit = SystemSetting::getBool('password_require_digit', true);
        $passwordSymbol = SystemSetting::getBool('password_require_symbol', true);

        $hintParts = ["Use at least {$minPasswordLength} characters"];

        $reqs = [];
        if ($passwordUpper) {
            $reqs[] = 'an uppercase letter';
        }

        if ($passwordLower) {
            $reqs[] = 'a lowercase letter';
        }

        if ($passwordDigit) {
            $reqs[] = 'a number';
        }

        if ($passwordSymbol) {
            $reqs[] = 'a symbol';
        }

        if ($reqs) {
            $hintParts[] = 'with ' . implode(', ', $reqs);
        }
        $passwordPolicyHint = implode(' ', $hintParts) . '.';

        return view('pages.profile.edit', compact(
            'user',
            'initials',
            'allowEmailChange',
            'allowUsernameChange',
            'emailCooldownDays',
            'usernameCooldownDays',
            'passwordPolicyHint',
        ));
    }

    /**
     * Change password for users who must change on first login.
     */
    public function ChangePasswordAction(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (! $request->filled('password')) {
            throw ValidationException::withMessages([
                'password' => ['The new password field is required.'],
            ]);
        }

        ($this->ChangePasswordAction)->run(
            user: $user,
            currentPassword: $data['current_password'],
            newPassword: $data['password'],
        );

        $this->audit('auth.password_changed', $user, $user);

        return redirect()->route('profile.show')
            ->with('status', 'Password changed successfully.');
    }

    /**
     * Update user profile. Changes password if new password provided;
     * requests email change if email was modified.
     *
     * @param  ProfileUpdateRequest  $request
     * @return RedirectResponse
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        ($this->updateAction)->run($user, $data);

        if ($request->filled('password')) {
            ($this->ChangePasswordAction)->run(
                user: $user,
                currentPassword: $data['current_password'],
                newPassword: $data['password'],
            );
            $this->audit('auth.password_changed', $user, $user);
        }

        $this->audit('user.profile_updated', $user, $user);

        if ($request->filled('email') && $data['email'] !== $user->getOriginal('email')) {
            $this->audit('user.email_change_requested', $user, $user, ['pending_email' => $data['email']]);
            return redirect()->route('profile.show')
                ->with('status', 'Verification email sent to new email address.');
        }

        return redirect()->route('profile.show')
            ->with('status', 'User updated successfully.');
    }
}
