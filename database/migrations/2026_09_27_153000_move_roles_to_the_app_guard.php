<?php

use App\Models\RoleLookup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move role rows and role assignments onto the guard the app authenticates with.
 *
 * RoleSeeder wrote `guard_name = 'api'` while config('auth.defaults.guard') is
 * `web`, and Spatie keys a role by (name, guard_name). So the seeded roles were
 * rows no permission check ever read, `Role::all()` returned each name twice,
 * and the role pickers rendered `superadmin` and `admin` as two checkboxes
 * each. `UserCreateAction` then resolved `where('name', ...)->first()`, which
 * returned the unreadable row and left new users with a role that granted
 * nothing.
 *
 * The role rows are moved rather than deleted, and assignments are repointed
 * to the new ids, so nobody loses the role they already had.
 */
return new class () extends Migration {
    /**
     * Repoint every role onto the app guard, keeping assignments intact.
     */
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        // Same resolver the app uses, not config('auth.defaults.guard'): Spatie
        // intersects that default with the guards a User can authenticate
        // under, so the two can disagree.
        $guard = RoleLookup::guard();

        // name => the id it now lives under on the app guard.
        $moved = [];

        foreach (DB::table('roles')->where('guard_name', '!=', $guard)->get() as $role) {
            $target = DB::table('roles')
                ->where('name', $role->name)
                ->where('guard_name', $guard)
                ->value('id');

            if ($target === null) {
                DB::table('roles')->where('id', $role->id)->update(['guard_name' => $guard]);
                $moved[$role->id] = $role->id;

                continue;
            }

            // Both guards hold this name. Carry the assignments over, then drop
            // the duplicate. insertOrIgnore keeps a user who somehow holds both
            // from failing the unique index.
            if (Schema::hasTable('model_has_roles')) {
                $assignments = DB::table('model_has_roles')->where('role_id', $role->id)->get();

                foreach ($assignments as $assignment) {
                    $exists = DB::table('model_has_roles')
                        ->where('role_id', $target)
                        ->where('model_id', $assignment->model_id)
                        ->where('model_type', $assignment->model_type)
                        ->exists();

                    if ($exists) {
                        DB::table('model_has_roles')->where('id', $assignment->id)->delete();

                        continue;
                    }

                    DB::table('model_has_roles')->where('id', $assignment->id)->update(['role_id' => $target]);
                }
            }

            $moved[$role->id] = $target;

            DB::table('roles')->where('id', $role->id)->delete();
        }

        if (Schema::hasTable('cache')) {
            // Spatie caches the permission map per request and in the cache
            // store; a stale entry keeps the old role ids alive.
            DB::table('cache')->where('key', 'like', '%spatie.permission%')->delete();
        }
    }

    /**
     * Not reversible: the original guard of each role is not recorded, and
     * moving a role back would be guesswork.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
