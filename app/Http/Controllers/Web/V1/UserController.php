<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\User\UserAdminResendVerificationAction;
use App\Actions\V1\BulkAction\BulkActionProcessor;
use App\Actions\V1\User\UserBulkActionHandler;
use App\Actions\V1\User\UserCancelEmailChangeAction;
use App\Actions\V1\User\UserCreateAction;
use App\Actions\V1\User\UserDeleteAction;
use App\Models\FailedLoginAttempt;
use App\Actions\V1\User\UserForceDeleteAction;
use App\Actions\V1\User\UserRequestEmailChangeAction;
use App\Actions\V1\User\UserRestoreAction;
use App\Actions\V1\User\UserUpdateAction;
use App\Actions\V1\User\UserIndexAction;
use App\Actions\V1\User\UserVerifyEmailChangeAction;
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
use Illuminate\Support\Facades\Auth;

/**
 * User management controller,CRUD, bulk actions, email verification flow.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserIndexAction $indexAction,
        private readonly UserCreateAction $createAction,
        private readonly UserUpdateAction $updateAction,
        private readonly UserDeleteAction $deleteAction,
        private readonly UserRestoreAction $restoreAction,
        private readonly UserForceDeleteAction $forceDeleteAction,
        private readonly BulkActionProcessor $processor,
        private readonly UserBulkActionHandler $userBulkActionHandler,
        private readonly UserAdminResendVerificationAction $resendVerificationAction,
        private readonly UserRequestEmailChangeAction $requestEmailChangeAction,
        private readonly UserCancelEmailChangeAction $cancelEmailChangeAction,
        private readonly UserVerifyEmailChangeAction $verifyEmailChangeAction,
    ) {
    }

    /**
     * Show the create user page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function create()
    {
        return view('pages.users.create', [
            'title' => 'Create User',
        ]);
    }

    /**
     * Create a new user.
     *
     * @param  CreateUserRequest  $request
     * @return RedirectResponse
     */
    public function store(CreateUserRequest $request)
    {
        $user = $this->createAction->run($request->validated(), causer: $request->user());

        $user->audit('user.created', $request->user());

        return redirect()->route('users.index')
            ->with('status', 'User created successfully.');
    }

    /**
     * List users with search, filtering, and pagination.
     *
     * @param  UserQueryRequest  $request
     * @return \Illuminate\Contracts\View\View
     */
    public function index(UserQueryRequest $request)
    {
        $status = $request->validated('status') ?? 'active';

        $users = $this->indexAction->run(
            search: $request->validated('search'),
            status: $status,
            sort: $request->validated('sort', 'created_at'),
            direction: $request->validated('direction', 'desc'),
            viewer: $request->user(),
            perPage: $request->validated('per_page', 10),
        );

        $counts = $this->indexAction->counts($request->user());

        return view('pages.users.index', [
            'title' => 'Users',
            'users' => $users,
            'filters' => $request->only('search', 'status', 'sort', 'direction'),
            'counts' => $counts,
            'statuses' => UserStatusEnum::cases(),
        ]);
    }

    /**
     * Show the user detail page.
     *
     * @param  User  $user
     * @return \Illuminate\Contracts\View\View
     */
    public function show(User $user)
    {
        return view('pages.users.edit', [
            'title' => 'User Detail',
            'user' => $user,
            'statuses' => UserStatusEnum::cases(),
            'failedLoginCount' => FailedLoginAttempt::where('user_id', $user->id)->sum('attempts'),
            'canResendVerification' => SystemSetting::getString('email_verification_mode', 'public') !== 'disabled',
        ]);
    }

    /**
     * The resource route advertises both users.show and users.edit, and both
     * render the same view — pages.users.edit. Without this, /users/{id}/edit
     * was a 500 with "undefined method UserController::edit()"; nothing caught
     * it because no test or link used that URL.
     */
    public function edit(User $user)
    {
        return $this->show($user);
    }

    /**
     * Show the edit user page.
     *
     * @param  User  $user
     * @return \Illuminate\Contracts\View\View
     */
    /**
     * Update an existing user. Optionally changes password.
     *
     * @param  UpdateUserRequest  $request
     * @param  User  $user
     * @return RedirectResponse
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $this->updateAction->run($user, $request->validated(), $request->user());

        $user->audit('user.updated', $request->user());

        return back()->with('status', 'User updated successfully.');
    }

    /**
     * Execute bulk user actions (delete, restore, lock, unlock, activate, deactivate).
     *
     * @param  BulkUserRequest  $request
     * @return RedirectResponse
     */
    public function bulkAction(BulkUserRequest $request): RedirectResponse
    {
        $result = $this->processor->run(
            action: $request->validated('action'),
            ids: $request->validated('user_ids'),
            causer: $request->user(),
            handler: $this->userBulkActionHandler,
        );

        if (!empty($result['auditRecords']) && $result['auditEvent']) {
            $this->bulkAudit($result['auditEvent'], $result['auditRecords'], $request->user());
        }

        return back()->with('status', "{$result['count']} selected users have been successfully {$result['label']}.");
    }

    /**
     * Soft-delete a user (moves to trash).
     *
     * @param  Request  $request
     * @param  User  $user
     * @return RedirectResponse
     */
    public function destroy(Request $request, User $user)
    {
        $this->deleteAction->run($user, $request->user());

        $user->audit('user.deleted', $request->user());

        return back()->with('status', "User '{$user->name}' has been successfully deleted.");
    }

    /**
     * Restore a trashed user.
     *
     * @param  User  $user
     * @return RedirectResponse
     */
    public function restore(User $user)
    {
        $this->restoreAction->run($user);

        $user->audit('user.restored', auth()->user());

        return back()->with('status', "User '{$user->name}' has been successfully restored.");
    }

    /**
     * Permanently delete a user from database.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return RedirectResponse
     */
    public function forceDelete(Request $request, User $user)
    {
        $this->forceDeleteAction->run($user, $request->user());

        $user->audit('user.force_deleted', $request->user());

        return redirect()->route('users.index')->with('status', "User '{$user->name}' has been permanently deleted.");
    }

    /**
     * Resend verification email to a user.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return RedirectResponse
     */
    public function resendVerification(Request $request, User $user)
    {
        $result = $this->resendVerificationAction->run($user, $request->ip());

        if (isset($result['error'])) {
            return back()->withErrors(['error' => $result['error']['message']]);
        }

        $user->audit('user.verification_resent', $request->user());

        return back()->with('status', 'Verification email successfully sent to user.');
    }

    /**
     * Request an email change for a user.
     *
     * @param  EmailChangeRequest  $request
     * @param  User  $user
     * @return RedirectResponse
     */
    public function requestEmailChange(EmailChangeRequest $request, User $user)
    {
        $this->requestEmailChangeAction->run($user, $request->validated('email'));

        $user->audit('user.email_change_requested', $request->user(), ['pending_email' => $request->validated('email')]);

        return back()->with('status', 'Verification email sent to new email address.');
    }

    /**
     * Cancel a pending email change.
     *
     * @param  User  $user
     * @return RedirectResponse
     */
    public function cancelEmailChange(Request $request, User $user)
    {
        // Plain Request on purpose: EmailChangeRequest validates an `email`
        // field, which this endpoint does not submit. Same rule as that request
        // — own account, or users.update. This had no guard at all, so any
        // authenticated account could clear another user's pending_email.
        abort_unless(
            $user->is($request->user()) || $request->user()?->can('users.update'),
            403
        );

        $this->cancelEmailChangeAction->run($user);

        $user->audit('user.email_change_cancelled', auth()->user());

        return back()->with('status', 'Email change cancelled.');
    }

    /**
     * Verify and complete an email change.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return RedirectResponse
     */
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

        // Log out the user so they must login with the new email.
        $actor = auth()->user();
        if (auth()->check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $user->audit('user.email_changed', $actor ?? $user, ['new_email' => $user->fresh()->email]);

        return redirect()->route('login')->with('success', 'Email changed successfully. Please login with your new email.');
    }

    /**
     * Redirect after email verification flow.
     *
     * @param  string  $message
     * @param  bool  $success
     * @return RedirectResponse
     */
    protected function verifyRedirect(string $message, bool $success)
    {
        $route = auth()->check() ? 'dashboard' : 'login';
        $key = $success ? 'status' : 'error';

        return redirect()->route($route)->with($key, $message);
    }
}
