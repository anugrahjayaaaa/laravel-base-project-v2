<?php

namespace App\Notifications;

use App\Support\NotificationChannel;
use App\Models\User;
use Illuminate\Bus\Queueable;
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
    use Queueable;

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

        $message->subject(
            $isSelf
                ? 'Your account registration is complete'
                : sprintf('New registration: %s', $this->user->username)
        )
            ->line($isSelf
                ? sprintf("Your account '%s' has been successfully created.", $this->user->username)
                : sprintf("New user '%s' (%s) registered.", $this->user->username, $this->user->email));

        if ($this->causer !== null) {
            $message->line(sprintf('Registered by %s.', $this->causer->name));
        }

        if (! $isSelf) {
            $message->line('No credentials are included in this message.');
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        $isSelf = $this->isSelf($notifiable);
        $by = $this->causer !== null ? ' by '.$this->causer->name : '';

        if ($isSelf) {
            return [
                'subject' => 'Your account registration is complete',
                'lines' => [
                    sprintf("Your account '%s' has been successfully created.", $this->user->username),
                ],
            ];
        }

        return [
            'subject' => sprintf('New registration: %s', $this->user->username),
            'lines' => [
                sprintf("New user '%s' (%s) registered%s.", $this->user->username, $this->user->email, $by),
            ],
        ];
    }

    /**
     * Is this recipient the account itself?
     *
     * Two wordings rather than one neutral sentence: an administrator reading
     * "your account is ready" has to work out that it is not theirs, and a new
     * user reading "a new account was created" cannot tell whether it is about
     * them. Both misreadings are silent, so the wording branches instead.
     */
    private function isSelf(User $notifiable): bool
    {
        return $notifiable->getKey() === $this->user->getKey();
    }
}
