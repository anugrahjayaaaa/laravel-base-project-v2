<?php

namespace App\Http\Controllers\Web\V1;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Notifications & mail management — web stub (Phase 9 Group A).
 *
 * Group A is the UI layer only: the pages render, the routes reach them, and
 * nothing persists yet. The values below are placeholders, and they are
 * placeholders HERE rather than in Blade on purpose — a `SystemSetting::get()`
 * or a `Notification::` call left inside a view is invisible until the request
 * happens to run with a cold cache, which is why
 * `NotificationUiRenderTest` counts queries around the render.
 *
 * Group B replaces the config values with the real SMTP settings and adds
 * `update` / `sendTestMail`; Group C replaces the channel defaults with the
 * user's stored preferences. The route names and the `$updateUrl` /
 * `$sendTestUrl` contract stay as they are, so the views need no change.
 */
class NotificationController extends Controller
{
    /**
     * Mail configuration page: the SMTP form plus the send-test card.
     *
     * Booleans are cast in the controller, never in Blade — a stored `'false'`
     * string is truthy in PHP, so an uncast value would render as ON. Same rule
     * `SystemSettingController::index()` follows.
     */
    public function index(): View
    {
        // ponytail: literal placeholders until P9-B1/P9-B2 own SMTP storage
        // (SystemSetting rows vs a dedicated table is still an open decision in
        // docs/planning/phase-9-notifications-mail.md). Replace this array with
        // the read action's output, in this shape, and the view is unchanged.
        $settings = [
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => '587',
            'mail_encryption' => 'smtp',
            'mail_username' => 'no-reply@example.com',
            'mail_from_address' => 'no-reply@example.com',
            'mail_from_name' => 'Laravel Base Project',
        ];

        // The password is never part of `$settings` — an SMTP credential in view
        // data is a credential anyone who can read this page can exfiltrate.
        $hasPassword = true;

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
            // Group B lands the real endpoints; a URL built in the view would be
            // route logic in a view, and `route('notifications.*')` for a route
            // that does not exist yet makes the page unrenderable, not merely wrong.
            'updateUrl' => url('/notifications'),
            'sendTestUrl' => url('/notifications/test-mail'),
        ]);
    }

    /**
     * Notification channel preferences page: In-App, Mail, Database.
     *
     * `enabled` is a real bool per row. Group C replaces the defaults with the
     * user's stored preferences in the same shape.
     */
    public function channels(): View
    {
        $channels = [
            [
                'key' => 'in_app',
                'label' => 'In-App',
                'description' => 'Show the notification in the in-app inbox badge.',
                'enabled' => true,
            ],
            [
                'key' => 'mail',
                'label' => 'Mail',
                'description' => 'Send the notification to the account email address.',
                'enabled' => true,
            ],
            [
                'key' => 'database',
                'label' => 'Database',
                'description' => 'Persist the notification so it can be listed and marked as read.',
                'enabled' => false,
            ],
        ];

        return view('pages.notifications.channels', [
            'channels' => $channels,
            'updateUrl' => url('/notifications/channels'),
        ]);
    }
}
