<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class SystemSetting extends Model
{

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

    public static function getString(string $key, string $default = ''): string
    {
        $val = static::where('key', $key)->value('value');
        return $val === null ? $default : $val;
    }

    public static function set(string $key, string $value): self
    {
        return static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
