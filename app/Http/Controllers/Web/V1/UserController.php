<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\User\AdminResendVerificationAction;
use App\Actions\User\BulkUserAction;
use App\Actions\User\CancelEmailChangeAction;
use App\Actions\User\CreateUserAction;
use App\Actions\User\DeleteUserAction;
use App\Models\FailedLoginAttempt;
use App\Actions\User\ForceDeleteUserAction;
use App\Actions\User\RequestEmailChangeAction;
use App\Actions\User\RestoreUserAction;
use App\Actions\User\UpdateUserAction;
use App\Actions\User\UserIndexAction;
use App\Actions\User\VerifyEmailChangeAction;
use App\Enums\UserStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\BulkUserRequest;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\EmailChangeRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UserQueryRequest;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(
        private readonly UserIndexAction $indexAction,
        private readonly CreateUserAction $createAction,
        private readonly UpdateUserAction $updateAction,
        private readonly DeleteUserAction $deleteAction,
        private readonly RestoreUserAction $restoreAction,
        private readonly ForceDeleteUserAction $forceDeleteAction,
        private readonly BulkUserAction $bulkAction,
        private readonly AdminResendVerificationAction $resendVerificationAction,
        private readonly RequestEmailChangeAction $requestEmailChangeAction,
        private readonly CancelEmailChangeAction $cancelEmailChangeAction,
        private readonly VerifyEmailChangeAction $verifyEmailChangeAction,
    ) {}

    public function create()
    {
        return view('pages.users.create', [
            'title' => 'Create User',
            'roles' => Role::all(),
        ]);
    }

    public function store(CreateUserRequest $request)
    {
        $user = $this->createAction->run($request->validated());

        $this->audit('user.created', $user, $request->user());

        return redirect()->route('users.index')
            ->with('status', 'User created successfully.');
    }

    public function index(UserQueryRequest $request)
    {
        $status = $request->validated('status') ?? 'active';

        $users = $this->indexAction->run(
            search: $request->validated('search'),
            status: $status,
            sort: $request->validated('sort', 'created_at'),
            direction: $request->validated('direction', 'desc'),
            perPage: $request->validated('per_page', 10),
        );

        $counts = $this->indexAction->counts();

        $badgeClass = function (UserStatusEnum $s, bool $trashed) {
            if ($trashed) {
                return 'bg-danger text-white';
            }
            return match ($s->value) {
                UserStatusEnum::ACTIVE->value => 'bg-success-subtle text-success border border-success-subtle',
                UserStatusEnum::INACTIVE->value
                    => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                UserStatusEnum::LOCKED->value => 'bg-warning-subtle text-warning border border-warning-subtle',
                UserStatusEnum::PENDING_VERIFICATION->value
                    => 'bg-warning-subtle text-dark border border-warning-subtle',
            };
        };

        return view('pages.users.index', [
            'title' => 'Users',
            'users' => $users,
            'filters' => $request->only('search', 'status', 'sort', 'direction'),
            'counts' => $counts,
            'statuses' => UserStatusEnum::cases(),
            'badgeClass' => $badgeClass,
        ]);
    }

    public function show(User $user)
    {
        $initials = str($user->name)->substr(0, 2)->upper();

        return view('pages.users.edit', [
            'title' => 'User Detail',
            'user' => $user,
            'initials' => $initials,
            'statuses' => UserStatusEnum::cases(),
            'allowUsernameChange' => SystemSetting::getBool('allow_username_change', true),
            'allowEmailChange' => SystemSetting::getBool('allow_email_change', true),
            'usernameCooldownDays' => SystemSetting::getInt('username_change_cooldown_days', 30),
            'emailCooldownDays' => SystemSetting::getInt('email_change_cooldown_days', 30),
            'failedLoginCount' => FailedLoginAttempt::where('user_id', $user->id)->sum('attempts'),
        ]);
    }

    public function edit(User $user)
    {
        $initials = str($user->name)->substr(0, 2)->upper();

        return view('pages.users.edit', [
            'title' => 'Edit User',
            'user' => $user,
            'initials' => $initials,
            'statuses' => UserStatusEnum::cases(),
            'allowUsernameChange' => SystemSetting::getBool('allow_username_change', true),
            'allowEmailChange' => SystemSetting::getBool('allow_email_change', true),
            'usernameCooldownDays' => SystemSetting::getInt('username_change_cooldown_days', 30),
            'emailCooldownDays' => SystemSetting::getInt('email_change_cooldown_days', 30),
            'failedLoginCount' => FailedLoginAttempt::where('user_id', $user->id)->sum('attempts'),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->updateAction->run($user, $request->validated());

        $this->audit('user.updated', $user, $request->user());

        return back()->with('status', 'User updated successfully.');
    }

    public function bulkAction(BulkUserRequest $request): RedirectResponse
    {
        $result = $this->bulkAction->run(
            action: $request->validated('action'),
            userIds: $request->validated('user_ids'),
            causer: $request->user(),
        );

        return back()->with('status', "{$result['label']} ({$result['count']} users).");
    }

    public function destroy(Request $request, User $user)
    {
        $this->deleteAction->run($user, $request->user());

        $this->audit('user.deleted', $user, $request->user());

        return back()->with('status', 'User deleted successfully.');
    }

    public function restore(User $user)
    {
        $this->restoreAction->run($user);

        $this->audit('user.restored', $user, auth()->user());

        return back()->with('status', 'User restored successfully.');
    }

    public function forceDelete(Request $request, User $user)
    {
        $this->forceDeleteAction->run($user, $request->user());

        $this->audit('user.force_deleted', $user, $request->user());

        return redirect()->route('users.index')->with('status', 'User permanently deleted.');
    }

    public function resendVerification(Request $request, User $user)
    {
        $result = $this->resendVerificationAction->run($user, $request->ip());

        if (isset($result['error'])) {
            return back()->withErrors(['error' => $result['error']['message']]);
        }

        $this->audit('user.verification_resent', $user, $request->user());

        return back()->with('status', 'Verification email successfully sent to user.');
    }

    public function requestEmailChange(EmailChangeRequest $request, User $user)
    {
        $this->requestEmailChangeAction->run($user, $request->validated('email'));

        $this->audit('user.email_change_requested', $user, $request->user(), ['pending_email' => $request->validated('email')]);

        return back()->with('status', 'Verification email sent to new email address.');
    }

    public function cancelEmailChange(User $user)
    {
        $this->cancelEmailChangeAction->run($user);

        $this->audit('user.email_change_cancelled', $user, auth()->user());

        return back()->with('status', 'Email change cancelled.');
    }

    public function verifyEmailChange(Request $request, User $user)
    {
        $token = $request->query('token') ?? $request->route('token');

        if (! $token) {
            return $this->verifyRedirect('Missing verification token.', false);
        }

        $verified = $this->verifyEmailChangeAction->run($user, $token);

        if (! $verified) {
            return $this->verifyRedirect('Invalid or expired verification link.', false);
        }

        $this->audit('user.email_changed', $user, auth()->user() ?? $user, ['new_email' => $user->fresh()->email]);

        return $this->verifyRedirect('Email changed successfully. Please login with your new email.', true);
    }

    protected function verifyRedirect(string $message, bool $success)
    {
        $route = auth()->check() ? 'dashboard' : 'login';
        $key = $success ? 'status' : 'error';

        return redirect()->route($route)->with($key, $message);
    }
}
