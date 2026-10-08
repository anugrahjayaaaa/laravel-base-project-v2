<?php

namespace App\Http\Requests\V1\Notification;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates the notification channel switch payload.
 *
 * Its own request because the fields are booleans and the rule that matters is
 * the one about UNCHECKED switches: the hidden `value="0"` companion means an
 * unticked channel arrives as `'0'` rather than being absent, so `boolean`
 * accepts both states. Dropping the companion would make an unticked channel
 * simply missing, and a `nullable|boolean` relaxation here would then leave the
 * stored value untouched — a switch that could be turned off but never back off.
 *
 * (Design system convention §4d — hidden `value="0"` + checkbox `value="1"`.)
 */
class UpdateNotificationChannelsRequest extends BaseFormRequest
{
    /**
     * The channel switches are administrator-owned, same as the transport.
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
            'in_app' => ['boolean'],
            'mail' => ['boolean'],
            'database' => ['boolean'],
        ];
    }
}
