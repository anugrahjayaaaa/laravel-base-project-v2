<?php

namespace App\Actions\V1\Role;

use App\Actions\Concerns\PersistsRole;
use App\Models\Role;
use App\Models\User;
use App\Support\SystemRole;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Update a role and sync its permission set.
 */
class RoleUpdateAction
{
    use PersistsRole;

    /**
     * Update a role.
     *
     * @param  Role       $role
     * @param  array      $data   Keys: name, permissions (ids)
     * @param  User|null  $causer Who to attribute the audit record to
     * @return Role
     *
     * @throws ValidationException When renaming a system role
     */
    public function run(Role $role, array $data, ?User $causer = null): Role
    {
        $this->guardAgainstSystemRename($role, (string) $data['name']);

        return $this->persist($role, $data, $causer, 'role.updated');
    }

    /**
     * Refuse to rename a role the application depends on.
     *
     * Checked against the STORED name, not the submitted one: the question is
     * whether this row is one the application depends on, which is a property of
     * the role being edited. Testing the incoming name instead would let `admin`
     * be renamed to anything at all.
     */
    private function guardAgainstSystemRename(Role $role, string $newName): void
    {
        if (! SystemRole::isSystem($role->name) || $newName === $role->name) {
            return;
        }

        $validator = Validator::make([], []);
        $validator->errors()->add('name', __('System roles cannot be renamed.'));

        throw new ValidationException($validator);
    }
}
