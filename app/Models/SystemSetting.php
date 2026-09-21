<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function getBool(string $key, bool $default = false): bool
    {
        $val = static::where('key', $key)->value('value');
        return $val === null ? $default : filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $val = static::where('key', $key)->value('value');
        return $val === null ? $default : (int) $val;
    }

    public static function set(string $key, string $value): self
    {
        return static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}