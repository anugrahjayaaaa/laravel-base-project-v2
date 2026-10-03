<?php

namespace App\Observers;

use App\Actions\V1\User\UserIndexAction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Clears user index cache on user lifecycle events.
 *
 * ## Why the bust is deferred to `DB::afterCommit`
 *
 * Eloquent events fire INSIDE the transaction that caused them. Forgetting
 * here immediately means the key is gone while the write is still uncommitted,
 * and the next reader — on any process — repopulates it from rows the database
 * has not promised yet. A rollback then cannot take that value back, so the
 * counts keep reporting users that were never written.
 *
 * `DB::afterCommit` closes that window: a committed write busts, a rolled-back
 * one never got as far as needing to. This matches what `SystemSetting` already
 * does, and for the same reason — `SystemSetting::loadSettings()` documents the
 * measured case where a rollback left the cache holding a password policy the
 * row did not have.
 *
 * @see \App\Observers\SystemSettingObserver for the same guard on settings
 */
class UserObserver
{
    /**
     * Clear user index cache after user save.
     */
    public function saved(User $user): void
    {
        $this->bustAfterCommit();
    }

    /**
     * Clear user index cache after user delete.
     */
    public function deleted(User $user): void
    {
        $this->bustAfterCommit();
    }

    /**
     * Clear user index cache after user restore.
     */
    public function restored(User $user): void
    {
        $this->bustAfterCommit();
    }

    /**
     * Forget every user index count key once the write is durable.
     */
    private function bustAfterCommit(): void
    {
        DB::afterCommit(function (): void {
            foreach (UserIndexAction::cacheKeys() as $key) {
                Cache::forget($key);
            }
        });
    }
}
