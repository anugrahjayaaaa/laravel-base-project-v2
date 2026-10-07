<?php

namespace App\Notifications;

use App\Support\NotificationChannel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * This application's configuration changed at an administrator's hands — a
 * feature flag flipped, a system setting rewritten, the mail transport
 * reconfigured, a delivery channel switched.
 *
 * ## Reaches every holder of the permission that made the change possible
 *
 * There is no single user affected by a configuration change, so there is no
 * "subject" to notify — the audience IS the answer, and it is the set of people
 * who can undo what was done. An operator flipping `users` off and nobody
 * telling the other operators is how two people spend an afternoon disagreeing
 * about whether the flag is on.
 *
 * ## Why one class rather than four
 *
 * The events differ in a label and a noun. Four classes would be four files whose
 * `toArray()` bodies must each agree with the inbox view's `subject` + `lines`
 * contract, and four places to forget the `database` channel. The wording is
 * derived from the event name here, and the audience from
 * `NotificationAudience::ADMINISTRATIVE_EVENTS` — one map, one class.
 *
 * ## It never carries the value that changed
 *
 * A settings notification that quotes the new SMTP host hands a reader holding
 * `notifications.view` nothing they could not already read, and one quoting a
 * password would be a credential in an inbox. The message says WHAT changed and
 * WHO changed it; the value stays where the change was made.
 */
class ConfigurationChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * How each event reads, keyed by the event names the audience map uses.
     *
     * @var array<string, string>
     */
    private const SUBJECTS = [
        'feature.changed' => 'A feature flag was changed',
        'setting.changed' => 'System settings were changed',
        'mail_setting.changed' => 'The mail transport was reconfigured',
        'channel.changed' => 'Notification channels were changed',
        'role.changed' => 'A role was changed',
        'role.deleted' => 'A role was deleted',
        'user.deleted' => 'An account was deleted',
    ];

    /**
     * @param  string       $event   One of the keys in self::SUBJECTS.
     * @param  string|null  $detail  What specifically changed — a flag slug, never
     *                              a value.
     * @param  User|null    $causer  Who changed it, when known.
     */
    public function __construct(
        private readonly string $event,
        private readonly ?string $detail = null,
        private readonly ?User $causer = null,
    ) {
    }

    /**
     * @return array<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannel::for();
    }

    /**
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail(User $notifiable): MailMessage
    {
        $message = new MailMessage;

        $message->subject(self::SUBJECTS[$this->event] ?? 'Configuration was changed')
            ->line($this->sentence());

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'subject' => self::SUBJECTS[$this->event] ?? 'Configuration was changed',
            'lines' => [$this->sentence()],
        ];
    }

    /**
     * The one sentence, in the same words for mail and for the inbox.
     *
     * Shared because a mail that says one thing and an inbox row that says
     * another makes the two look like two different events, and a reader who sees
     * both has no way to tell that they are the same one.
     */
    private function sentence(): string
    {
        $sentence = $this->detail !== null
            ? sprintf('%s: %s.', rtrim(self::SUBJECTS[$this->event] ?? 'Configuration changed', '.'), $this->detail)
            : (self::SUBJECTS[$this->event] ?? 'Configuration was changed').'.';

        return $this->causer !== null
            ? $sentence.' Changed by '.$this->causer->name.'.'
            : $sentence;
    }
}