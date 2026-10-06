<?php

namespace App\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sends email verification for pending email change.
 */
class ChangeEmailVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->subject('Confirm your email change')
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
                'Confirm this address to finish moving your account.',
                'If you did not request the change, ignore this message.',
            ],
        ];
    }
}
