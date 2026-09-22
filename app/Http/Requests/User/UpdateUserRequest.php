<?php

namespace App\Http\Requests\User;

use App\Models\SystemSetting;
use App\Enums\UserStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user)],
            'email' => [
                'sometimes', 'email', 'max:255',
                Rule::unique('users')->ignore($user),
            ],
            'status' => ['required', Rule::in(array_column(UserStatusEnum::cases(), 'value'))],
        ];
    }

    public function withValidator($validator): void
    {
        $user = $this->route('user');

        if ($this->has('username') && $this->input('username') !== $user->username) {
            if (SystemSetting::getBool('allow_username_change', true)) {
                if (method_exists($user, 'canChangeUsername') && ! $user->canChangeUsername()) {
                    $cooldown = (int) (SystemSetting::where('key', 'username_change_cooldown_days')->value('value') ?? 30);

                    $nextDate = $user->username_changed_at?->copy()->addDays($cooldown)->format('Y-m-d');

                    throw ValidationException::withMessages(['username' => "Username changes are currently disabled. Next change allowed on {$nextDate}."]);
                }
            } else {
                throw ValidationException::withMessages(['username' => 'Username changes are currently disabled.']);
            }
        }

        if ($this->has('email') && $this->input('email') !== $user->email) {
            if (SystemSetting::getBool('allow_email_change', true)) {
                if (method_exists($user, 'canChangeEmail') && ! $user->canChangeEmail()) {
                    $cooldown = (int) (SystemSetting::where('key', 'email_change_cooldown_days')->value('value') ?? 30);

                    $nextDate = $user->email_changed_at?->copy()->addDays($cooldown)->format('Y-m-d');
                    
                    throw ValidationException::withMessages(['email' => "Email changes are currently disabled. Next change allowed on {$nextDate}."]);
                }
            } else {
                throw ValidationException::withMessages(['email' => 'Email changes are currently disabled.']);
            }
        }
    }
}