<?php

namespace App\Support;

use Laravel\Pennant\Feature;

/**
 * The feature-flag catalogue — the single source of truth for what this
 * application can switch off, and what each flag is called.
 *
 * Reads `config/pennant.php` rather than holding its own array, because the
 * config file is where the flag lives: the same relationship
 * `PermissionCatalog` has to `PermissionSeeder` and the role form. One list,
 * read by the seeder, the management page, the menu and the tests, so none of
 * them can disagree about which flags exist or what they are called.
 *
 * What this class deliberately does NOT hold is the flag's ON/OFF state.
 * That lives in the Pennant store (`features` table) and is read through
 * `Feature::active()`. Identity in config, state in the store — the same split
 * a permission name and its granted roles have.
 */
class FeatureCatalog
{
    /**
     * Every declared flag slug, in declaration order.
     *
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_keys(self::features());
    }

    /**
     * Every flag as one row: slug, label, group, description.
     *
     * Defaults are filled in so no consumer has to null-check: a flag declared
     * as a bare `'audit' => []` still renders a row rather than an empty cell.
     *
     * @return array<string, array{slug: string, label: string, group: string, description: string}>
     */
    public static function all(): array
    {
        $rows = [];

        foreach (self::features() as $slug => $meta) {
            $rows[$slug] = [
                'slug' => $slug,
                'label' => (string) ($meta['label'] ?? ucfirst(str_replace('_', ' ', $slug))),
                'group' => (string) ($meta['group'] ?? 'General'),
                'description' => (string) ($meta['description'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Every flag grouped by module heading, for the management page's cards.
     *
     * @return array<string, array<int, array{slug: string, label: string, group: string, description: string}>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::all() as $row) {
            $grouped[$row['group']][] = $row;
        }

        return $grouped;
    }

    /**
     * One flag's metadata, or null when the slug is not declared.
     *
     * Null rather than an exception: an undeclared slug is a caller that
     * reached for a flag this application does not have, and the store decides
     * what that means (fail-closed false), not this lookup.
     *
     * @return array{slug: string, label: string, group: string, description: string}|null
     */
    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /**
     * Is this slug one of ours?
     *
     * Used by the toggle action to refuse a slug that is not declared. Pennant
     * will happily store any string, so without this check a crafted POST
     * writes a row nothing reads — a flag that exists in the database and in
     * no gate.
     */
    public static function has(string $slug): bool
    {
        return array_key_exists($slug, self::features());
    }

    /**
     * Is this flag a deploy-free kill switch?
     *
     * `disabled => true` in config means OFF no matter what the store says.
     *
     * It cannot be implemented as the Pennant resolver: measured on the
     * database driver, a stored row WINS over the resolver, so a flag
     * activated once would survive its own kill switch.
     */
    public static function isDisabledInConfig(string $slug): bool
    {
        return (bool) (self::features()[$slug]['disabled'] ?? false);
    }

    /**
     * The flag's effective state — the ONLY place that answer is decided.
     *
     * `Feature::active()` on its own is not the answer, because it cannot
     * honour `disabled => true` (see above). Every reader goes through here
     * instead: the management page, and `EnsureFeatureIsEnabled` at P7-C1.
     * One helper, so a new reader cannot come along and quietly skip the kill
     * switch.
     */
    public static function isActive(string $slug): bool
    {
        if (self::isDisabledInConfig($slug)) {
            return false;
        }

        return Feature::active($slug);
    }

    /**
     * Many flags' effective states in ONE store read.
     *
     * The page asks about every flag, so calling `isActive()` per slug is N
     * selects — measured at 8 queries for 8 flags. Pennant's `values()` reads
     * the same rows in a single `WHERE name IN (...)`, so the count stops
     * depending on the size of the catalogue.
     *
     * The kill switch is applied HERE, per slug, rather than by handing the raw
     * map to the caller: `isActive()` stays the only place that answer is
     * decided, so a new reader cannot come along and skip it. A `disabled =>
     * true` flag reads false without the store ever being asked about it.
     *
     * @param  array<int, string>  $slugs
     * @return array<string, bool> slug => effective state
     */
    public static function activeMap(array $slugs): array
    {
        $states = [];
        $askable = [];

        foreach ($slugs as $slug) {
            if (self::isDisabledInConfig($slug)) {
                // Reported as off without the store ever being asked about it.
                $states[$slug] = false;

                continue;
            }

            $askable[] = $slug;
        }

        $stored = $askable === [] ? [] : Feature::values($askable);

        foreach ($askable as $slug) {
            $states[$slug] = (bool) ($stored[$slug] ?? false);
        }

        return $states;
    }

    /**
     * @return array<string, mixed>
     */
    private static function features(): array
    {
        $features = config('pennant.features', []);

        return is_array($features) ? $features : [];
    }
}
