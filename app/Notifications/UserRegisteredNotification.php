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
 * ## Reaches `users.create` holders only
 *
 * The account itself is not a recipient. Whoever it is, the account already
 * received `RegisterNotification` or `UserCreatedNotification` — the mail that
 * carries the verification link and, on the admin path, the temporary password.
 * A second, status-only "Registration received" copy beside that one repeated
 * the same promise without adding a fact, and on the admin path it claimed a
 * registration that never happened, so it was removed.
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
        $message = (new MailMessage())->subject($this->subject());

        foreach ($this->lines() as $line) {
            $message->line($line);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'subject' => $this->subject(),
            'lines' => $this->lines(),
        ];
    }

    /**
     * The actor belongs in the subject: an administrator's inbox is a list, and
     * a subject that does not identify itself is unreadable in the bell, which
     * shows subjects only.
     */
    private function subject(): string
    {
        return $this->causer !== null
            ? sprintf('%s registered a new account', $this->causer->name)
            : sprintf('New account registered: %s', $this->user->username);
    }

    /**
     * Sentences rather than a field list. "Username: jane" reads as a column
     * header in a table cell and as nothing at all to a screen reader, which
     * reads the row as prose — and these rows are read in an admin's list,
     * where the two details matter less than the sentence that says what to
     * do with them.
     *
     * @return array<string>
     */
    private function lines(): array
    {
        return [
            sprintf('The username is %s.', $this->user->username),
            sprintf('The account email is %s.', $this->user->email),
            'Review the account from the Users page.',
        ];
    }
}
