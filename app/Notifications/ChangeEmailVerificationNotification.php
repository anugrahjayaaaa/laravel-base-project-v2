<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class ChangeEmailVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $pendingEmail,
        private readonly string $token,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

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