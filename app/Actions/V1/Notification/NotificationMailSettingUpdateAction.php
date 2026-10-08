<?php

namespace App\Actions\V1\Notification;

use App\Models\SystemSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;

/**
 * Persist the mail transport into `system_settings` and rebind the runtime config.
 *
 * Owns the write for both channels — web form and API — so a transport change
 * takes effect the same way regardless of who made it.
 *
 * ## Why `SystemSetting` and not a mail_settings table
 *
 * Every other operational knob in this app is a row here, with the cache bust and
 * the typed getters already built. A dedicated table would be a second settings
 * system beside the one Phase 8 just finished reconciling, holding six strings
 * that already have a home.
 *
 * ## Why the transaction
 *
 * `SystemSetting::set()` writes one row per key with no transaction of its own, so
 * a loop without one can stop half way — a failure on the fifth key leaves the
 * host saved and the from-address not, with nothing to roll back and an audit
 * record claiming the save succeeded. `SystemSettingsUpdateAction` hit exactly
 * this with 40 keys; with 8 the odds are lower and the failure is no less
 * confusing.
 */
class NotificationMailSettingUpdateAction
{
    public function __construct(
        private readonly NotificationAdminEventAction $notifyAction,
    ) {
    }

    /**
     * Normalize and persist the transport payload.
     *
     * ## The two payloads that reach here
     *
     * The web form posts every field, where an unchecked checkbox is simply
     * absent. The API client sends only the keys it means to change, where a
     * missing key must SURVIVE the call. `SystemSettingsUpdateAction` resolves
     * that with a `$partial` flag and a backfill; the same split applies here,
     * because the password is the field where getting it wrong disarms the
     * transport — a partial update that omitted it would clear a working
     * credential.
     *
     * @param  array<string, mixed>  $data
     * @param  bool                   $partial  True when absent means "unchanged"
     * @param  User|null              $causer   Who to attribute the audit record to
     */
    public function run(array $data, bool $partial = false, ?User $causer = null): void
    {
        $current = SystemSetting::getAll();

        // A partial payload only writes the keys it named. For the web form the
        // key is always present, so this is a no-op there and the values are the
        // submitted ones — which is what makes one code path serve both.
        $value = function (string $key, mixed $fallback = '') use ($data, $partial, $current): mixed {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }

            return $partial ? ($current[$key] ?? $fallback) : $fallback;
        };

        $updates = [
            'mail_mailer' => (string) $value('mail_mailer', config('mail.default', 'log')),
            'mail_host' => (string) $value('mail_host', config('mail.mailers.smtp.host', '')),
            'mail_port' => (string) $value('mail_port', config('mail.mailers.smtp.port', 2525)),
            'mail_username' => (string) $value('mail_username', ''),
            'mail_from_address' => (string) $value('mail_from_address', ''),
            'mail_from_name' => (string) $value('mail_from_name', ''),

            // `none` is not a transport scheme — `config/mail.php` has no
            // `encryption` key and Symfony's smtp transport reads `scheme`,
            // which understands smtp/tls/ssl and null. Storing the literal
            // string `none` would be a row nothing reads, and the transport
            // would fall back to plaintext while the form claims a choice was
            // made. Empty binds as null: the plaintext relay the operator meant.
            //
            // See UpdateMailSettingsRequest for the full divergence.
            'mail_encryption' => $this->normalizeEncryption($value('mail_encryption')),

            // NOT in the list above, and deliberately: the password is written
            // only when a new one was actually submitted. The field renders blank
            // when a credential is stored, so a form saved without retyping it
            // must leave the row alone rather than write an empty string and
            // disarm authentication on the next send.
        ];

        $password = $data['mail_password'] ?? null;

        if ($password !== null && $password !== '') {
            // `encrypt()` before the row, never after: a plaintext SMTP
            // credential in `system_settings` is readable by anyone who can query
            // that table, which includes every holder of `notifications.view`.
            // Decryption happens in `AppServiceProvider::bindMailConfig()`.
            $updates['mail_password'] = encrypt($password);
        }

        DB::transaction(function () use ($updates, $data, $causer): void {
            foreach ($updates as $key => $value) {
                SystemSetting::set($key, $value);
            }

            if ($causer !== null) {
                // Inside the transaction, so the record and the values it
                // describes commit or roll back together. A row claiming a save
                // the database then discarded is worse than no row.
                SystemSetting::query()->firstOrFail()->audit('mail_setting.updated', $causer, [
                    // The key list, never the values: this record lands in an
                    // audit table that `notifications.view` does not govern, and
                    // an encrypted password still does not belong in it.
                    'keys' => array_keys($updates),
                    'password_changed' => array_key_exists('mail_password', $updates),
                ]);
            }
        });

        // Rebind now the save is committed, rather than waiting for the next
        // boot — otherwise the admin who saved sees the old transport until a
        // restart, which is exactly the "field looks live and changes nothing"
        // failure this module exists to end.
        //
        // After the transaction, not inside: binding config from values a
        // rollback discards leaves the process enforcing a transport the
        // database never accepted.
        //
        // Cache busting needs no second call: `SystemSetting::set()` already
        // registers `DB::afterCommit(fn () => static::bustCache())`.
        AppServiceProvider::bindMailConfig();

        // Every `notifications.manage` holder — another operator may need to know
        // the transport moved underneath them. Never the values: this notification
        // goes into `notifications.data` and renders in an inbox, and an SMTP
        // host plus a password read back is a credential in a list.
        $this->notifyAction->configurationChanged('mail_setting.changed', null, $causer);
    }

    /**
     * The submitted encryption value as a stored `scheme`.
     *
     * `none` becomes the empty string, which `bindMailConfig()` binds as `null`.
     * Every other value passes through — the request has already restricted it
     * to the set the transport understands, and re-checking here would be a
     * second list to keep in step with the first.
     */
    private function normalizeEncryption(mixed $value): string
    {
        return $value === 'none' ? '' : (string) $value;
    }
}
