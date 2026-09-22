<?php

namespace App\Http\Requests\User;

use App\Enums\UserStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
                'required', 'email', 'max:255',
                Rule::unique('users')->ignore($user),
            ],
            'status' => ['required', Rule::in(array_column(UserStatusEnum::cases(), 'value'))],
        ];
    }
}