<?php

namespace App\Notifications;

use App\Support\NotificationChannel;
use App\Models\User;
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
     * How each event reads, keyed by the event names the audience map uses.
     *
     * @var array<string, string>
     */
    private const SUBJECTS = [
        'feature.changed' => 'Feature flag was changed',
        'setting.changed' => 'System settings were updated',
        'mail_setting.changed' => 'Mail transport configuration was updated',
        'channel.changed' => 'Notification channel was modified',
        'role.changed' => 'Role was updated',
        'role.deleted' => 'Role was deleted',
        'user.deleted' => 'User account was deleted',
        'user.updated' => 'User profile was updated',
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
        $message = new MailMessage();

        $message->subject($this->subject())
            ->line($this->summary());

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'subject' => $this->subject(),
            'lines' => $this->lines(),
        ];
    }

    /**
     * The notification subject, made specific to the event where a detail exists.
     *
     * Falls back to the generic subject for events whose detail is a count or
     * null — substituting "System setting 2 key(s)" would read worse, not better.
     */
    private function subject(): string
    {
        $event = $this->event;

        if ($this->detail !== null) {
            return match ($event) {
                'feature.changed' => sprintf("Feature flag '%s' was changed", $this->detail),
                'role.deleted' => sprintf("Role '%s' was deleted", $this->detail),
                'user.deleted' => sprintf("User account '%s' was deleted", $this->detail),
                default => self::SUBJECTS[$event] ?? 'Configuration was changed',
            };
        }

        return self::SUBJECTS[$event] ?? 'Configuration was changed';
    }

    /**
     * The action line(s) shared by mail and inbox.
     *
     * Line 1 names the action and the actor. Line 2 (when a detail is
     * available) identifies the specific target that changed, so an
     * administrator reading two notifications can tell them apart.
     */
    private function lines(): array
    {
        $by = $this->causer !== null
            ? ' by '.$this->causer->name
            : '';

        $lines = [(self::SUBJECTS[$this->event] ?? 'Configuration changed').$by.'.'];

        if ($this->detail !== null) {
            $lines[] = sprintf('Target: %s', $this->detail);
        }

        return $lines;
    }

    /**
     * One sentence for the mail body, matching the inbox lines.
     */
    private function summary(): string
    {
        return implode(' ', $this->lines());
    }
}
