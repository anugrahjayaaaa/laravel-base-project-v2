<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * InactivityLock — pure logic for inactivity-based account locking.
 *
 * Reads settings from SystemSetting (admin-editable via /settings).
 * Locking sets is_locked = true and revokes all sessions/tokens.
 */
class InactivityLock
{
    /**
     * Determine if the user has been inactive beyond the configured threshold.
     */
    public static function isInactive(User $user): bool
    {
        if (! SystemSetting::getBool('inactivity_lock_enabled', true)) {
            return false;
        }

        $lockDays = SystemSetting::getInt('inactivity_lock_days', 30);
        $reference = $user->last_activity_at;

        if (! $reference) {
            $reference = $user->created_at;
            $graceDays = SystemSetting::getBool('inactivity_lock_grace_enabled', true)
                ? SystemSetting::getInt('inactivity_lock_grace_days', 30)
                : 0;

            return $reference
                ? $reference->copy()->addDays($lockDays + $graceDays)->isPast()
                : false;
        }

        return $reference->copy()->addDays($lockDays)->isPast();
    }

    /**
     * Determine if the user should be locked (inactive + not already locked).
     */
    public static function shouldLock(User $user): bool
    {
        return self::isInactive($user) && ! $user->is_locked;
    }

    /**
     * Lock the user account and revoke all sessions/tokens.
     *
     * The audit row is written HERE, inside the transaction, rather than by the
     * caller. This service is the single writer of an inactivity lock and it has
     * two callers — the request middleware and the sweep job — which each audited
     * it on the line after this returned, outside this transaction. So a failure
     * part way through left a user whose sessions were revoked but who stayed
     * unlocked, or an audit row describing a lock that rolled back; the row could
     * outlive the state change it claimed.
     *
     * The event and properties are the caller's because the two are genuinely
     * different facts: the middleware locks someone mid-request, the sweep locks
     * a backlog on a timer.
     *
     * @param  string  $event      Audit event name for this caller
     * @param  array<string, mixed>  $properties  Event-specific properties
     */
    public static function lock(User $user, string $event = 'auth.inactivity_lock', array $properties = []): void
    {
        DB::transaction(function () use ($user, $event, $properties): void {
            $user->update(['is_locked' => true]);

            // Revoke all active web sessions
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->delete();

            // Revoke all Sanctum API tokens
            $user->tokens()->delete();

            // Inside the transaction, so a rollback takes the record with the lock.
            // `causer`/`source` come from the caller because the two callers are
            // not the same kind of event: see AuditsSystemActivity for the sweep's
            // shape and EnsurePasswordChangeRequired for the middleware's.
            $user->audit($event, null, array_merge([
                'last_activity_at' => $user->last_activity_at?->toIso8601String(),
            ], $properties));
        });
    }
}
