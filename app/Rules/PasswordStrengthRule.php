<?php

namespace App\Rules;

use App\Support\PasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Laravel validation rule that enforces the IM8 password policy.
 *
 * Delegates to PasswordPolicy for rule checking and error messages.
 * Pass $username to enable username-rejection check.
 */
class PasswordStrengthRule implements ValidationRule
{
    /**
     * @param  string|null  $username  Username to reject in password (if enabled).
     */
    public function __construct(
        private readonly ?string $username = null,
    ) {}

    /**
     * Validate the attribute value against the password policy.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Closure  $fail  Closure to call with error message(s).
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $errors = PasswordPolicy::validate((string) $value, $this->username);

        foreach ($errors as $error) {
            $fail($error);
        }
    }
}
