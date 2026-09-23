<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\Auth\ChangePassword;
use App\Actions\User\UpdateUserAction;
use App\Enums\UserStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ProfileUpdateRequest;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function __construct(
        private readonly UpdateUserAction $updateAction,
        private readonly ChangePassword $changePasswordAction,
    ) {}

    public function show()
    {
        $user = Auth::user();
        $initials = str($user->name)->substr(0, 2)->upper();
        $allowEmailChange = SystemSetting::getBool('allow_email_change', true);
        $allowUsernameChange = SystemSetting::getBool('allow_username_change', true);
        $emailCooldownDays = (int) SystemSetting::getInt('email_change_cooldown_days', 30);
        $usernameCooldownDays = (int) SystemSetting::getInt('username_change_cooldown_days', 30);

        return view('pages.profile.edit', compact(
            'user', 'initials',
            'allowEmailChange',
            'allowUsernameChange',
            'emailCooldownDays',
            'usernameCooldownDays',
        ));
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        ($this->updateAction)->run($user, $data);

        if ($request->filled('password')) {
            ($this->changePasswordAction)->run(
                user: $user,
                currentPassword: $data['current_password'],
                newPassword: $data['password'],
            );
            $this->audit('auth.password_changed', $user, $user);
        }

        $this->audit('user.profile_updated', $user, $user);

        if ($request->filled('email') && $request->input('email') !== $user->getOriginal('email')) {
            return redirect()->route('profile.show')
                ->with('status', 'Verification email sent to new email address.');
        }

        return redirect()->route('profile.show')
            ->with('status', 'User updated successfully.');
    }
}