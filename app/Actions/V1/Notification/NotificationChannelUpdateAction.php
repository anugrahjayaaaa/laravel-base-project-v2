<?php

namespace App\Actions\V1\Notification;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Persist the notification delivery channel switches.
 *
 * Same shape as `NotificationMailSettingUpdateAction` — one transaction, audit inside it —
 * because it is the same kind of fact: an operator changed how this application
 * reaches its users.
 *
 * ## Why the keys are global
 *
 * Notification delivery is per-recipient by definition. A registration event
 * goes to administrators, a password expiry to one account, an order or a
 * payment to the affected user. There is no per-user channel choice to record, so
 * there is no preference table: `notification_channel_*` rows are what an
 * administrator sets once and the delivery code reads.
 *
 * (Owner decision, 2026-10-05 — see the phase doc's Group D.)
 */
class NotificationChannelUpdateAction
{
    public function __construct(
        private readonly NotificationAdminEventAction $notifyAction,
    ) {
    }

    /**
     * The switches this action owns.
     *
     * A key the form renders but this list omits is dropped silently — the save
     * reports success and the value never changes. That is the failure
     * `SystemSettingsUpdateAction` documents for its own whitelist, and it is why
     * the two lists are adjacent constants rather than one guess.
     *
     * @var array<int, string>
     */
    private const CHANNELS = ['in_app', 'mail', 'database'];

    /**
     * Normalize and persist the channel payload.
     *
     * @param  array<string, mixed>  $data
     * @param  User|null             $causer  Who to attribute the audit record to
     */
    public function run(array $data, ?User $causer = null): void
    {
        $updates = [];

        foreach (self::CHANNELS as $channel) {
            // `array_key_exists` rather than `??`: an unticked switch arrives as
            // `'0'` thanks to its hidden companion, and `??` would treat that as
            // present-and-falsy correctly — but a genuinely absent key would also
            // become false, quietly turning a channel off. The web form posts
            // every switch, so requiring the key keeps an incomplete payload from
            // reading as "disable the rest".
            $updates['notification_channel_'.$channel] = array_key_exists($channel, $data)
                ? ((bool) $data[$channel] ? 'true' : 'false')
                : (string) (SystemSetting::getBool('notification_channel_'.$channel) ? 'true' : 'false');
        }

        DB::transaction(function () use ($updates, $causer): void {
            foreach ($updates as $key => $value) {
                SystemSetting::set($key, $value);
            }

            if ($causer !== null) {
                // Inside the transaction: a record claiming a save the database
                // then discarded is worse than no record.
                SystemSetting::query()->firstOrFail()->audit('notification_channels.updated', $causer, [
                    'channels' => $updates,
                ]);
            }
        });

        // Every `notifications.manage` holder, with the resulting states. These
        // are the switches themselves, not credentials — quoting them is what
        // makes the notification useful.
        $this->notifyAction->configurationChanged('channel.changed', null, $causer);
    }
}
