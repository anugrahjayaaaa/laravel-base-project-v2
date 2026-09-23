<?php

namespace App\Notifications;

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
    ) {}

    /**
     * Deliver via mail only.
     *
     * @return array<string>
     */
    public function via($notifiable): array
    {
        return ['mail'];
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

        return (new MailMessage)
            ->subject('Confirm your email change')
            ->line('You requested to change your email address.')
            ->line("New email: {$this->pendingEmail}")
            ->action('Verify Email Change', $verifyUrl)
            ->line('This link expires in 24 hours. If you did not request this, ignore this email.');
    }
}