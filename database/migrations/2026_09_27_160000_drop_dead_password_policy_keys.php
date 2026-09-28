<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the three password-policy keys that nothing ever read.
 *
 * The /settings form offered "Mixed Case", "Numbers" and "Symbols", and
 * UpdateSystemSettingsAction wrote password_mixed_case / password_numbers /
 * password_symbols. PasswordPolicy never consulted any of them — it read
 * password_require_upper / _lower / _digit / _symbol, which the form had no
 * control for. So all three switches were inert: an admin could uncheck them,
 * see "Settings updated successfully", and the policy did not change.
 *
 * The form and the action now use the password_require_* keys. These rows are
 * the dead half of that pair and are removed so the next save cannot resurrect
 * them. They are left to the seeder's default of true, which is what the
 * require_* keys already hold.
 */
return new class () extends Migration {
    /**
     * @var array<int, string>
     */
    private array $dead = [
        'password_mixed_case',
        'password_numbers',
        'password_symbols',
    ];

    public function up(): void
    {
        DB::table('system_settings')->whereIn('key', $this->dead)->delete();
    }

    /**
     * Restores each key as 'true'.
     *
     * The previous values are not recoverable — up() deleted the rows, and this
     * migration is not part of a release. 'true' is the seeded default and what
     * the require_* keys already hold, so the effective policy comes back
     * unchanged. Only the dead duplicate keys reappear.
     */
    public function down(): void
    {
        $now = now();

        foreach ($this->dead as $key) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => 'true', 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }
};
