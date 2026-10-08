<?php

namespace App\Support;

use App\Models\SystemSetting;

class NotificationChannel
{
    /**
     * The channels a notification goes out on.
     *
     * Read from the admin's global switches rather than hardcoded in each
     * `via()`, which is what makes the channels page a control instead of a
     * screen of switches.
     *
     * ## `$essential` — the switch does not apply to everything
     *
     * Mail is the ONLY delivery channel for four of this module's notifications,
     * and turning it off does not dim them — it breaks the flow they exist to
     * complete. A locked account cannot open the inbox (`account.state` refuses
     * the login that would show it), an unverifiable email address cannot be
     * activated, a pending email change cannot be confirmed, and an admin-created
     * account has no other way to learn its temporary password. A notification
     * delivered nowhere is not a quieter notification; it is an account nobody
     * can use and a support ticket.
     *
     * So `$essential` means "the mail channel is included whether or not the
     * switch says otherwise" — the one switch on this page that is not a hard
     * off, and the only honest thing to be when the alternative is a feature that
     * silently stops working.
     *
     * The in-app switch is NOT bypassed. An administrator who turns off in-app
     * delivery wants a quiet inbox, and none of these four flows depends on the
     * inbox existing — which is exactly why they depend on the mail.
     *
     * The line between essential and ordinary is not "is it important", it is
     * "does its absence make a flow uncompletable". Everything else —
     * administrative notices, role changes — has an inbox to arrive in and
     * follows the switches like any other notification.
     */
    public static function for(bool $essential = false): array
    {
        $channels = [];

        if ($essential || SystemSetting::getBool('notification_channel_mail', true)) {
            $channels[] = 'mail';
        }

        if (SystemSetting::getBool(
            'notification_channel_database',
            SystemSetting::getBool('notification_channel_in_app', false)
        )) {
            $channels[] = 'database';
        }

        return $channels;
    }
}
