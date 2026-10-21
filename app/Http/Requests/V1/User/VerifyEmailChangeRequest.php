<?php

namespace App\Http\Requests\V1\User;

use App\Http\Requests\BaseFormRequest;

/**
 * The `?token=` carried by a signed email-change link, on either channel.
 *
 * `nullable` on purpose: a link missing its token is answered 400
 * `INVALID_TOKEN` by the controller, which is the documented refusal, not a 422.
 * The signature itself is checked by the route's `signed` middleware before this
 * class runs; the token is what proves the link belongs to this pending change.
 */
class VerifyEmailChangeRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['nullable', 'string', 'max:255'],
        ];
    }
}
