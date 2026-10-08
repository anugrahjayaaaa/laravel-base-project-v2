<?php

namespace App\Notifications;

use App\Support\NotificationChannel;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A new account exists and the administrators who can create accounts may want
 * to know about it.
 *
 * ## Why this is a separate class from `UserCreatedNotification`
 *
 * That one carries a TEMPORARY PASSWORD. Sending it to administrators would copy
 * a live credential into the inboxes and audit-adjacent surfaces of every holder
 * of `users.create`, and into `notifications.data` where the inbox renders it
 * back. This class carries the username, the address and who created it — the
 * facts an administrator acts on — and no credential at all.
 *
 * The two are never combined. That separation is the whole reason this file
 * exists rather than a parameter on the other one.
 *
 * ## Reaches every `users.create` holder, plus the account itself
 *
 * The new account gets the same message: learning that your account exists is not
 * privileged information, and a first login that says "you were created by
 * someone" is how a user learns their username was not their own idea.
 */
class UserRegisteredNotification extends Notification implements ShouldQueue
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

    public function __construct(
        private readonly User $user,
        private readonly ?User $causer = null,
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
        $isSelf = $this->isSelf($notifiable);
        $message = new MailMessage();
        $message->subject($this->subject($isSelf));

        foreach ($this->lines($isSelf) as $line) {
            $message->line($line);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        $isSelf = $this->isSelf($notifiable);

        return [
            'subject' => $this->subject($isSelf),
            'lines' => $this->lines($isSelf),
        ];
    }

    /**
     * One line, two very different questions.
     *
     * ## Why the two audiences need different sentences
     *
     * A self-registration produces TWO rows in one person's inbox: this one and
     * `RegisterNotification`. Before this, they read "Your account registration is
     * complete" beside "Verify your email to activate your account", which are not
     * two facts but two contradictory readings of one. So this row states a STATUS
     * ("waiting for verification") and the other carries the ACTION. A reader who
     * has just signed up now has a status and a next step, not two claims about
     * the same account.
     *
     * The administrator's copy answers a different question again — who registered
     * — and the actor belongs in the subject for the same reason it does
     * elsewhere: an administrator's inbox is a list, and a subject that does not
     * identify itself is unreadable in the bell, which shows subjects only.
     */
    private function subject(bool $isSelf): string
    {
        if ($isSelf) {
            return 'Registration received';
        }

        return $this->causer !== null
            ? sprintf('%s registered a new account', $this->causer->name)
            : sprintf('New account registered: %s', $this->user->username);
    }

    /**
     * What the reader needs to know next, which is different for each audience.
     *
     * The old copy ended every administrative copy with "No credentials are
     * included in this message." — a sentence about the email rather than about
     * anything the reader can do. It was reassurance addressed to nobody who
     * needed it: the person who creates accounts knows what the mail contains.
     */
    private function lines(bool $isSelf): array
    {
        if ($isSelf) {
            return [
                'Your account activates once you confirm the verification link.',
            ];
        }

        // Sentences rather than a field list. "Username: jane" reads as a column
        // header in a table cell and as nothing at all to a screen reader, which
        // reads the row as prose — and these rows are read in an admin's list,
        // where the two details matter less than the sentence that says what to
        // do with them.
        $lines = [
            sprintf('The username is %s.', $this->user->username),
            sprintf('The account email is %s.', $this->user->email),
            'Review the account from the Users page.',
        ];

        return $lines;
    }

    /**
     * Is this recipient the account that registered?
     */
    private function isSelf(User $notifiable): bool
    {
        return $notifiable->getKey() === $this->user->getKey();
    }
}
