<?php

namespace App\Support;

/**
 * The role names the application itself depends on.
 *
 * `superadmin`, `admin`, and `user` are not ordinary rows: RoleSeeder creates
 * them, the registration default points at `user`, and authorization docs treat
 * them as protected. That makes "is this a system role?" a question several
 * layers need to answer identically — a literal array in each of them is how
 * the list silently grows a fourth name in one place and not the others.
 *
 * ponytail: a constant list, not a roles-table column. A fourth system role is
 * one line here. Revisit only if roles ever need to be system-marked by data
 * rather than by name.
 */
class SystemRole
{
    public const SUPERADMIN = 'superadmin';

    public const ADMIN = 'admin';

    public const USER = 'user';

    /**
     * Every system role name.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [self::SUPERADMIN, self::ADMIN, self::USER];
    }

    /**
     * Whether a role name is one the application depends on.
     */
    public static function isSystem(string $name): bool
    {
        return in_array($name, self::names(), true);
    }
}
