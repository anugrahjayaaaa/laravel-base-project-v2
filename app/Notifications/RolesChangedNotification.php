<?php

namespace App\Notifications;

use App\Support\NotificationChannel;
use App\Models\User;
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
    /**
     * Delivery follows the OUTERMOST commit, not this method's return.
     *
     * The framework default is null, which means "enqueue immediately" — and
     * every queue connection here runs `after_commit => false`. Left null, a
     * worker can send before the row that produced this notification commits,
     * and a rollback still delivers: a working temporary password, a signed
     * verification link, for an account that does not exist.
     *
     * This is the property `Illuminate\Bus\Queueable` would have declared with
     * a null default, declared here instead because a trait cannot change a
     * default the class already composes. See
     * `docs/planning/phase-9-notifications-mail.md`, audit finding 2.
     *
     * @var bool
     */
    public $afterCommit = true;

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
        $isPersonal = $this->isSelf($notifiable);
        $message = new MailMessage();
        $message->subject($this->subject($isPersonal));

        foreach ($this->lines($isPersonal) as $line) {
            $message->line($line);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        $isPersonal = $this->isSelf($notifiable);

        return [
            'subject' => $this->subject($isPersonal),
            'lines' => $this->lines($isPersonal),
        ];
    }

    /**
     * What changed, and to whom.
     *
     * For the account holder: second person, present tense, no "has been".
     *
     * For an administrator the actor goes IN the subject, because an
     * administrator's inbox is a list and "Roles were updated" does not say whose
     * roles or who touched them. It also means the lines can spend themselves on
     * what actually moved instead of on the sentence the subject already said.
     */
    private function subject(bool $isPersonal): string
    {
        if ($isPersonal) {
            return 'Your account roles changed';
        }

        if ($this->causer !== null) {
            return sprintf('%s changed the roles of %s', $this->causer->name, $this->user->username);
        }

        // No actor: a system job or an API call. Still names the account, which is
        // the half that makes the row findable.
        return sprintf('Roles changed for %s', $this->user->username);
    }

    /**
     * Who did it, what moved, and what to do if it was a mistake.
     *
     * The old first line was `sprintf('Your roles%s were changed.', $by)` — which
     * produced "Your roles by Ana Silva were changed." That is not a style
     * complaint, it is a broken sentence, and it sat under a subject saying the
     * same thing again.
     *
     * Each fact appears exactly once: the actor in the subject where the subject
     * has room for it, the roles here, the next step last.
     */
    private function lines(bool $isPersonal): array
    {
        $lines = [];

        // The actor is in the subject when there is one, so the body names them
        // only when the subject could not. "Ana Silva changed the roles of jane"
        // over a line reading "Changed by Ana Silva." says one thing twice, which
        // is the defect this class was rewritten for.
        if ($this->causer === null) {
            $lines[] = 'Changed by another operator.';
        }

        // The roles themselves, one line each, with the verbs that distinguish
        // them. "Granted" and "revoked" were the old words; "Added" and "Removed"
        // match what the change actually did and what an operator calls it.
        if ($this->added !== []) {
            $lines[] = 'Added: '.implode(', ', $this->added).'.';
        }

        if ($this->removed !== []) {
            $lines[] = 'Removed: '.implode(', ', $this->removed).'.';
        }

        $lines[] = $isPersonal
            ? 'Contact an administrator if this was not expected.'
            : 'Review the assignment from the user\'s page if this was a mistake.';

        return $lines;
    }

    /**
     * Is this recipient the user whose access changed?
     */
    private function isSelf(User $notifiable): bool
    {
        return $notifiable->getKey() === $this->user->getKey();
    }
}
