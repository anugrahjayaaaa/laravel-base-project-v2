<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Fold the sweep-only timezone into the global one.
     *
     * The old key described the password sweeps alone; the new one drives the
     * whole process, so an install that chose a sweep timezone keeps that
     * choice instead of silently falling back to UTC.
     *
     * The cache is busted because this writes through `DB`, which fires no
     * model observer — `SystemSetting` caches the map forever and would keep
     * serving the old key.
     */
    public function up(): void
    {
        $legacy = DB::table('system_settings')
            ->where('key', 'password_security_sweep_timezone')
            ->value('value');

        $exists = DB::table('system_settings')->where('key', 'app_timezone')->exists();

        if (! $exists) {
            DB::table('system_settings')->insert([
                'key' => 'app_timezone',
                'value' => is_string($legacy) ? $legacy : '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('system_settings')
            ->where('key', 'password_security_sweep_timezone')
            ->delete();

        Cache::forget('app_system_settings');
    }

    /**
     * Restore the sweep-only key from the global value.
     *
     * `up()` created the `app_timezone` row, so `down()` removes it again —
     * after copying its value into the legacy key, so an operator's choice
     * survives the rollback instead of being silently dropped.
     */
    public function down(): void
    {
        $canonical = DB::table('system_settings')
            ->where('key', 'app_timezone')
            ->value('value');

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'password_security_sweep_timezone'],
            ['value' => is_string($canonical) ? $canonical : ''],
        );

        DB::table('system_settings')->where('key', 'app_timezone')->delete();

        Cache::forget('app_system_settings');
    }
};
