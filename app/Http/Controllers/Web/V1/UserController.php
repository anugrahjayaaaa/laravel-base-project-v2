<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\User\AdminResendVerificationAction;
use App\Actions\User\DeleteUserAction;
use App\Actions\User\ForceDeleteUserAction;
use App\Actions\User\RestoreUserAction;
use App\Actions\User\UpdateUserAction;
use App\Actions\User\UserIndexAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UserQueryRequest;
use Illuminate\Http\Request;
use App\Models\User;

class UserController extends Controller
{
    public function __construct(
        private readonly UserIndexAction $indexAction,
        private readonly UpdateUserAction $updateAction,
        private readonly DeleteUserAction $deleteAction,
        private readonly RestoreUserAction $restoreAction,
        private readonly ForceDeleteUserAction $forceDeleteAction,
        private readonly AdminResendVerificationAction $resendVerificationAction,
    ) {}

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

        return view('pages.users.index', [
            'title' => 'Users',
            'users' => $users,
            'filters' => $request->only('search', 'status', 'sort', 'direction'),
            'counts' => $counts,
        ]);
    }

    public function show(int $id)
    {
        $user = User::withTrashed()->findOrFail($id);

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

    public function destroy(Request $request, User $user)
    {
        $this->deleteAction->run($user, $request->user());

        return back()->with('status', 'User deleted successfully.');
    }

    public function restore(int $id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $this->restoreAction->run($user);

        return back()->with('status', 'User restored successfully.');
    }

    public function forceDelete(Request $request, int $id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $this->forceDeleteAction->run($user, $request->user());

        return redirect()->route('users.index')->with('status', 'User permanently deleted.');
    }

    public function resendVerification(Request $request, User $user)
    {
        $result = $this->resendVerificationAction->run($user, $request->ip());

        if (isset($result['error'])) {
            return back()->withErrors(['error' => $result['error']['message']]);
        }

        return back()->with('status', 'Verification email successfully sent to user.');
    }
}
