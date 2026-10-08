<?php

namespace App\Support;

use App\Models\RoleLookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NotificationAudience
{
    public const ADMINISTRATIVE_EVENTS = [
        'user.registered' => 'users.create',
        'user.updated' => 'users.update',
        'user.locked' => 'users.lock',
        'user.unlocked' => 'users.unlock',
        'user.deactivated' => 'users.deactivate',
        'user.activated' => 'users.activate',
        'user.deleted' => 'users.delete',
        'role.changed' => 'roles.update',
        'role.deleted' => 'roles.delete',
        'permission.changed' => 'roles.assign_permissions',
        'feature.changed' => 'features.manage',
        'setting.changed' => 'settings.manage',
        'mail_setting.changed' => 'notifications.manage',
        'channel.changed' => 'notifications.manage',
    ];

    public static function administratorsFor(string $event): Collection
    {
        $permission = self::ADMINISTRATIVE_EVENTS[$event] ?? null;

        if ($permission === null) {
            return collect();
        }

        // Through a ROLE or DIRECTLY on the person. Spatie grants permissions both
        // ways, `User::can()` honours both, and a resolver that only read one of
        // them disagreed with the gate it exists to serve: a holder assigned a
        // permission directly passed `can('users.lock')` and was resolved to
        // nobody, so the one operator qualified to undo an action was never told
        // it happened. Nothing in the application does this today — Phase 6
        // grants through roles — but "holds the permission" has to mean what the
        // Gate says it means, or the two answers drift the first time one code
        // path does.
        $holders = User::query()
            ->where(function (Builder $q) use ($permission): void {
                $q->whereHas(
                    'roles.permissions',
                    fn (Builder $roles) => $roles
                    ->where('name', $permission)
                    ->where('guard_name', RoleLookup::guard())
                )
                    ->orWhereHas(
                        'permissions',
                        fn (Builder $direct) => $direct
                        ->where('name', $permission)
                        ->where('guard_name', RoleLookup::guard())
                    );
            })
            ->get();

        return $holders
            ->merge(User::query()->whereHas(
                'roles',
                fn (Builder $q) => $q
                ->where('name', SystemRole::SUPERADMIN)
                ->where('guard_name', RoleLookup::guard())
            )->get())
            ->unique('id')
            ->values();
    }

    public static function forEvent(string $event, ?User $subject = null): Collection
    {
        if (array_key_exists($event, self::ADMINISTRATIVE_EVENTS)) {
            return self::administratorsFor($event);
        }

        return $subject !== null ? collect([$subject]) : collect();
    }
}
