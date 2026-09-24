<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value'])]
/**
 * System settings key-value store with typed getters.
 *
 * Settings are cached per-request (static) and persisted via Laravel Cache
 * (rememberForever). Cache is busted on write via set().
 */
class SystemSetting extends Model
{
    /** @var array<string,string>|null Request-level cache to eliminate N+1. */
    protected static ?array $requestCache = null;

    /** Cache TTL key name. */
    protected static string $cacheKey = 'app_system_settings';

    /**
     * Load all settings once per request.
     *
     * @return array<string,string>
     */
    protected static function loadSettings(): array
    {
        if (static::$requestCache !== null) {
            return static::$requestCache;
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
     * Bust both request-level and persistent caches.
     */
    public static function bustCache(): void
    {
        static::$requestCache = null;
        Cache::forget(static::$cacheKey);
    }

    /**
     * Set a setting value (upsert).
     * Busts both request-level and persistent cache.
     *
     * @param string $key
     * @param string $value
     * @return self
     */
    public static function set(string $key, string $value): self
    {
        static::$requestCache = null;
        Cache::forget(static::$cacheKey);

        return static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
