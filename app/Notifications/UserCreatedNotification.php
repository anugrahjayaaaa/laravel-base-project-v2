<?php

namespace App\Notifications;

use App\Support\NotificationChannel;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends welcome email with temp password and verification link.
 */
class UserCreatedNotification extends Notification implements ShouldQueue
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
     * Create the notification with temp credentials and verification URL.
     *
     * @param  string     $tempPassword
     * @param  string     $username
     * @param  string     $verificationUrl
     * @param  int        $expireMinutes  Lifetime of the verification link, stated
     *                                    in the mail so it matches what the
     *                                    signature actually enforces.
     * @param  User|null  $causer         Who created the account, when known.
     */
    public function __construct(
        private readonly string $tempPassword,
        private readonly string $username,
        private readonly string $verificationUrl,
        private readonly int $expireMinutes,
        private readonly ?User $causer = null,
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
        // Essential: the temporary password travels in this mail and nowhere
        // else. Mail off would create an account nobody, including the
        // administrator who created it, can sign in to. See
        // `NotificationChannel::for()`.
        return NotificationChannel::for(essential: true);
    }

    /**
     * Build the welcome email with temp password and verification link.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your account has been created')
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
        $by = $this->causer !== null ? ' by '.$this->causer->name : '';

        return [
            'subject' => 'Your account has been created',
            'lines' => [
                sprintf('An account was created for you%s.', $by),
                'Please sign in and change your password at your first opportunity.',
            ],
        ];
    }
}
