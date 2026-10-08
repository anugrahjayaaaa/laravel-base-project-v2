<?php

namespace App\Http\Requests\V1\Notification;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the mail transport update payload.
 *
 * ## Why encryption is validated against `scheme`, not `encryption`
 *
 * `config/mail.php` declares no `encryption` key on the smtp mailer — it has
 * `'scheme' => env('MAIL_SCHEME')` and nothing else. Laravel's `smtp` transport
 * reads that value and passes it to Symfony, which understands `smtp`, `tls` and
 * `ssl` (and null for a plaintext local relay).
 *
 * So the payload field is still called `mail_encryption` — that is what an
 * operator calls it, and renaming the form to `mail_scheme` would be a UI
 * technicality — but the allowed values are the ones the transport can actually
 * be given. `none` is the fourth option in the view's select, and it is the one
 * value with no scheme behind it: storing the string `none` would be a row
 * nothing reads, and the transport would fall back to plaintext anyway.
 *
 * It is mapped to an empty value on write (see `NotificationMailSettingUpdateAction`), which
 * binds as `null` — the plaintext relay an administrator means by "none".
 */
class UpdateMailSettingsRequest extends BaseFormRequest
{
    /**
     * The transport values Symfony's `smtp` transport understands.
     *
     * Shared with the action, which maps `none` to the empty stored value, so
     * the list the form offers and the list the write path understands cannot
     * drift into two vocabularies.
     *
     * @var array<int, string>
     */
    public const ENCRYPTION_VALUES = ['smtp', 'tls', 'ssl', 'none'];

    /**
     * Mail configuration is administrator-only.
     *
     * `.view` alone renders the page read-only; writing the transport is
     * `.manage`. The send-test permission is a separate request and a separate
     * route — mailing an arbitrary address is not a subset of configuring one.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('notifications.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mail_mailer' => [
                'required',
                'string',
                // The key must exist in the configured mailers, or the transport
                // is asked for one the app has no credentials for and every
                // send fails with an error naming a transport nobody configured.
                Rule::in(array_keys(config('mail.mailers') ?: ['smtp' => null])),
            ],
            'mail_host' => ['required', 'string', 'max:255'],
            // 1–65535 is the TCP port range. `integer` alone accepts `99999`, and
            // an out-of-range port fails at send time with a transport error
            // instead of at save time with a field-level message.
            'mail_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'mail_encryption' => ['required', 'string', Rule::in(self::ENCRYPTION_VALUES)],
            'mail_username' => ['nullable', 'string', 'max:255'],

            // Nullable, and empty means KEEP. The field renders blank when a
            // password is stored — the value is never sent back to the browser —
            // so a form submitted without retyping it would otherwise overwrite a
            // working credential with an empty string.
            //
            // `max:255` is generous rather than exact: an SMTP credential longer
            // than this is not a credential a mail server accepts, and an exact
            // bound would be a rule to maintain for no protection.
            'mail_password' => ['nullable', 'string', 'max:255'],

            'mail_from_address' => ['nullable', 'email:rfc', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
