<?php

namespace App\Notifications;

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
     * Create the notification with the account, pending email and token.
     *
     * The account id is carried because the recipient is the pending ADDRESS,
     * not the User: an on-demand notifiable has no `id` to build the signed
     * route from.
     *
     * @param  int     $userId
     * @param  string  $pendingEmail
     * @param  string  $token
     */
    public function __construct(
        private readonly int $userId,
        private readonly string $pendingEmail,
        private readonly string $token,
    ) {
    }

    /**
     * Mail only, and only to the address being confirmed.
     *
     * This is the one notification whose whole purpose is to prove control of
     * an address the account does not own yet, so it cannot go to the current
     * inbox. There is no User notifiable to write an in-app row against.
     *
     * @return array<string>
     */
    public function via($notifiable): array
    {
        // Essential: this link is the only thing that completes the email change.
        // Mail off would strand the request — the account keeps its old address
        // and the pending one is never confirmed. That is why the channel is
        // hardcoded here instead of read from the admin switches.
        return ['mail'];
    }

    /**
     * Build the mail message for email change verification.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable): MailMessage
    {
        // 24 hours, matching the stored token's expiry and the sentence below.
        // The signature used to last one hour while the row and the copy said
        // 24, so the link died nine tenths of the way through its own promise.
        $verifyUrl = URL::temporarySignedRoute(
            'email.verify-change',
            now()->addHours(24),
            ['user' => $this->userId, 'token' => $this->token],
        );

        return (new MailMessage())
            ->subject('Confirm your new email address')
            ->line('You requested to change your email address.')
            ->line("New email: {$this->pendingEmail}")
            ->action('Verify Email Change', $verifyUrl)
            ->line('This link expires in 24 hours. If you did not request this, ignore this email.');
    }
}
