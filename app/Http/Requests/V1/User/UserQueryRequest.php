<?php

namespace App\Http\Requests\V1\User;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates user list query parameters.
 */
class UserQueryRequest extends BaseFormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive,locked,pending_verification,trashed'],
            'sort' => ['nullable', 'in:created_at,name,email,is_active'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
