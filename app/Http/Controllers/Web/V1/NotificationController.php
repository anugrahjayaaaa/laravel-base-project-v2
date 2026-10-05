<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Notification\NotificationMailSettingUpdateAction;
use App\Actions\V1\Notification\NotificationChannelUpdateAction;
use App\Actions\V1\Notification\NotificationTestMailSendAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Notification\SendTestMailRequest;
use App\Http\Requests\V1\Notification\UpdateMailSettingsRequest;
use App\Http\Requests\V1\Notification\UpdateNotificationChannelsRequest;
use App\Models\SystemSetting;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Notifications & mail management — web controller (Phase 9 Groups A and B).
 *
 * Read and write the mail transport, the channel switches, and probe the
 * transport. Thin by design: validation belongs to the Form Requests,
 * persistence and the config rebinding to the actions, and this class assembles
 * view data and formats the response.
 *
 * Every value the views read is resolved here rather than in Blade — a
 * `SystemSetting::get()` left inside a view is invisible until a request happens
 * to run with a cold cache, which is why `NotificationUiRenderTest` counts
 * queries around the render.
 */
class NotificationController extends Controller
{
    /**
     * The transport key → `config/mail.php` path each one falls back to.
     *
     * Read as config so an admin sees the transport the app is actually
     * enforcing before its first save, rather than an empty form that reads as
     * "not configured".
     *
     * @var array<string, string>
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
     * The channel switches, in display order.
     *
     * Global and admin-owned rather than per user: notification delivery is
     * per-recipient by definition (a registration event reaches admins, a
     * password expiry reaches one account), so a per-user preference screen
     * would describe a choice nobody needs to make.
     *
     * @var array<string, array{label: string, description: string}>
     */
    private const CHANNELS = [
        'in_app' => [
            'label' => 'In-App',
            'description' => 'Show the notification in the in-app inbox badge.',
        ],
        'mail' => [
            'label' => 'Mail',
            'description' => 'Send the notification to the account email address.',
        ],
        'database' => [
            'label' => 'Database',
            'description' => 'Persist the notification so it can be listed and marked as read.',
        ],
    ];

    /**
     * Mail configuration page: the SMTP form plus the send-test card.
     *
     * Booleans are cast in the controller, never in Blade — a stored `'false'`
     * string is truthy in PHP, so an uncast value renders as ON. Same rule
     * `SystemSettingController::index()` follows.
     */
    public function index(): View
    {
        $stored = SystemSetting::getAll();

        $settings = [];

        foreach (self::CONFIG_FALLBACKS as $key => $configPath) {
            // A stored row wins; an empty row falls back to the config. `$stored`
            // first because a seeder writes the config value anyway, and this way
            // a key nobody seeded still renders what is in force.
            $row = (string) ($stored[$key] ?? '');

            $settings[$key] = $row !== '' ? $row : (string) (config($configPath) ?? '');
        }

        // The password is never part of `$settings` — an SMTP credential in view
        // data is a credential anyone who can read this page can exfiltrate. The
        // field renders blank and reports stored-or-not through `$hasPassword`.
        $hasPassword = AppServiceProvider::hasMailPassword();

        // From config, not from a query: this is the mailer catalogue the app
        // itself is configured with, so the select cannot offer a transport that
        // does not exist or hide one that does. The config key is the identifier
        // stored in `mail_mailer`, so the key is both the value and the label —
        // these entries carry no display name of their own.
        $mailers = array_combine(
            array_keys(config('mail.mailers') ?: []),
            array_map('ucfirst', array_keys(config('mail.mailers') ?: []))
        );

        return view('pages.notifications.index', [
            'settings' => $settings,
            'mailers' => $mailers ?: ['smtp' => 'Smtp'],
            'hasPassword' => $hasPassword,
            'updateUrl' => route('notifications.update'),
            'sendTestUrl' => route('notifications.test-mail'),
        ]);
    }

    /**
     * Notification channel switches: In-App, Mail, Database.
     *
     * `enabled` is a real bool per row — cast from the stored string here, never
     * in Blade.
     */
    public function channels(): View
    {
        $channels = [];

        foreach (self::CHANNELS as $key => $meta) {
            $channels[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'enabled' => SystemSetting::getBool('notification_channel_'.$key),
            ];
        }

        return view('pages.notifications.channels', [
            'channels' => $channels,
            'updateUrl' => route('notifications.channels.update'),
        ]);
    }

    /**
     * Persist the mail transport.
     *
     * The audit record is written by the action, not here — see
     * `NotificationMailSettingUpdateAction`.
     */
    public function update(
        UpdateMailSettingsRequest $request,
        NotificationMailSettingUpdateAction $action
    ): RedirectResponse {
        $action->run($request->validated(), causer: $request->user());

        return back()->with('status', 'Mail settings updated.');
    }

    /**
     * Persist the channel switches.
     *
     * The web form posts every switch, and the hidden `value="0"` companion means
     * an unticked one arrives as `'0'` rather than being absent — so the
     * action's non-partial mode is correct here.
     */
    public function updateChannels(
        UpdateNotificationChannelsRequest $request,
        NotificationChannelUpdateAction $action
    ): RedirectResponse {
        $action->run($request->validated(), causer: $request->user());

        return back()->with('status', 'Notification channels updated.');
    }

    /**
     * Send one message through the configured transport.
     *
     * Failures are reported, not raised: the action returns `{ok, message}` and
     * this decides how that reads. A transport error is an ordinary outcome of
     * testing a transport, so it returns to the page with the form intact rather
     * than becoming a 500 with a stack trace.
     */
    public function sendTestMail(
        SendTestMailRequest $request,
        NotificationTestMailSendAction $action
    ): RedirectResponse {
        $result = $action->run($request->validated('email'), causer: $request->user());

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }
}
