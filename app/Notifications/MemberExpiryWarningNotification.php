<?php

namespace App\Notifications;

use App\Models\Member;
use App\Models\User;
use App\Support\NotificationChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * A membership period is about to end (Phase 18, P18-C2).
 *
 * One message per MEMBER, not per subscription — the batch is resolved by
 * `SendMemberExpiryNotificationJob`, which collapses a member's live periods to
 * the earliest expiry, so two stacked subscriptions never mail twice.
 *
 * Not `$essential` mail: a member with a live period can log in and read the
 * inbox, so the global channel switches apply as they do to every ordinary
 * notification.
 */
class MemberExpiryWarningNotification extends Notification implements ShouldQueue
{
    /**
     * @see AccountStateChangedNotification::$afterCommit for why this is true here —
     *      including why it is declared and not composed from `Illuminate\Bus\Queueable`.
     *
     * @var bool
     */
    public $afterCommit = true;

    public function __construct(
        private readonly Member $member,
        private readonly Carbon $expiresAt,
    ) {
    }

    /**
     * @return array<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannel::for();
    }

    public function toMail(User $notifiable): MailMessage
    {
        // The subject already carries who and when; the body says what it means
        // and what to do — restating the date here is the defect
        // `NotificationCopyTest` was written against.
        return (new MailMessage())
            ->subject($this->subject())
            ->line('Your tier benefits stay active until the end date.')
            ->line('Contact staff to renew before it passes.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'subject' => $this->subject(),
            'lines' => [
                'Your tier benefits stay active until the end date.',
                'Contact staff to renew before it passes.',
            ],
        ];
    }

    /**
     * The bell shows subjects only, so the member number and the date are the
     * whole message — a bare "Membership expiring" identifies nobody.
     */
    private function subject(): string
    {
        return sprintf(
            'Membership %s expires on %s',
            $this->member->member_number,
            $this->expiresAt->format('F j, Y')
        );
    }
}
