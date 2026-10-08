<?php

namespace App\Notifications;

use App\Support\NotificationChannel;
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
            'subject' => 'Verify your email address',
            'lines' => [
                'Welcome to the platform! Click the verification link sent to your email to activate your account.',
                'Verification link expires in '.$this->expireMinutes.' minutes.',
            ],
        ];
    }
}
