<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Drop the two expiry keys no code ever read.
     *
     * Each flow has one enforced lifetime — `password_reset_expire_minutes`
     * (`auth.passwords.users.expire`) and `email_verification_expire_minutes`
     * (`auth.verification.expire`). These rows were a second control for the
     * same value that no consumer consulted, so an admin could change them,
     * get a 200, and watch the expiry not move.
     *
     * The cache is busted because this writes through `DB`, which fires no
     * model observer and would otherwise keep serving the deleted keys.
     */
    private const KEYS = [
        'password_reset_token_expire_minutes',
        'email_verification_token_expire_minutes',
    ];

    public function up(): void
    {
        DB::table('system_settings')->whereIn('key', self::KEYS)->delete();

        Cache::forget('app_system_settings');
    }

    /**
     * Restore the rows the previous release seeded, so a rollback returns the
     * install to the state that code expected. They remain unread either way.
     */
    public function down(): void
    {
        foreach ([
            'password_reset_token_expire_minutes' => '15',
            'email_verification_token_expire_minutes' => '60',
        ] as $key => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value],
            );
        }

        Cache::forget('app_system_settings');
    }
};
