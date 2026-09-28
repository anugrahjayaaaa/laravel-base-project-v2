<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Traits\FormatsApiErrors;
use App\Rules\PasswordStrengthRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the self-registration form.
 *
 * Shared by the web controller and the API endpoint. Username and email are
 * required because login accepts either one, so an account with neither could
 * never be signed into.
 */
class RegisterRequest extends FormRequest
{
    use FormatsApiErrors;

    /**
     * Guest route, always authorized.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                new PasswordStrengthRule(),
            ],
        ];
    }

    /**
     * The password the user chose, to hand to the create action.
     *
     * @return string
     */
    public function password(): string
    {
        return $this->input('password', '');
    }
}
