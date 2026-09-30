<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\NormalizesRolePayload;
use App\Models\SystemSetting;
use App\Enums\UserStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates admin user update data (status, username, email).
 */
class UpdateUserRequest extends FormRequest
{
    use NormalizesRolePayload;

    /**
     * `users.update`, or a self-profile edit.
     *
     * The self-profile exception is deliberately narrow: the caller must be the
     * target AND must not be sending a `roles` key. Otherwise a user editing
     * their own profile could hand themselves a superadmin role, and the
     * narrower rule says so at the boundary rather than relying on every caller
     * to remember it.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user?->can('users.update') === true) {
            return true;
        }

        $target = $this->route('user');

        return $target !== null
            && (string) $user?->getKey() === (string) $target->getKey()
            && ! $this->has('roles');
    }

    /**
     * Define validation rules with conditional fields based on system settings.
     *
     * @return array
     */
    public function rules(): array
    {
        $user = $this->route('user');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_column(UserStatusEnum::cases(), 'value'))],
            // Same shape as CreateUserRequest: an array of existing role names.
            // The action syncs, so an empty array legitimately clears every role.
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];

        if (SystemSetting::getBool('allow_username_change', true)) {
            $rules['username'] = [
                'sometimes',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('users', 'username')->ignore($user)
            ];
        }

        if (SystemSetting::getBool('allow_email_change', true)) {
            $rules['email'] = [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user)
            ];
        }

        $rules['password'] = ['prohibited'];

        return $rules;
    }

    /**
     * Custom validation: check username/email change cooldowns and permissions.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $user = $this->route('user');

        if ($this->has('username') && $this->input('username') !== $user->username && SystemSetting::getBool('allow_username_change', true)) {
            if (method_exists($user, 'canChangeUsername') && ! $user->canChangeUsername()) {
                $cooldown = (int) (SystemSetting::where('key', 'username_change_cooldown_days')->value('value') ?? 30);

                $nextDate = $user->username_changed_at?->copy()->addDays($cooldown)->format('Y-m-d');

                throw ValidationException::withMessages(['username' => "Username changes are currently disabled. Next change allowed on {$nextDate}."]);
            }
        }

        if ($this->has('email') && $this->input('email') !== $user->email && SystemSetting::getBool('allow_email_change', true)) {
            if (method_exists($user, 'canChangeEmail') && ! $user->canChangeEmail()) {
                $cooldown = (int) (SystemSetting::where('key', 'email_change_cooldown_days')->value('value') ?? 30);

                $nextDate = $user->email_changed_at?->copy()->addDays($cooldown)->format('Y-m-d');

                throw ValidationException::withMessages(['email' => "Email changes are currently disabled. Next change allowed on {$nextDate}."]);
            }
        }
    }
}
