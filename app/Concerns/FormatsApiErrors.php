<?php

namespace App\Concerns;

use App\Support\ApiError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Formats validation failures as the project API error contract.
 *
 * For API requests: returns JSON with { message, errors, code, meta }.
 * For web requests: returns a standard redirect with errors and input.
 *
 * Used by Form Requests shared between Web and API controllers.
 */
trait FormatsApiErrors
{
    /**
     * Handle a failed validation attempt.
     *
     * API requests: throws JSON response with errors, code, meta.
     * Web requests: redirects back with errors and old input.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     */
    protected function failedValidation(Validator $validator): void
    {
        if (request()->expectsJson() || request()->is('api/*')) {
            // Built by the same class the exception handler uses, so a 422 from
            // a form request and one from the handler cannot drift apart.
            throw new HttpResponseException(ApiError::make(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['errors' => $validator->errors()],
            ));
        }

        // Web: redirect back with validation errors and old input.
        throw new HttpResponseException(
            back()->withErrors($validator)->withInput()
        );
    }
}
