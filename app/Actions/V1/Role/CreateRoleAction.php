<?php

namespace App\Actions\V1\Role;

use App\Actions\Concerns\PersistsRole;
use App\Models\Role;
use App\Models\User;

/**
 * Create a role and sync its permission set.
 */
class CreateRoleAction
{
    use PersistsRole;

    /**
     * Create a role.
     *
     * @param  array      $data   Keys: name, permissions (ids)
     * @param  User|null  $causer Who to attribute the audit record to
     * @return Role
     */
    public function run(array $data, ?User $causer = null): Role
    {
        return $this->persist(new Role(), $data, $causer, 'role.created');
    }
}
