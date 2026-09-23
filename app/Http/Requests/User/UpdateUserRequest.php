<?php

namespace App\Http\Requests\User;

use App\Models\SystemSetting;
use App\Enums\UserStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates admin user update data (status, username, email, password).
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * Guest route — always authorized.
     */
    public function authorize(): bool
    {
        return true;
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
        ];

        if (SystemSetting::getBool('allow_username_change', true)) {
            $rules['username'] = ['sometimes', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user)];
        }

        if (SystemSetting::getBool('allow_email_change', true)) {
            $rules['email'] = ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user)];
        }

        if ($this->has('password')) {
            $rules['password'] = ['nullable', 'confirmed', 'min:8'];
        }

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