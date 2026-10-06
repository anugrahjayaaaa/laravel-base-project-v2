<?php

namespace App\Notifications;

use App\Models\NotificationChannel;
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
     * @param  int     $expireMinutes  Lifetime of the verification link, stated
     *                                in the mail so it matches what the
     *                                signature actually enforces.
     */
    public function __construct(
        private readonly string $tempPassword,
        private readonly string $username,
        private readonly string $verificationUrl,
        private readonly int $expireMinutes,
    ) {
    }

    /**
     * Deliver on the channels the admin has enabled.
     *
     * @return array<string>
     */
    public function via(User $notifiable): array
    {
        // From the admin's global switches rather than a hardcoded list, so the
        // channels page controls something that is actually read.
        return NotificationChannel::for();
    }

    /**
     * Build the welcome email with temp password and verification link.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage())
            ->markdown('vendor.notifications.user-created', [
                'username' => $this->username,
                'tempPassword' => $this->tempPassword,
                'url' => $this->verificationUrl,
                'expireMinutes' => $this->expireMinutes,
            ]);
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
            'subject' => 'An account was created for you',
            'lines' => [
                'Your temporary password is '.e($this->tempPassword),
                'Sign in and change it at your first opportunity.',
            ],
        ];
    }
}
