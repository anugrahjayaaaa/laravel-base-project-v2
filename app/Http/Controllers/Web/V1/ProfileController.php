<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Auth\ChangePasswordAction;
use App\Actions\V1\User\UpdateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ProfileUpdateRequest;
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
    ) {
    }

    /**
     * Show the profile edit page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function show()
    {
        return view('pages.profile.edit', [
            'title' => 'Profile',
            // roles, allowUsernameChange, allowEmailChange and the two cooldowns
            // come from AccountOptionsComposer; the identity form is shared with
            // the user pages and must not read the policy twice.
            //
            // No password hint here: the strength checklist under the field
            // already lists the rules the validator enforces, and it is fed by
            // the same settings, so a sentence beside it can only ever drift.
            'user' => Auth::user(),
        ]);
    }

    /**
     * Show the forced password change screen.
     */
    public function showExpiredPassword()
    {
        return response()->view('pages.auth.password-expired', [
            'title' => 'Password Expired',
        ]);
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
