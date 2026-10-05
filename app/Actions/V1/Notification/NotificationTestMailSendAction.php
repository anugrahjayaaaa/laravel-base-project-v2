<?php

namespace App\Actions\V1\Notification;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Send one message through the configured transport, as a delivery probe.
 *
 * ## Why this exists rather than "save and hope"
 *
 * An SMTP configuration that is wrong fails on the next real send — a password
 * reset, a verification mail, an invoice — at the worst possible moment, for
 * somebody who cannot fix it. This sends one message so the operator learns the
 * transport is broken while they are still on the page holding the form.
 *
 * ## Why it never throws at the caller
 *
 * Every way this can fail is a transport failure: bad credentials, a refused
 * connection, a TLS mismatch, a relay that rejects the sender. All of them
 * arrive as an exception from the mailer. Left uncaught they become a 500 with a
 * stack trace, which tells an administrator nothing about which of the four it
 * was. Caught here, they become a sentence on the page plus a log line with the
 * exception, and the form is still there to correct.
 */
class NotificationTestMailSendAction
{
    /**
     * Send the test message, reporting failure instead of raising it.
     *
     * @param  string  $email   Validated destination address.
     * @param  User|null $causer Who to attribute the audit record to.
     * @return array{ok: bool, message: string}
     */
    public function run(string $email, ?User $causer = null): array
    {
        try {
            // `Mail::raw`, not a Notification: there is no notification class for
            // "is the transport working", and building one would make the probe
            // depend on the delivery system it is meant to test.
            //
            // The transport is resolved at send time from the runtime config, so
            // a transport saved on the previous request is the one this uses —
            // that is what makes the probe meaningful immediately after a save.
            Mail::raw(
                'This is a test message from '.config('app.name').'. '
                    .'Your mail transport is configured correctly.',
                fn ($message) => $message
                    ->to($email)
                    ->subject('Mail transport test')
            );

            $this->audit($causer, 'test_mail.sent', $email);

            return [
                'ok' => true,
                'message' => 'Test mail sent to '.$email.'.',
            ];
        } catch (Throwable $e) {
            // Logged with the address and the driver: the two facts an operator
            // needs and the exception message never carries on its own. The
            // credential is not here and must not be added — this line is a log
            // file that does not require `notifications.view` to read.
            Log::error('Test mail delivery failed', [
                'email' => $email,
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'exception' => $e->getMessage(),
            ]);

            // Recorded as a failure, not as nothing: an attempted send that did
            // not arrive is the fact worth keeping. `test_mail.failed` rather
            // than a successful `test_mail.sent` — a record claiming delivery
            // that never happened is the one thing an audit must not contain.
            $this->audit($causer, 'test_mail.failed', $email, ['error' => $e->getMessage()]);

            return [
                'ok' => false,
                // Deliberately not the exception message: it can contain the host,
                // the rejected username, or the server's own banner. The operator
                // gets the log; the page gets the actionable half.
                'message' => 'Could not send the test mail. Check the host, port, encryption and credentials, then try again.',
            ];
        }
    }

    /**
     * Record the attempt against the settings row that configures the transport.
     *
     * `SystemSetting` rather than a mail-specific subject because the transport
     * IS a system setting, and one audit entry point means a row written here is
     * shaped like every other settings row — same causer, same source, same
     * derived context.
     *
     * Best-effort: an audit failure must not turn a delivered message into an
     * error the operator has to interpret, nor a transport probe into a 500.
     */
    private function audit(?User $causer, string $event, string $email, array $properties = []): void
    {
        if ($causer === null) {
            return;
        }

        try {
            SystemSetting::query()->firstOrFail()->audit($event, $causer, [
                'recipient' => $email,
                ...$properties,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not write the test-mail audit record', [
                'event' => $event,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
