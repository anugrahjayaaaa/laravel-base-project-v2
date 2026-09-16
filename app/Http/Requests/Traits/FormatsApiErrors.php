<?php

namespace App\Http\Requests\Traits;

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
    protected function failedValidation(Validator $validator): void
    {
        if (request()->expectsJson() || request()->is('api/*')) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors(),
                    'code' => 'VALIDATION_ERROR',
                    'meta' => [
                        'request_id' => app('request_id'),
                        'timestamp' => now()->toIso8601String(),
                    ],
                ], 422)
            );
        }

        // Web: redirect back with validation errors and old input.
        throw new HttpResponseException(
            back()->withErrors($validator)->withInput()
        );
    }
}
