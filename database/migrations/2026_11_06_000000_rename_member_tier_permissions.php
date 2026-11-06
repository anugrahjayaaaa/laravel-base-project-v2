<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The eight tier permissions become `member_tiers.*` (Phase 18, P18-E2).
 *
 * `PermissionCatalogTest` gates every permission name on `{resource}.{action}`,
 * and `members.tiers.view` has two dots: it is the resource `members.tiers` with
 * an action, which no other module does. Renaming the slugs pays the convention
 * down instead of exempting the only file that would ever check it.
 *
 * Route names are untouched (`members.tiers.*` is a URL, not an ability), and
 * `role_has_permissions` is keyed by id, so no grant is lost. The reader must
 * still clear Spatie's permission cache after this runs — the registrar holds
 * the old names for the life of the process.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::table('permissions')
            ->where('name', 'like', 'members.tiers.%')
            ->update(['name' => DB::raw("REPLACE(name, 'members.tiers.', 'member_tiers.')")]);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'like', 'member_tiers.%')
            ->update(['name' => DB::raw("REPLACE(name, 'member_tiers.', 'members.tiers.')")]);
    }
};
