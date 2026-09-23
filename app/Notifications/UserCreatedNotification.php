<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tempPassword,
        private readonly string $username,
        private readonly string $verificationUrl,
    ) {}

    public function via(User $notifiable): array
    {
        return ['mail'];
    }

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
