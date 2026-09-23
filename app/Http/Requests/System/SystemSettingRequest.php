<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

class SystemSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allow_username_change' => ['boolean'],
            'allow_email_change' => ['boolean'],
            'username_change_cooldown_days' => ['integer', 'min:1', 'max:365'],
            'email_change_cooldown_days' => ['integer', 'min:1', 'max:365'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'allow_username_change' => $this->boolean('allow_username_change'),
            'allow_email_change' => $this->boolean('allow_email_change'),
        ]);
    }
}