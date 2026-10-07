<?php

namespace App\Notifications;

use App\Support\NotificationChannel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A user's roles or permissions changed at an administrator's hands.
 *
 * ## Reaches the user whose access changed — and nobody else
 *
 * Not every `roles.update` holder. The people who can edit a role are usually more
 * numerous than the people wearing it, and telling all of them that a colleague
 * gained a permission turns an administrative feed into noise — the same reason
 * an audit log has a viewer rather than pushing itself to everyone.
 *
 * (Owner decision, 2026-10-06.)
 *
 * The one exception is DELETION of the role itself: a role that no longer exists
 * changed nobody's access at that moment, and the accounts that referenced it are
 * left in a state an administrator needs to see. That goes to `roles.delete`
 * holders.
 *
 * ## It carries no credentials and no permission names by default
 *
 * Which roles were added or removed is the useful half, and it is safe: a role
 * NAME tells a user they may now do something, which they can discover anyway. A
 * permission NAME would not — `users.force_delete` in an inbox reads as an
 * invitation. So names are included for roles and omitted for the underlying
 * permissions.
 */
class RolesChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  User                $user     Whose access changed.
     * @param  User|null           $causer   Who changed it, when known.
     * @param  array<int, string>  $added    Role names granted.
     * @param  array<int, string>  $removed  Role names revoked.
     */
    public function __construct(
        private readonly User $user,
        private readonly ?User $causer,
        private readonly array $added = [],
        private readonly array $removed = [],
    ) {
    }

    /**
     * @return array<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannel::for();
    }

    /**
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail(User $notifiable): MailMessage
    {
        $message = new MailMessage;

        $message->subject('Your roles have changed')
            ->line($this->summary())
            ->line('Contact an administrator if you did not expect this.');

        if ($this->added !== []) {
            $message->line('Added: '.implode(', ', $this->added));
        }

        if ($this->removed !== []) {
            $message->line('Removed: '.implode(', ', $this->removed));
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'subject' => 'Your roles have changed',
            'lines' => [$this->summary()],
        ];
    }

    /**
     * One sentence naming the actor.
     *
     * Falls back to a neutral wording when the change was made by a system job or
     * an API without an actor — "your roles were changed" is still true, and a
     * notification that names nobody reads as though nobody did it.
     */
    private function summary(): string
    {
        $by = $this->causer !== null ? ' by '.$this->causer->name : '';

        return sprintf('Your roles%s were changed.', $by);
    }
}