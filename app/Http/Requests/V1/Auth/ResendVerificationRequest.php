<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates email for resending verification code.
 */
class ResendVerificationRequest extends BaseFormRequest
{
    /**
     * Guest route,always authorized.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
