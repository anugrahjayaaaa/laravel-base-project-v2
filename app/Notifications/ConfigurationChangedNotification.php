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
    /**
     * What changed, per event — the `<thing> <verb>` half of the subject.
     *
     * Active and past-participle rather than "was updated": this is an audit
     * record read in a list, and a passive subject spends its first four words on
     * grammar instead of on which setting moved.
     *
     * @var array<string, string>
     */
    private const SUBJECTS = [
        'feature.changed' => 'Feature flag changed',
        'setting.changed' => 'System settings changed',
        'mail_setting.changed' => 'Mail transport updated',
        'channel.changed' => 'Notification channels updated',
        'role.changed' => 'Role changed',
        'role.deleted' => 'Role deleted',
        'user.deleted' => 'User account deleted',
        'user.updated' => 'User profile updated',
    ];

    /**
     * Where the reader goes next, per event.
     *
     * Every one of these reaches an audience of operators who will want to look
     * at the thing that changed, and none of them can act from the notification
     * itself: the payload carries no link and the inbox has no controls. Saying
     * where it lives is the difference between a record and a notification.
     *
     * @var array<string, string>
     */
    private const NEXT_STEPS = [
        'feature.changed' => 'Review the flag on the Feature Flags page.',
        'setting.changed' => 'Review the values on the Settings page.',
        'mail_setting.changed' => 'Send a test mail if this change was not yours.',
        'channel.changed' => 'Review the switches on the Notification Channels page.',
        'role.changed' => 'Review the permissions on the Roles page.',
        'role.deleted' => 'Review the remaining roles on the Roles page.',
        'user.deleted' => 'The full record is in the activity log.',
        'user.updated' => 'Review the profile from the Users page.',
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
        $message->subject($this->subject());

        foreach ($this->lines() as $line) {
            $message->line($line);
        }

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
     * What changed, and which one.
     *
     * The target goes after a colon rather than inside the sentence, for two
     * reasons that both come from where this string is read: the bell dropdown
     * shows the subject and nothing else, so the subject has to identify itself,
     * and it truncates. Leading with the kind of thing keeps "Feature flag
     * changed" readable when "billing_v2" is the part that got cut.
     *
     * `setting.changed` is the one event that ignores its detail: that detail is a
     * count, and "System settings changed: 2 key(s)" says less than the subject
     * alone while costing the reader a moment to decode it.
     */
    private function subject(): string
    {
        $subject = self::SUBJECTS[$this->event] ?? 'Configuration changed';

        if ($this->detail === null || $this->event === 'setting.changed') {
            return $subject;
        }

        return $subject.': '.$this->detail;
    }

    /**
     * Who changed it, and where to look.
     *
     * The old copy opened with "Role was updated by Ana Silva." under a subject
     * that already read "Role was updated" — the notification spent its entire
     * body repeating its own title, which is a large part of why these rows read
     * as noise. What the subject cannot carry is the actor and the next step.
     */
    private function lines(): array
    {
        $lines = [
            // Never a bare nothing: a system job or an API call without an actor
            // still happened, and "changed by no one" reads as a bug in the app.
            $this->causer !== null
                ? sprintf('Changed by %s.', $this->causer->name)
                : 'Changed by another operator.',
        ];

        if ($this->event === 'setting.changed' && $this->detail !== null) {
            // The count the subject deliberately drops lands here instead, where
            // it is information rather than a subtitle.
            $lines[] = $this->detail.' saved.';
        }

        $next = self::NEXT_STEPS[$this->event] ?? null;

        if ($next !== null) {
            $lines[] = $next;
        }

        return $lines;
    }
}
