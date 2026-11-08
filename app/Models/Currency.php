<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'symbol', 'decimal_separator', 'thousands_separator'])]
/**
 * Reference currency options for the system settings UI (Phase 18).
 *
 * Seeded from a static list (`CurrencySeeder`), never fetched: the set an
 * operator may pick is small and stable, and a screen that renders a price must
 * not depend on an external service being up.
 *
 * @property string $code
 * @property string $name
 * @property string $symbol
 * @property string $decimal_separator
 * @property string $thousands_separator
 */
class Currency extends Model
{
    /** @var array<string, static>|null Request-level cache, same shape as SystemSetting's. */
    protected static ?array $byCode = null;

    /**
     * Look one currency up by code, without an N+1 in a price column.
     */
    public static function forCode(string $code): ?self
    {
        $byCode = static::byCode();

        // An isset branch, not `[$code] ?? null`: the cached map is typed
        // non-null, so the coalesce reads as an expression that can never return
        // null — while an unseeded code does return null at runtime.
        return isset($byCode[$code]) ? $byCode[$code] : null;
    }

    /**
     * The picker list — code => name, sorted by name. From the same cached read
     * as `forCode()`, so a page that shows the dropdown and prints one price
     * still asks the table once.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $names = array_map(static fn (self $currency): string => $currency->name, static::byCode());
        asort($names);

        return $names;
    }

    /** @return array<string, static> */
    protected static function byCode(): array
    {
        return static::$byCode ??= static::query()->get()->keyBy('code')->all();
    }

    /**
     * Drop the request-level cache — a seeder that rewrites the rows must not
     * leave a reader holding the set as it was before the write.
     */
    public static function bustCache(): void
    {
        static::$byCode = null;
    }
}
