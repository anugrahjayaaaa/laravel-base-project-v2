<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
/**
 * System settings key-value store with typed getters.
 */
class SystemSetting extends Model
{

    /**
     * Get a boolean setting value.
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $val = static::where('key', $key)->value('value');
        return $val === null ? $default : filter_var($val, FILTER_VALIDATE_BOOLEAN);
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
        $val = static::where('key', $key)->value('value');
        return $val === null ? $default : (int) $val;
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
        $val = static::where('key', $key)->value('value');
        return $val === null ? $default : $val;
    }

    /**
     * Get all settings as key-value pairs.
     *
     * @return array<string, string>
     */
    public static function getAll(): array
    {
        return static::query()->pluck('value', 'key')->all();
    }

    /**
     * Set a setting value (upsert).
     *
     * @param string $key
     * @param string $value
     * @return self
     */
    public static function set(string $key, string $value): self
    {
        return static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
