<?php

namespace App\Notifications;

use App\Support\NotificationChannel;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An account's state changed at an administrator's hands — locked, unlocked,
 * activated, deactivated.
 *
 * ## One class, not four
 *
 * The four events differ in a word and a sentence. Four classes would be four
 * files, four `toArray()` bodies that must agree with the inbox view's contract,
 * and four places to forget the `database` channel. One class carrying the event
 * name keeps the shape in one place and the mapping in
 * `NotificationAudience::ADMINISTRATIVE_EVENTS`, which is where the audience
 * for each event already lives.
 *
 * ## It goes to TWO audiences, on purpose
 *
 * The user it is about, and the administrators who could have done it. The first
 * is the personal half of the Target Audience Rule — an account being locked is
 * news about YOUR account. The second is the administrative half — `users.lock`
 * holders are the people who can undo it, so they are the people who need to
 * know it happened.
 *
 * Sending only to the subject would be the interesting failure: an admin locks
 * an account by mistake, the user never learns why they cannot log in, and
 * nobody who can fix it was told.
 *
 * ## Why the causer is named but not addressed
 *
 * The actor's name goes in the body because "an administrator locked your
 * account" is more actionable than "your account was locked", and their email is
 * deliberately NOT included: this notification is delivered to every holder of
 * `users.lock`, and mailing each of them the actor's address turns a permission
 * into a directory.
 */
class AccountStateChangedNotification extends Notification implements ShouldQueue
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
     * How each event reads to the person it happened to.
     *
     * Keyed by the same event names `NotificationAudience` uses, so a caller
     * passes one string and both the audience and the wording are derived from
     * it. An event name that is not here has no wording, and
     * `NotificationAudience` will send it to nobody anyway.
     *
     * @var array<string, string>
     */
    private const PERSONAL_SUBJECTS = [
        'user.locked' => 'Your account has been locked',
        'user.unlocked' => 'Your account has been unlocked',
        'user.deactivated' => 'Your account has been deactivated',
        'user.activated' => 'Your account has been activated',
    ];

    private const ADMIN_SUBJECTS = [
        'user.locked' => 'Account was locked',
        'user.unlocked' => 'Account was unlocked',
        'user.deactivated' => 'Account was deactivated',
        'user.activated' => 'Account was activated',
    ];

    /**
     * @param  User      $subject  The account that changed.
     * @param  User|null $causer   The administrator who changed it, when known.
     * @param  string    $event    One of the keys in self::PERSONAL_SUBJECTS or ADMIN_SUBJECTS.
     */
    public function __construct(
        private readonly User $subject,
        private readonly ?User $causer,
        private readonly string $event,
    ) {
    }

    /**
     * Deliver on the channels the admin has enabled.
     *
     * @return array<string>
     */
    public function via(User $notifiable): array
    {
        // Essential: a locked or deactivated account cannot open the inbox that
        // would otherwise carry this — `account.state` refuses the login first.
        // Withholding the mail would leave the user unable to learn why they are
        // locked out. See `NotificationChannel::for()`.
        return NotificationChannel::for(essential: true);
    }

    /**
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail(User $notifiable): MailMessage
    {
        $isPersonal = $this->isSelf($notifiable);
        $message = new MailMessage();

        $message->subject(
            $isPersonal
                ? self::PERSONAL_SUBJECTS[$this->event] ?? 'Your account has changed'
                : self::ADMIN_SUBJECTS[$this->event] ?? 'Account status changed'
        )
            ->line($this->explanation($isPersonal))
            ->line('If you believe this is a mistake, contact an administrator.');

        return $message;
    }

    /**
     * The in-app representation, stored verbatim in `notifications.data`.
     *
     * `subject` + `lines` is the contract with `pages/notifications/inbox`. A
     * class storing different keys renders there as a bare "Notification"
     * placeholder.
     *
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        $isPersonal = $this->isSelf($notifiable);

        return [
            'subject' => $isPersonal
                ? self::PERSONAL_SUBJECTS[$this->event] ?? 'Your account has changed'
                : (self::ADMIN_SUBJECTS[$this->event] ?? 'Account status changed'),
            'lines' => [$this->explanation($isPersonal)],
        ];
    }

    /**
     * One sentence naming the actor and the account.
     *
     * Reads differently for the two audiences: an administrator reading it knows
     * the account already, while the account holder needs to know whose action
     * it was. The subject's own name is included because the notification also
     * reaches administrators who did not perform it.
     */
    private function explanation(bool $isPersonal = true): string
    {
        $by = $this->causer !== null
            ? ' by '.$this->causer->name
            : '';

        if ($isPersonal) {
            return sprintf('Your account was %s.%s', $this->pastTense(), $by);
        }

        return sprintf(
            "The account '%s' was %s.%s",
            $this->subject->username,
            $this->pastTense(),
            $by
        );
    }

    /**
     * Is this recipient the account itself?
     */
    private function isSelf(User $notifiable): bool
    {
        return $notifiable->getKey() === $this->subject->getKey();
    }

    /**
     * The event as a past-tense verb phrase.
     *
     * Derived from the event name rather than carried as a second string: two
     * strings describing one event can disagree, and the disagreement renders as
     * "The account was locked" on a notification about an unlock.
     */
    private function pastTense(): string
    {
        return match ($this->event) {
            'user.locked' => 'locked',
            'user.unlocked' => 'unlocked',
            'user.deactivated' => 'deactivated',
            'user.activated' => 'activated',
            default => 'changed',
        };
    }
}
