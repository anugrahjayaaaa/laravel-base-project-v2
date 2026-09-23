<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Traits\FormatsApiErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared "forgot password" validation for Web + API.
 */
class PasswordForgotRequest extends FormRequest
{
    use FormatsApiErrors;

    /**
     * Guest route — always authorized.
     */
    public function authorize(): bool
    {
        return true; // Guest route.
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    /**
     * Get the email address for the password reset link.
     *
     * @return string
     */
    public function email(): string
    {
        return $this->input('email', '');
    }
}
