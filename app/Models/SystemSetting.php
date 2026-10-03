<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Fillable(['key', 'value'])]
/**
 * System settings key-value store with typed getters.
 *
 * Settings are cached per-request (static) and persisted via Laravel Cache
 * (rememberForever). Cache is busted on write via set().
 */
class SystemSetting extends Model
{
    use Auditable;

    /** @var array<string,string>|null Request-level cache to eliminate N+1. */
    protected static ?array $requestCache = null;

    /** Cache TTL key name. */
    protected static string $cacheKey = 'app_system_settings';

    /**
     * Load all settings once per request.
     *
     * ## Not cached while a transaction is open
     *
     * Reading inside a transaction can see values that transaction has not
     * committed yet. Populating the shared cache from there writes the
     * uncommitted value somewhere every other process will read it, and a
     * rollback cannot take it back — `set()` busting after commit does not help,
     * because the offending write happened on the READ path, not the write one.
     * Measured: a rollback left the cache holding a password policy of 20 while
     * the row said 12.
     *
     * So inside a transaction this reads the rows and fills only the
     * per-request static, which dies with the request. Outside one — the
     * overwhelmingly common case — the persistent cache is used as before.
     *
     * @return array<string,string>
     */
    protected static function loadSettings(): array
    {
        if (static::$requestCache !== null) {
            return static::$requestCache;
        }

        if (DB::transactionLevel() > 0) {
            return static::$requestCache = static::query()->pluck('value', 'key')->all();
        }

        static::$requestCache = Cache::rememberForever(static::$cacheKey, function () {
            return static::query()->pluck('value', 'key')->all();
        });

        return static::$requestCache;
    }

    /**
     * Get a boolean setting value.
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $settings = static::loadSettings();
        if (!array_key_exists($key, $settings)) {
            return $default;
        }
        return filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get an integer setting value.
     *
     * @param string $key
     * @param int $default
     * @return int
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $settings = static::loadSettings();
        if (!array_key_exists($key, $settings)) {
            return $default;
        }
        return (int) $settings[$key];
    }

    /**
     * Get a string setting value.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    public static function getString(string $key, string $default = ''): string
    {
        $settings = static::loadSettings();
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /**
     * Get all settings as key-value pairs.
     *
     * @return array<string, string>
     */
    public static function getAll(): array
    {
        return static::loadSettings();
    }

    /**
     * Drop only the request-level static, leaving the shared cache alone.
     *
     * The write path needs this the moment a row changes: the rest of THIS
     * request must not read a value the transaction may yet discard. The
     * persistent cache has to wait for commit — see `set()` and
     * `SystemSettingObserver` for why.
     */
    public static function clearRequestCache(): void
    {
        static::$requestCache = null;
    }

    /**
     * Bust both request-level and persistent caches.
     */
    public static function bustCache(): void
    {
        static::clearRequestCache();
        Cache::forget(static::$cacheKey);
    }

    /**
     * Set a setting value (upsert).
     *
     * ## Why the cache is NOT busted here
     *
     * Busting on write looks right and is wrong. `set()` runs inside a
     * transaction that may still roll back, and the two ways that goes wrong are
     * both silent:
     *
     * - A read during the transaction window repopulates the cache from inside
     *   the transaction, so it caches the value that was about to be discarded.
     *   After the rollback the cache serves it forever: measured here, the cache
     *   held `20` while the row said `12`. The next request reads a policy that
     *   was never committed.
     * - With no read in the window the cache is simply empty afterwards, and the
     *   next request repopulates it correctly — so the bug looks intermittent,
     *   which is worse than a consistent one.
     *
     * Deferring to `DB::afterCommit` closes both: a committed save busts the
     * cache, a rolled-back one never wrote anything worth forgetting. The
     * request-level static is cleared eagerly because it is per-process and
     * would otherwise serve uncommitted values to the rest of THIS request,
     * which is the same defect one step sooner.
     *
     * @param string $key
     * @param string $value
     * @return self
     */
    public static function set(string $key, string $value): self
    {
        static::clearRequestCache();

        DB::afterCommit(static function (): void {
            static::bustCache();
        });

        return static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
