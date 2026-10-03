<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when someone self-registers, asking them to verify their email address.
 *
 * Carries no password: they chose it themselves moments earlier, so repeating
 * it in an email only widens the places a plaintext password has to survive.
 */
class RegisterNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $username
     * @param  string  $verificationUrl
     * @param  int     $expireMinutes  Lifetime of the verification link, stated
     *                                in the mail so it matches what the
     *                                signature actually enforces.
     */
    public function __construct(
        private readonly string $username,
        private readonly string $verificationUrl,
        private readonly int $expireMinutes,
    ) {
    }

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
     * Build the verification email for a self-registered account.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Verify your email address')
            ->markdown('vendor.notifications.register', [
                'username' => $this->username,
                'url' => $this->verificationUrl,
                'expireMinutes' => $this->expireMinutes,
            ]);
    }
}
