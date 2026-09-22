<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'username' => [
                'sometimes', 'string', 'max:50', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($user),
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users')->ignore($user),
            ],
            'current_password' => ['nullable', 'string'],
            'password' => ['nullable', 'confirmed', 'min:8'],
        ];
    }

    public function withValidator($validator): void
    {
        $user = $this->user();

        if ($this->has('username') && $this->input('username') !== $user->username) {
            if (! $user->canChangeUsername()) {
                throw ValidationException::withMessages([
                    'username' => 'Username changes are currently disabled.',
                ]);
            }
        }

        if ($this->has('email') && $this->input('email') !== $user->email) {
            if (! $user->canChangeEmail()) {
                throw ValidationException::withMessages([
                    'email' => 'Email changes are currently disabled.',
                ]);
            }
        }

        if ($this->filled('current_password')) {
            if (! Hash::check($this->input('current_password'), $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'The current password is incorrect.',
                ]);
            }
        }
    }
}