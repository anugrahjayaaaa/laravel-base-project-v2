<?php

use App\Support\MorphMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rewrite every polymorphic type column in the application from FQCNs to morph
 * aliases.
 *
 * Phase 10, P10-D5.
 *
 * ## Why this touches more than `activity_log`
 *
 * `Relation::morphMap()` is global. Registering one in a service provider changes
 * what `Model::getMorphClass()` returns for EVERY polymorphic relation in the
 * application, not just the one it was added for — and this project has four
 * more that are not its own:
 *
 * | Table | Column | Owner |
 * |---|---|---|
 * | `model_has_roles` | `model_type` | spatie/laravel-permission |
 * | `model_has_permissions` | `model_type` | spatie/laravel-permission |
 * | `personal_access_tokens` | `tokenable_type` | laravel/sanctum |
 * | `notifications` | `notifiable_type` | laravel/notifications |
 * | `activity_log` | `subject_type` / `causer_type` | spatie/laravel-activitylog |
 *
 * The failure mode is silent and severe. Reading falls back
 * (`getActualClassNameForMorph()` returns the stored value when it is not a map
 * key), but a QUERY filters on the new alias:
 *
 *     stored: App\Models\User      getMorphAlias(User): "user"
 *     whereHas('roles')->exists(): false      $user->roles->count(): 0
 *
 * Every pre-existing role assignment, API token and notification becomes
 * invisible — which locks people out and invalidates sessions without a single
 * error being raised. Backfilling only `activity_log` would have shipped exactly
 * that.
 *
 * ## Why reads do not break if this is late
 *
 * `Model::getActualClassNameForMorph()` is `Arr::get(morphMap(), $type, $type)`,
 * keyed alias => class, so an FQCN row falls back to itself and still resolves.
 * This migration is about not writing FQCNs forever, not about rescuing rows.
 *
 * ## Idempotent
 *
 * Each statement targets one exact FQCN, so a second run matches nothing. That
 * matters during a rolling deploy, where older code is still writing FQCNs while
 * this runs.
 *
 * ## Tables are checked, not assumed
 *
 * A derived project's database may not have every one of these tables — the
 * permission and sanctum tables arrive with their packages' migrations, and a
 * project that trims a package would not have them. `Schema::hasTable()` guards
 * each, so this migration is safe to run on a partial schema rather than fataling
 * on the first missing one and leaving the rest undone.
 */
return new class extends Migration
{
    /**
     * table => the type columns on it.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMNS = [
        'activity_log' => ['subject_type', 'causer_type'],
        'model_has_roles' => ['model_type'],
        'model_has_permissions' => ['model_type'],
        'personal_access_tokens' => ['tokenable_type'],
        'notifications' => ['notifiable_type'],
    ];

    public function up(): void
    {
        $this->rewrite(MorphMap::classToAlias());
    }

    public function down(): void
    {
        $this->rewrite(MorphMap::aliasToClass());
    }

    /**
     * @param  array<string, string>  $map  either class => alias, or alias => class
     */
    private function rewrite(array $map): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = Schema::getColumnListing($table);

            foreach ($columns as $column) {
                if (! in_array($column, $existing, true)) {
                    continue;
                }

                foreach ($map as $from => $to) {
                    DB::table($table)->where($column, $from)->update([$column => $to]);
                }
            }
        }
    }
};
