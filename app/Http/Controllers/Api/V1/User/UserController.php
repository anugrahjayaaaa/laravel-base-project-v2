<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Actions\V1\User\UserAdminResendVerificationAction;
use App\Actions\V1\BulkAction\BulkActionProcessor;
use App\Actions\V1\User\UserBulkActionHandler;
use App\Actions\V1\User\UserCancelEmailChangeAction;
use App\Actions\V1\User\UserCreateAction;
use App\Actions\V1\User\UserDeleteAction;
use App\Actions\V1\User\UserForceDeleteAction;
use App\Actions\V1\User\UserRequestEmailChangeAction;
use App\Actions\V1\User\UserRestoreAction;
use App\Actions\V1\User\UserUpdateAction;
use App\Actions\V1\User\UserIndexAction;
use App\Actions\V1\User\UserVerifyEmailChangeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\User\BulkUserRequest;
use App\Http\Requests\V1\User\CreateUserRequest;
use App\Http\Requests\V1\User\EmailChangeRequest;
use App\Http\Requests\V1\User\UpdateUserRequest;
use App\Http\Requests\V1\User\UserQueryRequest;
use App\Http\Resources\Api\V1\User\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\User;

/**
 * API user controller, CRUD, bulk actions, email verification.
 */
class UserController extends Controller
{
    /**
     * @param  UserCreateAction  $createAction
     * @param  UserIndexAction  $indexAction
     * @param  UserUpdateAction  $updateAction
     * @param  UserDeleteAction  $deleteAction
     * @param  UserRestoreAction  $restoreAction
     * @param  UserForceDeleteAction  $forceDeleteAction
     * @param  BulkActionProcessor  $processor
     * @param  UserBulkActionHandler  $userBulkActionHandler
     * @param  UserRequestEmailChangeAction  $requestEmailChangeAction
     * @param  UserCancelEmailChangeAction  $cancelEmailChangeAction
     * @param  UserVerifyEmailChangeAction  $verifyEmailChangeAction
     * @param  UserAdminResendVerificationAction  $resendVerificationAction
     */
    public function __construct(
        private readonly UserCreateAction $createAction,
        private readonly UserIndexAction $indexAction,
        private readonly UserUpdateAction $updateAction,
        private readonly UserDeleteAction $deleteAction,
        private readonly UserRestoreAction $restoreAction,
        private readonly UserForceDeleteAction $forceDeleteAction,
        private readonly BulkActionProcessor $processor,
        private readonly UserBulkActionHandler $userBulkActionHandler,
        private readonly UserRequestEmailChangeAction $requestEmailChangeAction,
        private readonly UserCancelEmailChangeAction $cancelEmailChangeAction,
        private readonly UserVerifyEmailChangeAction $verifyEmailChangeAction,
        private readonly UserAdminResendVerificationAction $resendVerificationAction,
    ) {
    }

    /**
     * List users with search, filtering, and pagination.
     *
     * @param  UserQueryRequest  $request
     * @return JsonResponse
     */
    public function index(UserQueryRequest $request): JsonResponse
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

        return $this->respond('', 200, [
            'users' => UserResource::collection($users),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Create a new user.
     *
     * @param  CreateUserRequest  $request
     * @return JsonResponse
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = $this->createAction->run($request->validated(), causer: $request->user());

        $user->audit('user.created', $request->user());

        return response()->json([
            'data' => ['message' => 'User created successfully.'],
            'meta' => [
                'request_id' => app('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Get a single user by ID.
     *
     * @param  User  $user
     * @return JsonResponse
     */
    public function show(User $user): JsonResponse
    {
        return $this->respond('', 200, [
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Update an existing user.
     *
     * @param  UpdateUserRequest  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->updateAction->run($user, $request->validated(), $request->user());

        $user->audit('user.updated', $request->user());

        return $this->respond('User updated successfully.', 200, [
            'message' => 'User updated successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Execute bulk user actions (delete, restore, lock, unlock, activate, deactivate).
     *
     * @param  BulkUserRequest  $request
     * @return JsonResponse
     */
    public function bulkAction(BulkUserRequest $request): JsonResponse
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

        return $this->respond("{$result['label']} ({$result['count']} users).", 200);
    }

    /**
     * Soft-delete a user (moves to trash).
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->deleteAction->run($user, $request->user());

        $user->audit('user.deleted', $request->user());

        return $this->respond('User deleted successfully.', 200);
    }

    /**
     * Permanently delete a user from database.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function forceDelete(Request $request, User $user): JsonResponse
    {
        $this->forceDeleteAction->run($user, $request->user());

        $user->audit('user.force_deleted', $request->user());

        return $this->respond('User permanently deleted.', 200);
    }

    /**
     * Restore a trashed user.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function restore(Request $request, User $user): JsonResponse
    {
        $this->restoreAction->run($user);

        $user->audit('user.restored', $request->user());

        return $this->respond('User restored successfully.', 200, [
            'message' => 'User restored successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Request an email change for a user.
     *
     * @param  EmailChangeRequest  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function requestEmailChange(EmailChangeRequest $request, User $user): JsonResponse
    {
        $this->requestEmailChangeAction->run($user, $request->validated('email'));

        $user->audit('user.email_change_requested', $request->user(), ['pending_email' => $request->validated('email')]);

        return $this->respond('Verification email sent to new email address.', 200);
    }

    /**
     * Cancel a pending email change.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function cancelEmailChange(Request $request, User $user): JsonResponse
    {
        // Same rule as the web controller: own account, or users.update. Same
        // unguarded hole, so it is closed here too rather than only on one layer.
        abort_unless(
            $user->is($request->user()) || $request->user()?->can('users.update'),
            403
        );

        $this->cancelEmailChangeAction->run($user);

        $user->audit('user.email_change_cancelled', $request->user());

        return $this->respond('Email change cancelled.', 200);
    }

    /**
     * Verify and complete an email change.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function verifyEmailChange(Request $request, User $user): JsonResponse
    {
        $token = $request->route('token');

        if (! $token) {
            return $this->respond('Missing verification token.', 400);
        }

        $verified = $this->verifyEmailChangeAction->run($user, $token);

        if (! $verified) {
            return $this->respond('Invalid or expired verification link.', 400);
        }

        $user->audit('user.email_changed', $request->user(), ['new_email' => $user->fresh()->email]);

        return $this->respond('Email changed successfully. Please login with your new email.', 200);
    }

    /**
     * Resend verification email to a user.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function resendVerification(Request $request, User $user): JsonResponse
    {
        $result = $this->resendVerificationAction->run($user, $request->ip());

        if (isset($result['error'])) {
            // The action already decided the status (403 disabled, 422 already
            // verified, 429 throttled). Hardcoding 400 here threw all of that
            // away and told the client its request was malformed.
            return $this->respond($result['error']['message'], $result['error']['status'] ?? 400);
        }

        $user->audit('user.verification_resent', $request->user());

        return $this->respond('Verification email successfully sent.', 200);
    }
}
