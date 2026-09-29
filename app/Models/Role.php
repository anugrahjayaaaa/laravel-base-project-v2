<?php

namespace App\Models;

use App\Support\SystemRole;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Application Role model extending Spatie's base Role.
 *
 * Carries the `is_system` accessor so the controller doesn't have to
 * mutate collection items after loading — and so views get the same
 * answer everywhere SystemRole::isSystem() is needed.
 */
class Role extends SpatieRole
{
    /**
     * Whether this role name is one the application depends on.
     */
    public function getIsSystemAttribute(): bool
    {
        return SystemRole::isSystem($this->name);
    }
}
