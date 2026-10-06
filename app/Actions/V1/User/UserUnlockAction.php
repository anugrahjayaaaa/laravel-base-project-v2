<?php

namespace App\Actions\V1\User;

use App\Actions\V1\Notification\NotificationAccountStateAction;
use App\Models\User;
use App\Auth\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Unlock a user account and optionally reset login throttle.
 */
class UserUnlockAction
{
    /**
     * @param  NotificationAccountStateAction  $notifyAction  Notifies both
     *         audiences after the state change commits. Injected rather than
     *         resolved so the action stays testable and the dispatch has one
     *         seam, not two.
     */
    public function __construct(
        private readonly NotificationAccountStateAction $notifyAction,
    ) {
    }

    /**
     * Unlock the user and reset throttle if provided.
     *
     * @param  User           $user
     * @param  User|null      $causer  Who to attribute the audit record to
     * @param  string|null    $ip
     * @param  Request|null   $request
     * @param  LoginThrottle|null $throttle
     * @return array          ['user' => User]
     */
    public function run(
        User $user,
        ?User $causer = null,
        ?string $ip = null,
        ?Request $request = null,
        ?LoginThrottle $throttle = null,
    ): array {
        DB::transaction(function () use ($user, $causer, $ip, $request, $throttle) {
            $user->update(['is_locked' => false]);
            if ($throttle && $ip) {
                $throttle->reset($user->email, $ip);
            }

            // target_id/target_email are redundant with the subject, and
            // deliberately so: the activity list filters on properties, and a
            // state row that only carried a subject id could not be searched by
            // the address it affected.
            $user->audit('user.unlocked', $causer, [
                'target_id' => $user->id,
                'target_email' => $user->email,
            ]);
        });

        // Both audiences, after the transaction: the account is already in its
        // new state, so a notification failure cannot roll back a lock the admin
        // asked for. See NotificationAccountStateAction for why this is a
        // separate step rather than an event listener on the update above.
        $this->notifyAction->run($user, 'user.unlocked', $causer);

        return ['user' => $user];
    }
}
