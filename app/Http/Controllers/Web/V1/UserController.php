<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\User\AdminResendVerificationAction;
use App\Actions\User\DeleteUserAction;
use App\Actions\User\ForceDeleteUserAction;
use App\Actions\User\RestoreUserAction;
use App\Actions\User\ShowUserAction;
use App\Actions\User\UpdateUserAction;
use App\Actions\User\UserIndexAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\DeleteUserRequest;
use App\Http\Requests\User\ForceDeleteUserRequest;
use App\Http\Requests\User\ResendVerificationRequest;
use App\Http\Requests\User\RestoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UserQueryRequest;
use App\Models\User;

class UserController extends Controller
{
    public function __construct(
        private readonly UserIndexAction $indexAction,
        private readonly UpdateUserAction $updateAction,
        private readonly DeleteUserAction $deleteAction,
        private readonly RestoreUserAction $restoreAction,
        private readonly ForceDeleteUserAction $forceDeleteAction,
        private readonly ShowUserAction $showAction,
        private readonly AdminResendVerificationAction $resendVerificationAction,
    ) {}

    public function index(UserQueryRequest $request)
    {
        $users = $this->indexAction->run(
            search: $request->validated('search'),
            status: $request->validated('status'),
            sort: $request->validated('sort', 'created_at'),
            direction: $request->validated('direction', 'desc'),
            perPage: $request->validated('per_page', 10),
        );

        return view('pages.users.index', [
            'title' => 'Users',
            'users' => $users,
            'filters' => $request->only('search', 'status', 'sort', 'direction'),
        ]);
    }

    public function show(ShowUserAction $action, int $id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $action->run($user);

        return view('pages.users.edit', ['title' => 'User Detail', 'user' => $user]);
    }

    public function edit(int $id)
    {
        $user = User::withTrashed()->findOrFail($id);

        return view('pages.users.edit', ['title' => 'Edit User', 'user' => $user]);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->updateAction->run($user, $request->validated());

        return back()->with('status', 'User updated successfully.');
    }

    public function destroy(DeleteUserRequest $request, User $user)
    {
        $this->deleteAction->run($user, $request->user());

        return back()->with('status', 'User deleted successfully.');
    }

    public function restore(RestoreUserRequest $request, int $id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $this->restoreAction->run($user);

        return back()->with('status', 'User restored successfully.');
    }

    public function forceDelete(ForceDeleteUserRequest $request, int $id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $this->forceDeleteAction->run($user, $request->user());

        return redirect()->route('users.index')->with('status', 'User permanently deleted.');
    }

    public function resendVerification(ResendVerificationRequest $request, User $user)
    {
        $result = $this->resendVerificationAction->run($user, $request->ip());

        if (isset($result['error'])) {
            return back()->withErrors(['error' => $result['error']['message']]);
        }

        return back()->with('status', 'Verification email successfully sent to user.');
    }
}