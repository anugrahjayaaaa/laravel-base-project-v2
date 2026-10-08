<?php

namespace App\Notifications;

use App\Support\NotificationChannel;
use App\Models\User;
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
        //
        // Essential: this link is the only thing that activates the account. Mail
        // off would leave a registered user permanently unable to finish signing
        // up. See `NotificationChannel::for()`.
        return NotificationChannel::for(essential: true);
    }

    /**
     * Build the verification email for a self-registered account.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Verify your email to activate your account')
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
            // The action, not the noun. "Verify your email address" describes the
            // mail; "Verify your email to activate your account" describes the one
            // thing the reader has to do, which is what the notification is for.
            'subject' => 'Verify your email to activate your account',
            'lines' => [
                // The reader of an inbox row is already signed in, and the inbox
                // has no link in it — so the copy has to say where the link is.
                // The old line ("Click the verification link sent to your email")
                // was addressed to someone who has no way to tell which surface
                // they are reading.
                'Open the verification link we emailed you.',
                'The link expires in '.$this->expireMinutes.' minutes.',
            ],
        ];
    }
}
