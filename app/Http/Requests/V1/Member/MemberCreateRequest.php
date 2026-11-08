<?php

namespace App\Http\Requests\V1\Member;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates the Add new member form (Phase 18).
 *
 * The account half is the same contract `CreateUserRequest` enforces — the
 * member IS a user (owner decision 2026-11-05), so the name, username and email
 * rules are copied rather than loosened. There is no member number rule: the
 * number comes from `Member::nextMemberNumber()` inside the create Action, so
 * the form has nothing to validate and a forged `member_number` in the payload
 * is simply not read. `users.email` is still unique, so an email clash says
 * "this account exists" — and it arrives as a field error on the form the
 * operator is filling in, not as a query exception after the account has
 * already been created and mailed.
 */
class MemberCreateRequest extends BaseFormRequest
{
    /**
     * Creating a member is `members.create`, the same gate the route carries.
     *
     * The role the new account receives is not chosen here — it is fixed to
     * `member` by `MemberCreateAction` — but handing it out still goes through
     * `RoleAssignAction`, so that action's own `users.assign_roles` check is what
     * refuses an operator who may edit members but may not grant roles.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('members.create') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
