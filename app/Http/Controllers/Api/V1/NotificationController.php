<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\V1\Notification\NotificationMailSettingUpdateAction;
use App\Actions\V1\Notification\NotificationChannelUpdateAction;
use App\Actions\V1\Notification\NotificationTestMailSendAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Notification\SendTestMailRequest;
use App\Http\Requests\V1\Notification\UpdateMailSettingsRequest;
use App\Http\Requests\V1\Notification\UpdateNotificationChannelsRequest;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

/**
 * Notifications & mail management — API V1 (Phase 9 Group B).
 *
 * Shares every action with the web controller, so a transport change takes
 * effect the same way regardless of which channel made it. The API stays
 * independently usable without the AdminLTE layer.
 *
 * ## Nothing here returns the SMTP password
 *
 * `index` hands over the same shape the web page reads: the credential itself is
 * absent, and a boolean says whether one is stored. Returning the raw column
 * would put an encrypted credential in a JSON body every client with
 * `notifications.view` can read and re-read; returning nothing at all would leave
 * a client unable to tell "no password" from "password withheld".
 */
class NotificationController extends Controller
{
    /**
     * The transport, without the credential.
     *
     * @var array<int, string>
     */
    private const CONFIG_FALLBACKS = [
        'mail_mailer' => 'mail.default',
        'mail_host' => 'mail.mailers.smtp.host',
        'mail_port' => 'mail.mailers.smtp.port',
        'mail_encryption' => 'mail.mailers.smtp.scheme',
        'mail_username' => 'mail.mailers.smtp.username',
        'mail_from_address' => 'mail.from.address',
        'mail_from_name' => 'mail.from.name',
    ];

    /**
     * Read the mail transport and the channel switches.
     */
    public function index(): JsonResponse
    {
        $stored = SystemSetting::getAll();

        $settings = [];

        foreach (self::CONFIG_FALLBACKS as $key => $configPath) {
            $row = (string) ($stored[$key] ?? '');

            $settings[$key] = $row !== '' ? $row : (string) (config($configPath) ?? '');
        }

        $channels = [];

        foreach (['in_app', 'mail', 'database'] as $channel) {
            // Booleans, not the stored strings: `'false'` is truthy in PHP, and
            // a JSON consumer has no idea `SystemSetting` stores strings.
            $channels[$channel] = SystemSetting::getBool('notification_channel_'.$channel);
        }

        return $this->respond('', 200, [
            'settings' => $settings,
            // Whether a credential exists, never the credential.
            'has_password' => ($stored['mail_password'] ?? '') !== '',
            'channels' => $channels,
            'mailers' => array_keys(config('mail.mailers') ?: []),
        ]);
    }

    /**
     * Update the mail transport.
     *
     * Partial on purpose: an API client sends the keys it wants to change, and a
     * key it left out must survive the call. The password is the field where
     * getting this wrong disarms the transport — a partial update that omitted it
     * would clear a working credential.
     *
     * The audit record is written by the action — see `NotificationMailSettingUpdateAction`.
     */
    public function update(
        UpdateMailSettingsRequest $request,
        NotificationMailSettingUpdateAction $action
    ): JsonResponse {
        $action->run($request->validated(), partial: true, causer: $request->user());

        return $this->respond('Mail settings updated.', 200);
    }

    /**
     * Update the channel switches.
     */
    public function updateChannels(
        UpdateNotificationChannelsRequest $request,
        NotificationChannelUpdateAction $action
    ): JsonResponse {
        $action->run($request->validated(), causer: $request->user());

        return $this->respond('Notification channels updated.', 200);
    }

    /**
     * Send one message through the configured transport.
     *
     * A transport failure is a 502, not a 500: the request was understood and
     * authorized, and the thing that failed is downstream of this application —
     * the mail server. The body carries the same actionable sentence the web page
     * shows; the exception itself went to the log.
     */
    public function sendTestMail(
        SendTestMailRequest $request,
        NotificationTestMailSendAction $action
    ): JsonResponse {
        $result = $action->run($request->validated('email'), causer: $request->user());

        if (! $result['ok']) {
            return $this->respond($result['message'], 502);
        }

        return $this->respond($result['message'], 200);
    }
}
