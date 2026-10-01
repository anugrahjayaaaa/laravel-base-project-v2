<?php

namespace App\Http\Requests\V1\User;

use App\Models\User;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new email address for the change-email flow.
 */
class EmailChangeRequest extends BaseFormRequest
{
    /**
     * Your own account, or someone who can edit users.
     *
     * This endpoint is self-service — the profile page posts to it — so it must
     * not simply require a permission, or nobody could ever change their own
     * email. But it takes {user} from the URL, and it used to authorize nobody:
     * `return true`, with a docblock claiming "route,token-based authorization"
     * that did not exist. Any authenticated account could therefore write
     * `pending_email` on ANOTHER user's record, and because the change completes
     * by clicking the emailed link, that is an account takeover rather than a
     * nuisance.
     *
     * Scoped to the two callers that actually exist: yourself, or an admin who
     * holds users.update.
     */
    public function authorize(): bool
    {
        $target = $this->route('user');

        if (! $target instanceof User) {
            return false;
        }

        $actor = $this->user();

        if ($actor === null) {
            return false;
        }

        return $target->is($actor) || $actor->can('users.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user'))
            ],
        ];
    }
}
