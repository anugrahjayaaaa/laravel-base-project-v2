<?php

namespace App\Http\Requests\V1\Feature;

use App\Http\Requests\BaseFormRequest;
use App\Support\FeatureCatalog;
use Illuminate\Validation\Rule;

/**
 * Validates a bulk flag action submitted by the features index bulk bar.
 *
 * ## Why this does not use `AuthorizesBulkAction`
 *
 * The trait maps `action -> "<prefix>.<suffix>"`, so `delete` needs
 * `roles.delete` and `delete_feature` would need `features.delete_feature` — a
 * permission that does not exist. The catalogue has exactly two:
 * `features.view` and `features.manage`.
 *
 * That is the right shape for flags and not a gap. Turning a module off is not
 * a lesser or a distinct privilege from turning one on — both rewrite the same
 * store row — so one `features.manage` gate covers both, and minting
 * `features.enable_feature` / `features.disable_feature` would create two
 * permissions nobody can hold separately without meaning anything.
 *
 * So the action name is validated here and the authorization is the single
 * `features.manage` check below. The trait stays the users/roles mechanism,
 * where actions genuinely do need different permissions.
 */
class BulkFeatureRequest extends BaseFormRequest
{
    /**
     * The bulk actions this request accepts.
     *
     * Both are one permission, so the map is a list rather than the trait's
     * action => suffix table.
     *
     * @var array<int, string>
     */
    public const ACTIONS = ['enable_feature', 'disable_feature'];

    /**
     * One gate for both directions.
     *
     * Checked here as well as on the route, so stripping the route middleware
     * does not turn this into an unauthenticated write.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('features.manage') ?? false;
    }

    /**
     * The state this request is asking for.
     *
     * Named rather than derived from the action string at the call site, so the
     * controller never has to know that `enable_feature` happens to mean true.
     */
    public function enabled(): bool
    {
        return $this->string('action')->value() === 'enable_feature';
    }

    /**
     * The flags this request names, de-duplicated and in the order given.
     *
     * De-duplicated because a crafted POST can repeat a slug, and the action
     * writes a row per entry — the second write would read `from` as the state
     * the first just set, producing an audit row claiming a flag changed to
     * what it already was.
     *
     * @return array<int, string>
     */
    public function slugs(): array
    {
        return array_values(array_unique($this->input('features', [])));
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'features' => ['required', 'array', 'min:1'],
            // Membership, not existence: the catalogue is config, so an
            // undeclared slug is refused by name. FeatureBulkToggleAction
            // re-checks with FeatureCatalog::has() and 404s, because rules()
            // only runs if authorize() passed.
            'features.*' => ['required', 'string', Rule::in(FeatureCatalog::slugs())],
        ];
    }
}
