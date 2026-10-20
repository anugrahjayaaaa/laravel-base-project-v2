<?php

namespace App\Actions\V1\Feature;

use App\Models\RoleLookup;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Take a switched-off module's permissions off every role that holds them.
 *
 * ## Why this is part of turning a flag OFF, not of the permission UI
 *
 * Hiding the permission from the role matrix only stops it being handed out
 * afterwards. A role that already holds `pulse.view` keeps it, and hiding the
 * checkbox means nobody can see the grant to remove it — so the flag has to be
 * the thing that clears it. Both halves land in one action, inside the toggle's
 * transaction, so a rollback cannot leave a module off with its permissions
 * still distributed.
 *
 * ## Why it does not come back
 *
 * The revoke is final: switching the flag on again does not re-grant anything.
 * Restoring would need the previous holdings stored somewhere, and the result an
 * operator asked for — "this module is off, nobody holds its permissions" —
 * would then silently undo itself. Re-granting is a deliberate act through the
 * role matrix.
 *
 * ## Why the pivot tables are written directly
 *
 * The obvious shape is `$permission->roles()->detach()` per permission, which
 * is one statement per permission per grant path — and the bulk flag action is
 * benchmarked flat in the size of the selection
 * (`FeatureFlagPerformanceTest::a_bulk_toggle_does_not_cost_more_queries_for_a_bigger_selection`),
 * because that is what batching means everywhere else in this feature. Two
 * `DELETE ... WHERE permission_id IN (...)` cover both grant paths for every
 * permission in ONE statement each, whatever the selection.
 *
 * Direct writes also reach two rows the package's role-side API would miss:
 * a soft-deleted role still holds its pivot rows, so a role restored after the
 * module was switched off would come back holding its permissions. Read through
 * Spatie's own config rather than spelled as literals, so a deployment that
 * renamed the tables still gets them right.
 *
 * `config('permission.teams')` is false in this application; with teams on, a
 * delete keyed on `permission_id` alone would reach every team's rows.
 */
class FeatureRevokePermissionsAction
{
    /**
     * Clear one flag's permissions, returning the ones that were actually held.
     *
     * Names returned, not counts: the audit row for the toggle carries them, and
     * "which access did this switch take away" is the question an auditor asks.
     * A permission nobody held is not reported — nothing was taken from anyone.
     *
     * @return array<int, string> the permission names that lost at least one holder
     */
    public function run(string $flag): array
    {
        return $this->runMany([$flag])[$flag] ?? [];
    }

    /**
     * Clear several flags' permissions in one pass.
     *
     * The bulk toggle path asks for every flag it switched off, because each one
     * is a separate line in the audit row and an operator reading it wants to
     * know which module lost what.
     *
     * @param  array<int, string>  $flags
     * @return array<string, array<int, string>> flag => permission names that lost a holder
     */
    public function runMany(array $flags): array
    {
        $namesByFlag = [];

        foreach (array_unique($flags) as $flag) {
            $names = PermissionCatalog::forFeature($flag);

            if ($names !== []) {
                $namesByFlag[$flag] = $names;
            }
        }

        if ($namesByFlag === []) {
            return [];
        }

        $flagByPermission = [];

        foreach ($namesByFlag as $flag => $names) {
            foreach ($names as $name) {
                $flagByPermission[$name] = $flag;
            }
        }

        // Counted first: the counts are what say whether anything was actually
        // taken from anyone, and only held permissions need their pivots
        // touched.
        $held = Permission::query()
            ->where('guard_name', RoleLookup::guard())
            ->whereIn('name', array_keys($flagByPermission))
            ->withCount(['roles', 'users'])
            ->get()
            ->filter(fn (Permission $permission): bool => ($permission->roles_count + $permission->users_count) > 0);

        if ($held->isEmpty()) {
            return [];
        }

        $ids = $held->pluck('id')->all();
        $pivots = config('permission.table_names');

        DB::table($pivots['role_has_permissions'])->whereIn('permission_id', $ids)->delete();
        DB::table($pivots['model_has_permissions'])->whereIn('permission_id', $ids)->delete();

        // Spatie resolves a user's permissions through its own cache, so without
        // this every account keeps its old answer for the rest of the request —
        // including the account that just triggered the toggle, which is the one
        // whose own sidebar is about to be built.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Reported in the order asked for, not in the order the rows came back:
        // this array is serialised into the audit row for a human comparing it
        // against the selection they made. The query has no `order by`, so the
        // database's order is not something to inherit.
        $taken = $held->pluck('name')->all();
        $revoked = [];

        foreach ($namesByFlag as $flag => $names) {
            $names = array_values(array_intersect($names, $taken));

            if ($names !== []) {
                $revoked[$flag] = $names;
            }
        }

        return $revoked;
    }
}
