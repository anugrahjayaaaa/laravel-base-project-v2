<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\NotificationChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sends email verification for pending email change.
 */
class ChangeEmailVerificationNotification extends Notification implements ShouldQueue
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
     * Create the notification with pending email and verification token.
     *
     * @param  string  $pendingEmail
     * @param  string  $token
     */
    public function __construct(
        private readonly string $pendingEmail,
        private readonly string $token,
    ) {
    }

    /**
     * Deliver on the channels the admin has enabled.
     *
     * @return array<string>
     */
    public function via($notifiable): array
    {
        // From the admin's global switches rather than a hardcoded list, so the
        // channels page controls something that is actually read.
        return NotificationChannel::for();
    }

    /**
     * Build the mail message for email change verification.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable): MailMessage
    {
        $verifyUrl = URL::temporarySignedRoute(
            'email.verify-change',
            now()->addHours(1),
            ['user' => $notifiable->id, 'token' => $this->token],
        );

        return (new MailMessage())
            ->subject('Confirm your new email address')
            ->line('You requested to change your email address.')
            ->line("New email: {$this->pendingEmail}")
            ->action('Verify Email Change', $verifyUrl)
            ->line('This link expires in 24 hours. If you did not request this, ignore this email.');
    }

    /**
     * The in-app representation, stored verbatim in `notifications.data`.
     *
     * Required the moment `database` is a channel — without it Laravel throws
     * rather than writing an empty row. The shape (`subject` + `lines`) is what
     * `pages/notifications/inbox` reads, so the two are a contract: a class that
     * stores different keys renders as a bare "Notification" placeholder rather
     * than an undefined-variable fatal on someone's inbox.
     *
     * `lines` is escaped here, not in the view: this string is persisted and
     * re-rendered later, so escaping at write time is the only place the value
     * can be trusted.
     *
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'subject' => 'Confirm your new email address',
            'lines' => [
                "Verify your request to change email to {$this->pendingEmail}.",
                'If you did not request this change, please ignore this notification.',
            ],
        ];
    }
}
