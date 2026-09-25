<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Remove the superseded password expiration setting.
     */
    public function up(): void
    {
        $legacy = DB::table('system_settings')
            ->where('key', 'password_expiration_days')
            ->value('value');

        DB::table('system_settings')
            ->where('key', 'password_expiration_days')
            ->delete();

        if ($legacy !== null && ! DB::table('system_settings')->where('key', 'password_expiry_days')->exists()) {
            DB::table('system_settings')->insert([
                'key' => 'password_expiry_days',
                'value' => $legacy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Restore the legacy setting with the canonical value when available.
     */
    public function down(): void
    {
        $canonical = DB::table('system_settings')
            ->where('key', 'password_expiry_days')
            ->value('value');

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'password_expiration_days'],
            ['value' => $canonical ?? '90'],
        );
    }
};
