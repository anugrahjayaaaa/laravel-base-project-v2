<?php

namespace App\Models;

use App\Support\SystemRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NotificationChannel
{
    public static function for(): array
    {
        $channels = [];

        if (SystemSetting::getBool('notification_channel_mail', true)) {
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
