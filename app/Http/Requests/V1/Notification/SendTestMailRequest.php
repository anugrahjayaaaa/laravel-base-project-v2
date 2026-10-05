<?php

namespace App\Http\Requests\V1\Notification;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates the send-test-mail payload.
 *
 * Separate from `UpdateMailSettingsRequest` because it carries its own
 * permission: mailing an address a user typed is an abuse vector, and a request
 * that inherited `notifications.manage` would let anyone who may reconfigure the
 * transport also mail arbitrary recipients. That is why `notifications.send_test`
 * is a third permission rather than a subset of manage.
 */
class SendTestMailRequest extends BaseFormRequest
{
    /**
     * The send-test card requires its own permission.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('notifications.send_test') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // `email:rfc` rather than the default `email` because the value is
            // passed straight to the transport as a recipient, and the stricter
            // rule is the one that keeps a crafted header out of the envelope.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }
}
