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
     * Present tense and no "has been": a notification says what is true now, and
     * "your account is locked" is both shorter and truer than "your account has
     * been locked" — the past perfect implies an end that has not come.
     *
     * @var array<string, string>
     */
    private const PERSONAL_SUBJECTS = [
        'user.locked' => 'Your account is locked',
        'user.unlocked' => 'Your account is unlocked',
        'user.deactivated' => 'Your account is deactivated',
        'user.activated' => 'Your account is active',
    ];

    /**
     * The same event, for the administrators who did it.
     *
     * Names the account, because an administrator's inbox is a list of rows and
     * "Account was locked" tells them nothing about which one. The subject is
     * also all the bell dropdown ever shows — it has no body — so a subject that
     * does not identify its own subject is unreadable in the one place most
     * people will actually meet it.
     *
     * @var array<string, string>
     */
    private const ADMIN_SUBJECTS = [
        'user.locked' => 'Locked account: %s',
        'user.unlocked' => 'Unlocked account: %s',
        'user.deactivated' => 'Deactivated account: %s',
        'user.activated' => 'Reactivated account: %s',
    ];

    /**
     * What the reader can do about it, per event, per audience.
     *
     * The second half of a notification is not a restatement of the first: the
     * subject says what happened, and this says what it means for you. A reader
     * who is told only that an account was locked has to guess whether they are
     * the locked one and what to do about it.
     *
     * `null` means there is genuinely nothing to do, which is more honest than a
     * line that says so.
     *
     * @var array<string, array{0: string|null, 1: string|null}>
     */
    private const ACTIONS = [
        'user.locked' => [
            'Contact an administrator if this was not expected.',
            'You can unlock the account from the user\'s page.',
        ],
        'user.unlocked' => [
            'Contact an administrator if this was not expected.',
            null,
        ],
        'user.deactivated' => [
            'Contact an administrator if this was not expected.',
            'You can reactivate the account from the user\'s page.',
        ],
        'user.activated' => [
            null,
            null,
        ],
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
        $message->subject($this->subject($isPersonal));

        foreach ($this->lines($isPersonal) as $line) {
            $message->line($line);
        }

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
            'subject' => $this->subject($isPersonal),
            'lines' => $this->lines($isPersonal),
        ];
    }

    /**
     * One line: what happened, and to which account.
     *
     * Built in one place because a mail subject and an inbox row that word the
     * same event differently are two events to the reader, and nothing on either
     * surface tells them they are the same one.
     */
    private function subject(bool $isPersonal): string
    {
        if ($isPersonal) {
            return self::PERSONAL_SUBJECTS[$this->event] ?? 'Your account status changed';
        }

        return sprintf(
            self::ADMIN_SUBJECTS[$this->event] ?? 'Account changed: %s',
            $this->subject->username
        );
    }

    /**
     * Who did it, and what the reader can do about it.
     *
     * Not a second version of the subject. The old copy opened with "Your account
     * was locked" under a subject that already said "Your account has been
     * locked" — the same fact twice, which made a notification that had
     * something important to say look empty. What the subject cannot carry is the
     * actor and the consequence, and those are what the lines are for.
     */
    private function lines(bool $isPersonal): array
    {
        // Never a bare nothing. A system job or an API call without an actor still
        // happened, and "changed by no one" reads as a bug in the app.
        $lines[] = $this->causer !== null
            ? sprintf('Done by %s.', $this->causer->name)
            : 'Changed by another operator.';

        [$personalAction, $adminAction] = self::ACTIONS[$this->event] ?? [null, null];
        $action = $isPersonal ? $personalAction : $adminAction;

        if ($action !== null) {
            $lines[] = $action;
        }

        return $lines;
    }

    /**
     * Is this recipient the account itself?
     *
     * The two copies below are not variations on one message — they answer
     * different questions for different readers, and a recipient who is both the
     * account and an operator gets the personal one, because "your account is
     * locked" is the fact they cannot get anywhere else.
     */
    private function isSelf(User $notifiable): bool
    {
        return $notifiable->getKey() === $this->subject->getKey();
    }
}
