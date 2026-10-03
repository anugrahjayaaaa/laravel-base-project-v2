<?php

namespace App\Observers;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;

/**
 * Busts the settings cache on any write, not just the ones that go through
 * `SystemSetting::set()`.
 *
 * ## Why this exists
 *
 * `set()` defers its bust to `DB::afterCommit`, which is correct but only
 * covers callers that use it. A write that bypasses it — a query-builder
 * `update()`, a seeder calling `upsert()`, a `DB::table()` patch in a test or
 * a console command — leaves the persistent cache holding the old value
 * forever. Nothing errors. The next request reads a setting that is not what
 * the database says, which for this table means a password policy or an
 * account lockout that an operator believes they just changed.
 *
 * Every shipped write path goes through `set()`, so this is a guard rather than
 * a fix for a live bug. It is here because the failure is silent and the
 * forgetting is one line.
 *
 * ## Why the bust is deferred the same way `set()` defers it
 *
 * Eloquent events fire inside the transaction that caused them. Busting here
 * immediately would repopulate-or-be-repopulated from inside an uncommitted
 * transaction, and a rollback cannot take the cached value back — the exact
 * defect `SystemSetting::set()` documents at length. `DB::afterCommit` gives
 * both paths the same guarantee: a committed write busts, a rolled-back one
 * never wrote anything worth forgetting.
 */
class SystemSettingObserver
{
    public function saved(SystemSetting $setting): void
    {
        $this->bustAfterCommit();
    }

    public function deleted(SystemSetting $setting): void
    {
        $this->bustAfterCommit();
    }

    /**
     * Clear the request-level static eagerly, the shared cache after commit.
     *
     * The static dies with the request, so clearing it now is free — and
     * waiting would let the rest of THIS request read a value the transaction
     * is about to discard.
     */
    private function bustAfterCommit(): void
    {
        SystemSetting::clearRequestCache();

        DB::afterCommit(static function (): void {
            SystemSetting::bustCache();
        });
    }
}
