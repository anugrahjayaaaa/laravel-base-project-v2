<?php

namespace App\Observers;

use App\Actions\V1\User\UserIndexAction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Clears user index cache on user lifecycle events.
 */
class UserObserver
{
    /**
     * Clear user index cache after user save.
     */
    public function saved(User $user): void
    {
        foreach (UserIndexAction::cacheKeys() as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Clear user index cache after user delete.
     */
    public function deleted(User $user): void
    {
        foreach (UserIndexAction::cacheKeys() as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Clear user index cache after user restore.
     */
    public function restored(User $user): void
    {
        foreach (UserIndexAction::cacheKeys() as $key) {
            Cache::forget($key);
        }
    }
}
