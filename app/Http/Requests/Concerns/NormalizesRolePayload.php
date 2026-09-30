<?php

namespace App\Http\Requests\Concerns;

/**
 * Normalises a `roles[]` array that arrived from a checkbox group.
 *
 * A checkbox group has one failure mode that no other input has: an UNCHECKED
 * box is not sent at all. Untick every box and the browser posts no `roles` key,
 * which is indistinguishable from a caller that never had roles in its payload
 * — so `UserUpdateAction`'s `array_key_exists('roles', ...)` reads "removed
 * them all" as "leave the roles alone" and the removal silently does nothing.
 *
 * The view sends a hidden `roles[]` with an empty value to close that hole, and
 * THIS strips the empty value back out before validation. Both halves are
 * needed: without the hidden input the key never arrives, and without this
 * strip `roles.* => exists:roles,name` rejects the empty string we added on
 * purpose.
 *
 * Shared because CreateUserRequest and UpdateUserRequest have the identical
 * `roles` rule, and a fix applied to one of them is a fix the other will lose.
 */
trait NormalizesRolePayload
{
    /**
     * Drop the placeholder empty value, keeping the key itself.
     *
     * The key MUST survive: its presence is the signal that means "this is a
     * deliberate role edit". `['roles' => []]` clears the roles;
     * no `roles` key at all leaves them alone. Collapsing the key away here
     * would re-break the exact case this trait exists to fix.
     *
     * Read through the source bag rather than redeclaring has()/input(): those
     * live on Symfony's ParameterBag, not on Request, so a trait that declares
     * them is a signature clash waiting to happen — and one that does not is
     * calling a method it does not own. `array_key_exists` on the raw input
     * array is the same question `has()` answers, with no signature to match.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        if (! array_key_exists('roles', $input)) {
            return;
        }

        $roles = $input['roles'];

        if (! is_array($roles)) {
            return;
        }

        $this->merge(['roles' => array_values(array_filter(
            $roles,
            static fn ($role): bool => is_string($role) && trim($role) !== ''
        ))]);
    }
}
