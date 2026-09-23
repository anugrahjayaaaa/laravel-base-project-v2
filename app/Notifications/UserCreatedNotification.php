<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends welcome email with temp password and verification link.
 */
class UserCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create the notification with temp credentials and verification URL.
     *
     * @param  string  $tempPassword
     * @param  string  $username
     * @param  string  $verificationUrl
     */
    public function __construct(
        private readonly string $tempPassword,
        private readonly string $username,
        private readonly string $verificationUrl,
    ) {}

    /**
     * Deliver via mail only.
     *
     * @return array<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the welcome email with temp password and verification link.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('vendor.notifications.user-created', [
                'username' => $this->username,
                'tempPassword' => $this->tempPassword,
                'url' => $this->verificationUrl,
            ]);
    }
}
