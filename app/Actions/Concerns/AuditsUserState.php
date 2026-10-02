<?php

namespace App\Actions\Concerns;

use App\Models\User;

/**
 * The audit write shared by the user state actions (activate, deactivate, lock,
 * unlock).
 *
 * These four actions differ only in which column they write and which
 * precondition they refuse on. The audit row does not differ at all: same
 * event shape, same two properties. Copying a five-line block into four classes
 * is how one of them ends up with `target_email` missing and nobody notices,
 * because an audit gap is invisible until an incident needs it.
 */
trait AuditsUserState
{
    /**
     * Record a user state change, attributed to the actor who caused it.
     *
     * `target_id` and `target_email` are redundant with the subject — that is
     * the point. An auditor reading the activity list filters on properties, and
     * a state change row that only carries a subject id cannot be searched by
     * the address it affected.
     *
     * @param  User       $user
     * @param  string     $event
     * @param  User|null  $causer
     */
    protected function auditState(User $user, string $event, ?User $causer): void
    {
        $user->audit($event, $causer, [
            'target_id' => $user->id,
            'target_email' => $user->email,
        ]);
    }
}
