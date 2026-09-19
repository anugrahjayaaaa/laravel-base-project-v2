<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Traits\FormatsApiErrors;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared unlock-user validation for Web + API.
 *
 * The dedicated permission `users.unlock` is defined in
 * docs/base/features/user-management.md. RBAC permission-to-role
 * assignment is implemented in Phase 6.
 */
class UnlockUserRequest extends FormRequest
{
    use FormatsApiErrors;

    public function authorize(): bool
    {
        $target = $this->route('user');

        if (! $target instanceof User) {
            return false;
        }

        // Works for both web (session) and API (Sanctum) contexts.
        $user = $this->user() ?: auth('sanctum')->user();

        return (bool) $user && $user->can('unlock', $target);
    }

    public function rules(): array
    {
        return [];
    }
}
