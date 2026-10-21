<?php

namespace App\Http\Requests\V1\Feature;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * One flag spent through either channel: `POST /features/{feature}/toggle` on
 * web, `POST /api/v1/features/{feature}/toggle` on the API.
 *
 * The payload is one boolean. It lives here rather than inline because the
 * project's rule is that controllers read `validated()` and nothing else — an
 * inline `$request->validate()` is invisible to the generated document and to
 * `grep '\$request->validate('`, which is how this rule is audited.
 *
 * The flag slug is NOT validated here: the route parameter is a plain string and
 * `FeatureToggleAction` resolves it through `FeatureCatalog`, answering 404 for
 * an undeclared name on both channels.
 */
class ToggleFeatureRequest extends BaseFormRequest
{
    /**
     * Checked here as well as on the route and in the controller, so stripping
     * the route middleware does not turn this into an unauthenticated write.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('features.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean', Rule::in(['0', '1', '0.0', '1.0', 0, 1, false, true])],
        ];
    }
}
