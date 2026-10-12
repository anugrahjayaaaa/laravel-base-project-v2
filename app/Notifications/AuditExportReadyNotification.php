<?php

namespace App\Notifications;

use App\Models\AuditExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An audit export finished generating and is ready to download (Phase 10, P10-E5).
 *
 * ## It goes to the requester, not to an audience
 *
 * Every other notification in this module routes through
 * `NotificationAudience::ADMINISTRATIVE_EVENTS`, which exists because an
 * administrative event concerns everybody who can undo it. An export concerns one
 * person and nobody else: it is the file they asked for. Putting an export event
 * in that map would hand the contents of somebody's extract to every operator
 * holding `audit.view`, which is precisely the boundary `user_id` on the row
 * exists to keep.
 *
 * ## Fixed channels, and why they do not read the switches
 *
 * `database` and `mail`, deliberately, instead of `NotificationChannel::for()`.
 * The other notifications are ambient — an operator reads them if they happen to
 * open the bell — and the global switches exist to keep an inbox quiet. This one
 * is the only completion signal the feature has: there is no list of exports to
 * come back to, so a notification swallowed by a switch leaves a requester who
 * pressed a button and heard nothing, which reads as a broken button rather than
 * as a preference. They asked for a file; it is owed to them.
 *
 * ## The link is a route, not a path
 *
 * The mail carries the download URL, never the disk path. A path in an inbox
 * would be a private-storage location leaked into a second channel, and it would
 * keep working in the reader's head long after the sweep unlinks the file.
 */
class AuditExportReadyNotification extends Notification implements ShouldQueue
{
    /**
     * Delivery follows the outermost commit.
     *
     * The framework default is null, which means "enqueue immediately" — and
     * every queue connection here runs `after_commit => false`. The export row is
     * written before this is dispatched, so the window is narrow, but a rollback
     * that took the row with it would still send a link to a file no row claims.
     *
     * @var bool
     */
    public $afterCommit = true;

    public function __construct(
        private readonly AuditExport $export,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->export->row_count;

        $message = (new MailMessage())
            ->subject('Your audit export is ready')
            ->line(sprintf(
                'The audit export you requested is ready: %s row%s.',
                number_format((int) $count),
                $count === 1 ? '' : 's',
            ))
            ->action('Download the CSV', route('activity-logs.exports.download', $this->export))
            ->line(sprintf(
                'The link stops working on %s, after which the file is deleted.',
                $this->export->expires_at->format('Y-m-d H:i'),
            ));

        return $message;
    }

    /**
     * The inbox payload.
     *
     * `subject` and `lines` are the contract the bell dropdown reads
     * (`pages/notifications`), so this matches every other notification in the
     * module rather than inventing a shape the inbox has to special-case.
     *
     * `url` and `action_label` are the OPTIONAL second half of that contract: a
     * notification that leaves something for the reader to DO says so, and the
     * inbox renders it as a button. Without them the file exists, the notice
     * arrives, and nothing on screen leads to the file — which is exactly what
     * Group E shipped the first time.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $count = (int) $this->export->row_count;

        return [
            'subject' => 'Audit export ready',
            'lines' => [
                sprintf(
                    'The export you requested is ready: %s row%s.',
                    number_format($count),
                    $count === 1 ? '' : 's',
                ),
                'Download it from the Activity Logs page before it expires on '
                    .$this->export->expires_at->format('Y-m-d H:i').'.',
            ],
            'action_label' => 'Download CSV',
            // A PATH, not an absolute URL: `data` is a JSON column its writer
            // controls and this value reaches an `href`, so the inbox can accept
            // it with a same-origin check that a full URL would defeat.
            'url' => route('activity-logs.exports.download', $this->export, absolute: false),
        ];
    }
}
